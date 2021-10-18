<?php

class CRM_WeAct_ActionProcessor {

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
    $this->campaignCache = new CRM_WeAct_CampaignCache(Civi::cache(), new \GuzzleHttp\Client());
  }

  public function process(CRM_WeAct_Action $action) {
    if ($action->actionType == 'donate') {
      $campaign = $this->campaignCache->getFromAction($action);
      $contact_id = $this->getOrCreateContact($action, $campaign['id']);
      $this->processDonation($action, $campaign['id'], $contact_id);
    }
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

    Civi::log()->debug("Checking for group membership - {$contact['api.GroupContact.get']['count']}");

    //Membership was retrieved from a joined query to GroupContact for the members group
    if ($contact['api.GroupContact.get']['count'] == 0) {
      Civi::log()->debug("Sending consent request to contact {$contact['id']}");
      $consentParams = [
        'contact_id' => $contact['id'],
        'campaign_id' => $campaign_id,
        'utm_source' => CRM_Utils_Array::value('source', $action->utm),
        'utm_medium' => CRM_Utils_Array::value('medium', $action->utm),
        'utm_campaign' => CRM_Utils_Array::value('campaign', $action->utm),
      ];
      civicrm_api3('Gidipirus', 'send_consent_request', $consentParams);
    }

    return $contact['id'];
  }

  public function processDonation($action, $campaign_id, $contact_id) {
    CRM_Core_Transaction::create(TRUE)->run(function(CRM_Core_Transaction $tx) use ($action, $campaign_id, $contact_id) {
      $donation = $action->details;
      $rcontrib_id = NULL;
      if ($donation->isRecurring()) {
        if (!$donation->findMatchingContribRecur()) {
          $donation->createContribRecur($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
        }
      }
      else if (!$donation->findMatchingContrib()) {
        $donation->createContrib($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
      }
    });
  }
}
