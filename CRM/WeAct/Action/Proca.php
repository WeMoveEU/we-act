<?php

class CRM_WeAct_Action_Proca extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'proca';
    $this->createdAt = $json_msg->action->createdAt;
    $this->actionPageId = $json_msg->actionPageId;
    $this->actionPageName = $json_msg->actionPage->name;
    $this->contact = $this->buildContact($json_msg->contact);
    $this->language = $this->determineLanguage($json_msg); // ->actionPage->locale);

    $this->details = $this->buildDonation($json_msg->actionId, $json_msg->action);
    $this->locationId = @$json_msg->action->customFields->speakoutCampaign;

    if (property_exists($json_msg, 'tracking') && $json_msg->tracking) {
      $this->utm = [];
      $tracking = $json_msg->tracking;
      foreach (['source', 'medium', 'campaign', 'location'] as $key) {
        if (
          property_exists($tracking, $key)
          && $tracking->{$key}
          && $tracking->{$key} != "unknown"
        ) {
          $this->utm[$key] = $tracking->{$key};
        }
      }
      $this->location = @$json_msg->tracking->location;
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
    if (property_exists($json_contact, 'postcode')) {
      $contact->postcode = trim($json_contact->postcode);
    }
    if (property_exists($json_contact, 'country')) {
      $contact->country = strtoupper($json_contact->country);
    }
    return $contact;
  }

  protected function buildDonation($action_id, $json_action) {
    $statusMap = ['succeeded' => 'Completed', 'failed' => 'Failed'];
    $frequencyMap = ['one_off' => 'one-off', 'monthly' => 'month', 'weekly' => 'week', 'daily' => 'day'];

    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $json_action->createdAt;
    $donation->status = 'Completed'; //FIXME $statusMap[$json_action->customFields->status];
    $donation->amount = intval($json_action->donation->amount) / 100;
    $donation->fee = 0;
    $donation->currency = strtoupper($json_action->donation->currency);
    if (@$frequencyMap[$json_action->donation->frequencyUnit]) {
      $donation->frequency = $frequencyMap[$json_action->donation->frequencyUnit];
    } else {
      $donation->frequency = 'one-off';
    }
    $donation->isTest = FALSE;

    $provider = $json_action->donation->payload->provider;
    $donation->processor = $this->externalSystem . '-' . $provider;
    if ($provider == 'sepa') {
      $donation->iban = $json_action->donation->payload->iban;
      $donation->bic = "NOTPROVIDED";
      $donation->paymentId = "proca_$action_id";
      $donation->donationId = $donation->paymentId;
      $donation->paymentMethod = 'sepa';
    } else if ($provider == 'stripe') {
      $donation->paymentMethod = $json_action->donation->payload->paymentConfirm->payment_method_types[0];
      $donation->isTest = !$json_action->donation->payload->paymentIntent->response->livemode;
      if ($donation->frequency == 'one-off') {
        $donation->paymentId = $json_action->donation->payload->paymentIntent->response->id;
        $donation->donationId = $donation->paymentId;
      } else {
        //Stripe webhook expects invoice id as trxn_id of contributions
        $donation->paymentId = $json_action->donation->payload->paymentIntent->response->latest_invoice->id;
        $donation->donationId = $json_action->donation->payload->subscriptionId;
        $donation->providerDonorId = $json_action->donation->payload->customerId;
      }
    } else if ($provider == "paypal") {
      $donation->paymentId = $json_action->donation->payload->response->orderID;
      $donation->paymentMethod = 'paypal';
      if ($donation->frequency == 'one-off') {

        $donation->donationId = $donation->paymentId; /// ???
      } else {
        $donation->donationId = $json_action->donation->payload->response->subscriptionID;
      }
    }

    return $donation;
  }

  public function determineLanguage($action) {
    /*
      Hrm, I'm very confused about what to do here. Sometimes we have a Country,
      sometimes we don't. Language and Locale are used inter-changeably
      sometimes.

      We have Language based lists, so we should try hardest to get the language and
      only secondarily the country.

      And we should add Country to add the forms. Duh.
    */
    $page = $action->actionPage;
    $action = $action->action;

    if (property_exists($action->customFields, 'language')) {
      $language = $action->customFields->language;
    } else {
      $language = $page->locale;
    }
    $language = strtoupper($language);

    $settings = CRM_WeAct_Settings::instance();
    $countryLangMapping = $settings->countryCodeToLocale;

    if (array_key_exists($language, $countryLangMapping)) {
      return $countryLangMapping[$language];
    }

    return 'en_GB';
  }

  // protected function determineLanguage($procaLanguage) {
  //   $language = strtoupper($procaLanguage);
  //   $countryLangMapping = Civi::settings()->get('country_lang_mapping');
  //   if (array_key_exists($language, $countryLangMapping)) {
  //     return $countryLangMapping[$language];
  //   }
  //   return 'en_GB';
  // }
}
