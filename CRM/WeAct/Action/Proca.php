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

  protected function _lookupCharge($pi) {
    $sk = CRM_Core_DAO::singleValueQuery(
        "SELECT password FROM civicrm_payment_processor WHERE id = 1" // I know, but it works
    );
    if (!$sk) {
      $sk = getenv("STRIPE_SECRET_KEY");
    }
    if (!$sk) {
      throw new Exception("Oops, couldn't find a secret key for Stripe. Can't go on!");
    }
    $stripe = new \Stripe\StripeClient($sk);
    $charges = $stripe->charges->all(['payment_intent' => $pi->id]);
    if (! $charges->data) {
      throw new Exception("Couldn't find a Charge for PaymentIntent: {$pi->id}");
    }
    return $charges->data[0];
  }

  protected function buildDonation($action_id, $json_action) {
    $frequencyMap = ['one_off' => 'one-off', 'monthly' => 'month', 'weekly' => 'week', 'daily' => 'day'];
    $settings = CRM_WeAct_Settings::instance();

    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $json_action->createdAt;
    $donation->status = $settings->contributionStatusIds['completed'];
    $donation->amount = intval($json_action->donation->amount) / 100;
    $donation->fee = 0;
    $donation->currency = strtoupper($json_action->donation->currency);
    if (@$frequencyMap[$json_action->donation->frequencyUnit]) {
      $donation->frequency = $frequencyMap[$json_action->donation->frequencyUnit];
    } else {
      $donation->frequency = 'one-off';
    }
    $donation->isTest = False;

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
      if ($_ENV['CIVICRM_UF'] == 'UnitTests') {
        $charge_id = property_exists($json_action->donation->payload, 'testingChargeId')
          ? $json_action->donation->payload->testingChargeId
          : 'ch_yetanothercharge';
      }
      else {
        $charge_id = $this->_lookupCharge($json_action->donation->payload->paymentIntent->response);
      }
      # this becomes civicrm_contribution.trxn_id
      $donation->paymentId = $charge_id;
      if ($donation->frequency == 'one-off') {
        $donation->donationId = $donation->paymentId;
      } else {
        // This should match up with the Stripe webhook - which sort of expects
        // the invoice id as trxn_id of contributions, but also does some weird
        // shit that is really hard to grok. So if there's a problem with
        // payments getting attached to recurring donations, maybe it's because
        // this doesn't match up with what the stripe extension is expecting.
        $donation->paymentId = $json_action->donation->payload->paymentIntent->response->latest_invoice->id;
        $donation->donationId = $json_action->donation->payload->subscriptionId;
        $donation->providerDonorId = $json_action->donation->payload->customerId;
      }
    } else if ($provider == "paypal") {
      $donation->paymentId = $json_action->donation->payload->response->orderID;
      $donation->paymentMethod = 'paypal';
      if ($donation->frequency == 'one-off') {
        $donation->donationId = $donation->paymentId;
      } else {
        $donation->donationId = $json_action->donation->payload->response->subscriptionID;
      }
    }

    return $donation;
  }

  static private function _language_code_to_locale($language) {
    // NOTE: this is terrible, but true
    if (strtoupper($language) == 'EN') {
        return "en_GB";
    }
    if (strlen($language) == 2) {
      return strtolower($language) . "_" . strtoupper($language);
    }
    return $language; // who knows, maybe it's valid =)
  }

  static public function determineLanguage($message) {
    /*
    Try to guess language:

      1. custom fields, set by hosting page
      2. country -> language mapping
      3. widget locale, set at build time
      4. en_GB
    */
    $page = $message->actionPage;
    $action = $message->action;
    $contact = $message->contact;

    $settings = CRM_WeAct_Settings::instance();
    $countryLangMapping = $settings->countryCodeToLocale;

    $language = @$action->customFields->language;
    if ($language) {
      return self::_language_code_to_locale($language);
    }

    $country = @$contact->country;
    if ($country) {
      if (array_key_exists($country, $countryLangMapping)) {
        return $countryLangMapping[$country];
      }
    }

    // NOTE: it's not really a locale, just a 2 char language code
    $locale = @$page->locale;
    if ($locale) {
      return self::_language_code_to_locale($locale);
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
