<?php

class CRM_WeAct_Action_Houdini extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'houdini';
    $this->createdAt = $json_msg->create_dt;
    $this->actionPageId = $json_msg->external_id;
    $this->actionPageName = $json_msg->action_name;
    $this->language = $this->determineLanguage($json_msg->action_name);
    $this->location = $json_msg->action_technical_type;
    $this->contact = $this->buildContact($json_msg->contact);
    $this->details = $this->buildDonation($json_msg->donation);
    $this->utm = [
      'source' => $json_msg->source->source,
      'medium' => $json_msg->source->medium,
      'campaign' => $json_msg->source->campaign,
    ];
  }

  protected function buildContact($json_contact) {
    $contact = new CRM_WeAct_Contact();
    $contact->firstname = trim($json_contact->firstname);
    $contact->lastname = trim($json_contact->lastname);
    $contact->email = trim($json_contact->emails[0]->email);
    $contact->postcode = trim($json_contact->addresses[0]->zip);
    $contact->country = strtoupper($json_contact->addresses[0]->country);
    return $contact;
  }

  protected function buildDonation($json_donation) {
    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $this->createdAt;
    $donation->status = $json_donation->status;
    $donation->amount = $json_donation->amount;
    $donation->fee = $json_donation->amount_charged;
    $donation->currency = strtoupper($json_donation->currency);
    $donation->processor = $this->externalSystem . '-' . $json_donation->payment_processor;
    if ($json_donation->payment_processor == 'sepa') {
      $donation->iban = $json_donation->iban;
      $donation->bic = $json_donation->bic;
    }
    if ($json_donation->type == 'single') {
      $donation->frequency = 'one-off';
      $donation->donationId = $json_donation->transaction_id;
    }
    else {
      $donation->frequency = 'monthly';
      $donation->donationId = $json_donation->recurring_id;
    }
    $donation->paymentId = $json_donation->transaction_id;
    return $donation;
  }

  protected function determineLanguage($campaignName) {
    $re = "/(.*)[_\\- ]([a-zA-Z]{2})$/";
    if (preg_match($re, $campaignName, $matches)) {
      $country = strtoupper($matches[2]);
      $countryLangMapping = Civi::settings()->get('country_lang_mapping');
      if (array_key_exists($country, $countryLangMapping)) {
        return $countryLangMapping[$country];
      }
    }
    return 'en_GB';
  }
}
