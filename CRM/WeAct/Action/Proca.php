<?php

class CRM_WeAct_Action_Proca extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'proca';
    $this->createdAt = $json_msg->action->createdAt;
    $this->actionPageId = $json_msg->actionPageId;
    $this->actionPageName = $json_msg->actionPage->name;
    $this->language = $this->determineLanguage($json_msg->actionPage->locale);
    $this->location = "proca.wemove.eu:donate";
    $this->contact = $this->buildContact(json_decode($json_msg->contact->payload));
    $this->details = $this->buildDonation($json_msg->action);
    $this->utm = [
      'source' => NULL, //$json_msg->source->source,
      'medium' => NULL, //$json_msg->source->medium,
      'campaign' => NULL, //$json_msg->source->campaign,
    ];
  }

  protected function buildContact($json_contact) {
    $contact = new CRM_WeAct_Contact();
    $contact->firstname = trim($json_contact->firstName);
    $contact->lastname = trim($json_contact->lastName);
    $contact->email = trim($json_contact->email);
    $contact->postcode = trim($json_contact->postcode);
    $contact->country = strtoupper($json_contact->country);
    return $contact;
  }

  protected function buildDonation($json_donation) {
    $statusMap = ['succeeded' => 'Completed', 'failed' => 'Failed'];
    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $json_donation->createdAt;
    $donation->status = $statusMap[$json_donation->fields->status];
    $donation->amount = intval($json_donation->fields->amount) / 100;
    $donation->fee = 0;
    $donation->currency = strtoupper($json_donation->fields->currency);
    $donation->processor = $this->externalSystem . '-' . $json_donation->fields->payment_method_types;
    /* if ($json_donation->payment_processor == 'sepa') {
      $donation->iban = $json_donation->iban;
      $donation->bic = $json_donation->bic;
    } */
    //if ($json_donation->type == 'single') {
      $donation->frequency = 'one-off';
      $donation->donationId = $json_donation->fields->id;
    /*}
    else {
      $donation->frequency = 'monthly';
      $donation->donationId = $json_donation->recurring_id;
    }*/
    $donation->paymentId = $json_donation->fields->id;
    return $donation;
  }

  protected function determineLanguage($procaLanguage) {
    $language = strtoupper($procaLanguage);
    $countryLangMapping = Civi::settings()->get('country_lang_mapping');
    if (array_key_exists($language, $countryLangMapping)) {
      return $countryLangMapping[$language];
    }
    return 'en_GB';
  }
}
