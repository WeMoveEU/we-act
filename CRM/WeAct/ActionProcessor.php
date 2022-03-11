<?php

class CRM_WeAct_ActionProcessor {

  public function __construct($request_consents = TRUE) {
    $this->settings = CRM_WeAct_Settings::instance();
    $this->campaignCache = new CRM_WeAct_CampaignCache(Civi::cache(), new \GuzzleHttp\Client());
    $this->requestConsents = $request_consents;
  }

  public function process(CRM_WeAct_Action $action, $campaign_id=NULL) {
    if ($action->actionType != 'donate') {
      return ;
    }
    if (is_null($campaign_id)) {
      $campaign = $this->campaignCache->getFromAction($action);
      $campaign_id = $campaign['id'];
    }
    $contact_id = $this->getOrCreateContact($action, $campaign_id);
    return $this->processDonation($action, $campaign_id, $contact_id);
  }

  public function getOrCreateContact(CRM_WeAct_Action $action, $campaign_id) {
    if ($action->contact->isAnonymous()) {
      return $this->settings->anonymousId;
    }

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
    if ($this->requestConsents && $contact['api.GroupContact.get']['count'] == 0) {
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
    $result = NULL;
    CRM_Core_Transaction::create(TRUE)->run(function(CRM_Core_Transaction $tx) use ($action, $campaign_id, $contact_id, &$result) {

      $donation = $action->details;

      if ($donation->isRecurring()) {
        $recur_id = $donation->findMatchingContribRecur();
        if (!$recur_id) {
          $result = $donation->createContribRecur($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
          return;
        }
        if (!$donation->findMatchingContrib()) {
          $result = $donation->createContrib($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm, $recur_id);
          return;
        }
        return; // DON'T FALL THROUGH
      }

      if (!$donation->findMatchingContrib()) {
        $result = $donation->createContrib($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
        return;
      }

      CRM_Core_Error::debug_log_message("Couldn't figure out what to do with {json_encode($action)} in ActionProcessor->processDonation");

      return;

    });

    return $result;
  }
}
