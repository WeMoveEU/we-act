<?php

class CRM_WeAct_CampaignCache {

  /**
   * @param $cache
   * @param $guzzleClient
   * @param string $actionType Derived from RabbitMQ message.action_type, [ petition | poll ]
   */
  public function __construct($cache, $guzzleClient) {
    $this->settings = CRM_WeAct_Settings::instance();
    $this->cache = $cache;
    $this->guzzleClient = $guzzleClient;
  }

  /**
   * Fetches from cache, DB or create and cache the CiviCRM campaign
   * to which the given action is to be associated
   */
  public function getFromAction(CRM_WeAct_Action $action) {
    $campaign = NULL;

    // If the action seems to come from a mailing (according to utm), use the campaign of the mailing
    if ($action->utm && array_key_exists('source', $action->utm) && substr($action->utm['source'], 0, 9) == 'civimail-') {
      $campaign = $this->getFromMailingSource($action->utm['source']);
    }

    // Location ID is set to speakout campaign in custom fields in paypal and
    // proca messages. So if we have it defined, ask Speakout for it!
    if (!$campaign && @$action->locationId) {
      // todo all calls to method getFromAction() work with default extenal system = speakout
      //  but should be $action->externalSystem
      $campaign = $this->getOrCreateSpeakout($action->location, $action->locationId, 'speakout');
    }

    // If not, use the action page as an external identifier of the campaign
    if (!$campaign) {

      $campaign = $this->getExternalCampaign($action->externalSystem, $action->actionPageId);
      if (!$campaign) {
        $external_id = $this->externalIdentifier($action->externalSystem, $action->actionPageId);
        civicrm_api3('Campaign', 'create', [
          'sequential' => 1,
          'name' => $action->actionPageName,
          'title' => $action->actionPageName,
          'description' => $action->actionPageName,
          'external_identifier' => $external_id,
          'campaign_type_id' => $this->campaignType($action->actionType),
          'start_date' => date('Y-m-d H:i:s'),
          $this->settings->customFields['campaign_language'] => $action->language,
        ]);
        $campaign = $this->getExternalCampaign($action->externalSystem, $action->actionPageId);
      }
    }
    return $campaign;
  }

  public function getCiviCampaign($campaign_id) {
    $key = "WeAct:Campaign:{$campaign_id}";
    $entry = $this->cache->get($key);
    if (!$entry) {
      $entry = civicrm_api3('Campaign', 'getsingle', ['id' => $campaign_id]);
      $this->setCiviCampaign($entry);
    }
    return $entry;
  }

  public function setCiviCampaign($campaign) {
    $key = "WeAct:Campaign:{$campaign['id']}";
    $this->cache->set($key, $campaign);
  }

  protected function getFromMailingSource($source) {
    $key = "WeAct:MailingCampaign:{$source}";
    $campaign_id = $this->cache->get($key);
    if ($campaign_id === NULL) {
      //Use civicrm_api instead of civicrm_api3 to avoid exception when entity not found
      $mailing_result = civicrm_api('Mailing', 'getsingle', ['version' => 3, 'id' => substr($source, 9, strlen($source))]);
      //If no campaign id is set, store 0 so that we hit the cache next time
      if (isset($mailing_result['id'])) {
        $campaign_id = $mailing_result['campaign_id'] ?? 0;
      } else {
        $campaign_id = 0;
      }
      $this->cache->set($key, $campaign_id);
    }

    if ($campaign_id) {
      return $this->getCiviCampaign($campaign_id);
    }
    return NULL;
  }

  /**
   * @param $speakout_url
   * @param $speakout_id
   * @param string $external_system 'speakout' for campains, 'speakout_survey' for surveys
   *
   * @return array|mixed|null
   */
  public function getOrCreateSpeakout($speakout_url, $speakout_id, string $external_system = 'speakout') {
    $entry = $this->getExternalCampaign($external_system, $speakout_id);
    if (!$entry) {
      $urlments = parse_url($speakout_url);
      $speakout_domain = $urlments['host'];
      $this->createSpeakoutCampaign($speakout_domain, $speakout_id, $external_system);
      $entry = $this->getExternalCampaign($external_system, $speakout_id);
    }
    return $entry;
  }

  protected function getExternalCampaign($external_system, $external_id) {
    $key = $this->keyForCache($external_system, $external_id);
    $campaign_id = $this->cache->get($key);
    if ($campaign_id === NULL) {
      $external_identifier = $this->externalIdentifier($external_system, $external_id);
      $get_params = ['sequential' => 1, 'external_identifier' => $external_identifier];
      $get_result = civicrm_api3('Campaign', 'get', $get_params);
      if ($get_result['count'] == 1) {
        $campaign = $get_result['values'][0];
        $this->cache->set($key, $campaign['id']);
      } else {
        $campaign = NULL;
      }
    } else {
      $campaign = $this->getCiviCampaign($campaign_id);
    }
    return $campaign;
  }

  public function campaignType($actionType, $categories = []) {
    $mapping = [
      'donate' => 'Fundraising',
      'sign' => 'Petitions',
      'Trial campaign' => 'Trial campaign',
      'poll' => 'Survey',
    ];
    $types = CRM_Core_PseudoConstant::get('CRM_Campaign_BAO_Campaign', 'campaign_type_id');
    $type = array_search($mapping[$categories[0]->name ?? $actionType], $types);
    if (empty($type)) {
      throw new Exception("Unsupported action type '$actionType'");
    }
    return $type;
  }

  /**
   * Speakout is the historical campaign provider and thus the default external system,
   * the external id pattern must be kept for backward compatibility.
   * The distinction between the 2 Speakout instances is based on the id value (lesser or greater than 10000).
   * Houdini ids have "cc_" prepended to them, so no need for further distinction.
   */
  public function externalIdentifier($system, $id) {
    if ($system == 'speakout_survey') {
      /**
       * Surveys from Speakout don't share the id sequence of Speakout campaigns,
       * so their external identifier needs to be prefixed in CiviCRM
       * because this mysql field has unique key.
       */
      return sprintf("%s_%s", $system, $id);
    }

    if ($system == 'houdini' || $system == 'speakout') {
      $external_id = $id;
    } else {
      $external_id = "{$system}_$id";
    }
    return $external_id;
  }

  protected function createSpeakoutCampaign($speakout_domain, $speakout_id, string $external_system = 'speakout') {
    $url = $this->prepareSpeakoutAPIUrl($speakout_domain, $speakout_id, $external_system);
    $user = CIVICRM_SPEAKOUT_USERS[$speakout_domain];
    $externalCampaign = json_decode($this->getRemoteContent($url, $user));

    $locale = $externalCampaign->locale;
    $slug = ($externalCampaign->slug != '' ? $externalCampaign->slug : 'speakout_'.$externalCampaign->id);
    if (isset($externalCampaign->consents)) {
      $consentIds = array_keys(get_object_vars($externalCampaign->consents));
    } else {
      $consentIds = [];
    }
    if ($externalCampaign->thankyou_from_email) {
      $sender = "\"$externalCampaign->thankyou_from_name\" &lt;$externalCampaign->thankyou_from_email&gt;";
    } else {
      $sender = $this->settings->defaultSender;
    }

    $fields = $this->settings->customFields;
    $externalIdentifier = $this->externalIdentifier($external_system, $speakout_id);
    $params = array(
      'sequential' => 1,
      'name' => $externalCampaign->internal_name,
      'title' => $externalCampaign->internal_name,
      'description' => $externalCampaign->name ?? $externalCampaign->title,
      'external_identifier' => $externalIdentifier,
      'campaign_type_id' => $this->prepareCampaignType($externalCampaign->categories ?? [], $external_system),
      'start_date' => date('Y-m-d H:i:s'),
      $fields['campaign_language'] => $locale,
      $fields['campaign_sender'] => $sender,
      $fields['campaign_url'] => $this->prepareSpeakoutCampaignUrl($speakout_domain, $slug, $external_system),
      $fields['campaign_slug'] => $slug,
      $fields['campaign_twitter_share'] => $externalCampaign->twitter_share_text,
      $fields['campaign_consent_ids'] => implode(',', $consentIds),
      $fields['campaign_confirm_subject'] => CRM_WeAct_Dictionary::getSubjectConfirm($locale),
      $fields['campaign_confirm_body'] => CRM_WeAct_Dictionary::getMessageNew($locale),
      $fields['campaign_postaction_subject'] => $externalCampaign->thankyou_subject,
      $fields['campaign_postaction_body'] => $externalCampaign->thankyou_body,
    );
    $create_result = civicrm_api3('Campaign', 'create', $params);

    //Surveys don't have a parent
    if ($external_system != 'speakout_survey') {
      // This is done in a separate step even if the parent campaign is defined,
      // to avoid circular-reference drama (getCampaignByExternalId may call this function)
      if ($externalCampaign->parent_campaign_id) {
        // Not the correct URL, but assuming that only the host part matters
        $parent = $this->getOrCreateSpeakout($url, $externalCampaign->parent_campaign_id, $external_system);
        $parent_params = [
          'id' => $create_result['values'][0]['id'],
          'parent_id'=> $parent['id'],
        ];
      } else {
        $parent_params = [
          'id' => $create_result['values'][0]['id'],
          'parent_id'=> $create_result['values'][0]['id'],
        ];
      }
      civicrm_api3('Campaign', 'create', $parent_params);
    }
  }

  private function getRemoteContent($url, $user = NULL) {
    $resp = $this->guzzleClient->request('GET', $url, ['auth' => [$user['email'], $user['password']]]);
    $data = $resp->getBody();
    $code = $resp->getStatusCode();
    if ($code == 200) {
      return $data;
    } elseif ($code = 404) {
      throw new Exception('Speakout campaign doesnt exist: ' . $url);
    } else {
      throw new Exception('Speakout campaign is unavailable' . $url);
    }
  }

  /* The one-liners below are only for the purpose of being overridable by a child class */

  protected function keyForCache($external_system, $external_id): string {
    if ($external_system == 'speakout_survey') {
      return sprintf("WeAct:ActionPage:Survey:%s:%s", $external_system, $external_id);
    }

    return sprintf("WeAct:ActionPage:Campaign:%s:%s", $external_system, $external_id);
  }

  protected function prepareSpeakoutAPIUrl(string $speakout_domain, string $speakout_id, string $external_system): string {
    if ($external_system == 'speakout_survey') {
      return sprintf("https://%s/api/v1/surveys/%s", $speakout_domain, $speakout_id);
    }

    return sprintf("https://%s/api/v1/campaigns/%s", $speakout_domain, $speakout_id);
  }

  protected function prepareSpeakoutCampaignUrl(string $speakout_domain, string $slug, string $external_system): string {
    if ($external_system == 'speakout_survey') {
      return sprintf("https://%s/surveys/%s", $speakout_domain, $slug);
    }

    return sprintf("https://%s/campaigns/%s", $speakout_domain, $slug);
  }

  protected function prepareCampaignType($categories = [], $external_system = 'speakout') {
    if ($external_system == 'speakout_survey') {
      return $this->campaignType('poll', $categories);
    }

    return $this->campaignType('sign', $categories);
  }
}
