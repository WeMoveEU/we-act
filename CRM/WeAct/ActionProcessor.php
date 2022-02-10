<?php

class CRM_WeAct_ActionProcessor {

  public function __construct($request_consents = TRUE) {
    $this->settings = CRM_WeAct_Settings::instance();
    $this->campaignCache = new CRM_WeAct_CampaignCache(Civi::cache(), new \GuzzleHttp\Client());
    $this->requestConsents = $request_consents;
  }

  public function process(CRM_WeAct_Action $action) {
    if ($action->actionType == 'donate') {
      $campaign = $this->campaignCache->getFromAction($action);
      $contact_id = $this->getOrCreateContact($action, $campaign['id']);
      $this->processDonation($action, $campaign['id'], $contact_id);
    }
  }

  public function getOrCreateContact(CRM_WeAct_Action $action, $campaign_id) {
    $result = $action->contact->createOrUpdate(
      $action->language,
      $action->source()
    );

    if ($this->requestConsents) {
      $action->contact->sendConsents(
        $result['id'],
        $campaign_id,
        [ 'source' => CRM_Utils_Array::value('source', $action->utm),
          'medium' => CRM_Utils_Array::value('medium', $action->utm),
          'campaign' => CRM_Utils_Array::value('campaign', $action->utm) ]
      );
    }

    return $result['id'];
  }

  public function processDonation($action, $campaign_id, $contact_id) {
    CRM_Core_Transaction::create(TRUE)->run(
      function(CRM_Core_Transaction $tx) use ($action, $campaign_id, $contact_id) {
        $donation = $action->details;
        if ($donation->isRecurring()) {
          $recur_id = $donation->findMatchingContribRecur();
          if (!$recur_id) {
            return $donation->createContribRecur($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
          }
          else if (!$donation->findMatchingContrib()) {
            return $donation->createContrib($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm, $recur_id);
          }
        }
        else if (!$donation->findMatchingContrib()) {
          return $donation->createContrib($campaign_id, $contact_id, $action->actionPageName, $action->location, $action->utm);
        }
    });
  }
}
