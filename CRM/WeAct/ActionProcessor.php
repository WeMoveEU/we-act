<?php

class CRM_WeAct_ActionProcessor {

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function process(CRM_WeAct_Action $action) {
    $campaign = $this->getOrCreateCampaign($action);
    $contact = $this->getOrCreateContact($action, $campaign['id']);
    if ($action->actionType == 'donate') {
    }
  }

  /**
   * Fetches from cache, DB or create and cache the CiviCRM campaign
   * to which the given action is to be associated
   */
  public function getOrCreateCampaign(CRM_WeAct_Action $action) {
    $key = "WeAct:ActionPage:extid:{$action->actionPageId}";
    $entry = Civi::cache()->get($key);
    if (!$entry) {
      $get_params = ['sequential' => 1, 'external_identifier' => $action->actionPageId];
      $get_result = civicrm_api3('Campaign', 'get', $get_params);
      if ($get_result['count'] == 1) {
        $entry = $get_result['values'][0];
      }
      else {
        $create_result = civicrm_api3('Campaign', 'create', [
          'sequential' => 1,
          'name' => $action->actionPageName,
          'title' => $action->actionPageName,
          'description' => $action->actionPageName,
          'external_identifier' => $this->externalIdentifier($action->externalSystem, $action->actionPageId),
          'campaign_type_id' => $this->campaignType($action->actionType),
          'start_date' => date('Y-m-d H:i:s'),
          $this->settings->campaignLanguageField => $action->language,
        ]);
        $entry = $create_result['values'][0];
      }
      Civi::cache()->set($key, $entry);
    }
    return $entry;
  }

  public function getOrCreateContact(CRM_WeAct_Action $action, $campaign_id) {
    if ($action->contact->isAnonymous()) {
      return $this->settings->anonymousId;
    }

    $requireConsent = FALSE;
    $contact_ids = $action->contact->getMatchingIds();
    if (count($contact_ids) == 0) {
      $contact = $action->contact->create($action->language, $action->source());
    } else {
      //There shouldn't be more than one contact, but if does we'll simply use the "oldest" one
      //TODO send an alert to someone that a merge is required if more than one id
      $contact_id = min($contact_ids);
      $contact = $action->contact->getAndUpdate($contact_id);
    }

    //Membership was retrieved from a joined query to GroupContact for the members group
    if ($contact['api.GroupContact.get']['count'] == 0) {
      $consentParams = [
        'contact_id' => $contact['id'],
        'campaign_id' => $campaign_id,
        'utm_source' => $action->source['source'],
        'utm_medium' => $action->source['medium'],
        'utm_campaign' => $action->source['campaign'],
      ];
      civicrm_api3('Gidipirus', 'send_consent_request', $consentParams);
    }

    return $contact['id'];
  }

  public function externalIdentifier($system, $id) {
    return $id;
  }

  public function campaignType($actionType) {
    if ($actionType == 'donate') {
      return CRM_Core_PseudoConstant::getKey('CRM_Campaign_BAO_Campaign', 'campaign_type_id', 'Fundraising');
    }
    throw new Exception("Unsupported action type");
  }
}
