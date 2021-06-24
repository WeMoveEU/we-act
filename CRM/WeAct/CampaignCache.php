<?php

class CRM_WeAct_CampaignCache {

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
    if ($action->utm && substr($action->utm['source'], 0, 9) == 'civimail-') {
      $campaign = $this->getFromMailingSource($action->utm['source']);
    }

    if (@$action->locationId) {
      $campaign = $this->getOrCreateSpeakout($action->location, $action->locationId);
    }

    // If not, use the action page as an external identifier of the campaign
    if (!$campaign) {
      $campaign = $this->getExternalCampaign($action->externalSystem, $action->actionPageId);
      if (!$campaign) {
        $external_id = $this->externalIdentifier($action->externalSystem, $action->actionPageId);
        $create_result = civicrm_api3('Campaign', 'create', [
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

  protected function getFromMailingSource($source) {
    $key = "WeAct:MailingCampaign:{$source}";
    $entry = $this->cache->get($key);
    if (!$entry) {
      //Use civicrm_api instead of civicrm_api3 to avoid exception when entity not found
      $mailing_result = civicrm_api('Mailing', 'getsingle', ['version' => 3, 'id' => substr($source, 9, strlen($source))]);
      if (isset($mailing_result['id'])) {
        $campaign_result = civicrm_api('Campaign', 'getsingle', ['version' => 3, 'id' => $mailing_result['campaign_id']]);
        if (isset($campaign_result['id'])) {
          $entry = $campaign_result;
          $this->cache->set($key, $entry);
        }
      }
    }
    return $entry;
  }

  protected function getOrCreateSpeakout($speakout_url, $speakout_id) {
    $entry = $this->getExternalCampaign('speakout', $speakout_id);
    if (!$entry) {
      $urlments = parse_url($speakout_url);
      $speakout_domain = $urlments['host'];
      $this->createSpeakoutCampaign($speakout_domain, $speakout_id);
      $entry = $this->getExternalCampaign('speakout', $speakout_id);
    }
    return $entry;
  }

  protected function getExternalCampaign($external_system, $external_id) {
    $key = "WeAct:ActionPage:$external_system:$external_id";
    $campaign = $this->cache->get($key);
    if (!$campaign) {
      $external_identifier = $this->externalIdentifier($external_system, $external_id);
      $get_params = ['sequential' => 1, 'external_identifier' => $external_identifier];
      $get_result = civicrm_api3('Campaign', 'get', $get_params);
      if ($get_result['count'] == 1) {
        $campaign = $get_result['values'][0];
      }
      $this->cache->set($key, $campaign);
    }
    return $campaign;
  }

  public function campaignType($actionType, $categories = []) {
    $mapping = [
      'donate' => 'Fundraising',
      'sign' => 'Petitions',
      'Trial campaign' => 'Trial campaign',
    ];
    $types = CRM_Core_PseudoConstant::get('CRM_Campaign_BAO_Campaign', 'campaign_type_id');
    $type = array_search($mapping[$categories[0]->name ?? $actionType], $types);
    if (empty($type)) {
      throw new Exception("Unsupported action type");
    }
    return $type;
  }

  public function externalIdentifier($system, $id) {
    if ($system == 'houdini' || $system == 'speakout') {
      $external_id = $id;
    } else {
      $external_id = "{$system}_$id";
    }
    return $external_id;
  }

  protected function createSpeakoutCampaign($speakout_domain, $speakout_id) {
    $url = "https://$speakout_domain/api/v1/campaigns/$speakout_id";
    $user = CIVICRM_SPEAKOUT_USERS[$speakout_domain];
    $externalCampaign = json_decode($this->getRemoteContent($url, $user));

    $locale = $externalCampaign->locale;
    $slug = ($externalCampaign->slug != '' ? $externalCampaign->slug : 'speakout_'.$externalCampaign->id);
    $consentIds = array_keys(get_object_vars($externalCampaign->consents));
    if ($externalCampaign->thankyou_from_email) {
      $sender = "\"$externalCampaign->thankyou_from_name\" &lt;$externalCampaign->thankyou_from_email&gt;";
    } else {
      $sender = $this->settings->defaultSender;
    }

    $fields = $this->settings->customFields;

    $params = array(
      'sequential' => 1,
      'name' => $externalCampaign->internal_name,
      'title' => $externalCampaign->internal_name,
      'description' => $externalCampaign->name,
      'external_identifier' => $externalCampaign->id,
      'campaign_type_id' => $this->campaignType('sign', $externalCampaign->categories ?? []),
      'start_date' => date('Y-m-d H:i:s'),
      $fields['campaign_language'] => $locale,
      $fields['campaign_sender'] => $sender,
      $fields['campaign_url'] => "https://$speakout_domain/campaigns/$slug",
      $fields['campaign_slug'] => $slug,
      $fields['campaign_twitter_share'] => $externalCampaign->twitter_share_text,
      $fields['campaign_consent_ids'] => implode(',', $consentIds),
      $fields['campaign_confirm_subject'] => CRM_WeAct_Dictionary::getSubjectConfirm($locale),
      $fields['campaign_confirm_body'] => CRM_WeAct_Dictionary::getMessageNew($locale),
      $fields['campaign_postaction_subject'] => $externalCampaign->thankyou_subject,
      $fields['campaign_postaction_body'] => $externalCampaign->thankyou_body,
    );
    $create_result = civicrm_api3('Campaign', 'create', $params);

    // This is done in a separate step even if the parent campaign is defined,
    // to avoid circular-reference drama (getCampaignByExternalId may call this function)
    if ($externalCampaign->parent_campaign_id) {
      // Not the correct URL, but assuming that only the host part matters
      $parent = $this->getOrCreateSpeakout($url, $externalCampaign->parent_campaign_id);
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
}
