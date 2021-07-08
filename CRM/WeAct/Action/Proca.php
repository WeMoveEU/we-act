<?php

class CRM_WeAct_Action_Proca extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'proca';
    $this->createdAt = $json_msg->action->createdAt;
    $this->actionPageId = $json_msg->actionPageId;
    $this->actionPageName = $json_msg->actionPage->name;
    $this->language = $this->determineLanguage($json_msg->actionPage->locale);
    $this->contact = $this->buildContact(json_decode($json_msg->contact->payload));
    $this->details = $this->buildDonation($json_msg->actionId, $json_msg->action);
    if (property_exists($json_msg, 'tracking') && $json_msg->tracking) {
      $this->utm = [
        'source' => @$json_msg->tracking->source,
        'medium' => @$json_msg->tracking->medium,
        'campaign' => @$json_msg->tracking->campaign,
      ];
      $this->location = @$json_msg->tracking->location;
      $this->locationId = @$json_msg->action->fields->speakoutCampaign;
    } else {
      $this->utm = NULL;
      $this->location = "proca:donate";
    }
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

  protected function buildDonation($action_id, $json_action) {
    $statusMap = ['succeeded' => 'Completed', 'failed' => 'Failed'];
    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $json_action->createdAt;
    $donation->status = 'Completed'; //FIXME $statusMap[$json_action->fields->status];
    $donation->amount = intval($json_action->donation->amount) / 100;
    $donation->fee = 0;
    $donation->currency = strtoupper($json_action->donation->currency);

    if ($json_action->donation->frequencyUnit == 'one_off') {
      $donation->frequency = 'one-off';
    }
    else {
      $donation->frequency = 'monthly';
    }

    $provider = $json_action->donation->payload->provider;
    $donation->processor = $this->externalSystem . '-' . $provider;
    if ($provider == 'sepa') {
      $donation->iban = $json_action->donation->payload->iban;
      $donation->bic = "NOTPROVIDED";
      $donation->paymentId = "proca_$action_id";
      $donation->donationId = $donation->paymentId;
      $donation->paymentMethod = 'sepa';
    } else if ($provider == 'stripe') {
      $donation->paymentId = $json_action->donation->payload->paymentIntent->response->id;
      $donation->paymentMethod = $json_action->donation->payload->paymentConfirm->payment_method_types[0];
      if ($donation->frequency == 'one-off') {
        $donation->donationId = $donation->paymentId;
      } else {
        $donation->donationId = $json_action->donation->payload->subscriptionId;
        $donation->providerDonorId = $json_action->donation->payload->customerId;
      }
    } else if ($provider == "paypal") {
      $donation->paymentMethod = 'paypal';
      $donation->paymentId = $json_action->donation->payload->order->id;
      $donation->donationId = $donation->paymentId;
    }

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
