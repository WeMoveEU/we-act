<?php

class CRM_WeAct_Action_Proca extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'proca';
    $this->createdAt = $json_msg->action->createdAt;
    $this->actionPageId = $json_msg->actionPageId;
    $this->actionPageName = $json_msg->actionPage->name;
    $this->contact = $this->buildContact(json_decode($json_msg->contact->payload));
    $this->language = $this->contact->determineLanguage($json_msg->actionPage->locale);

    $this->details = $this->buildDonation($json_msg->actionId, $json_msg->action);

    $this->locationId = @$json_msg->action->fields->speakoutCampaign;
    if (property_exists($json_msg, 'tracking') && $json_msg->tracking) {
      $this->utm = [
        'source' => @$json_msg->tracking->source,
        'medium' => @$json_msg->tracking->medium,
        'campaign' => @$json_msg->tracking->campaign,
      ];
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
    $contact->postcode = trim($json_contact->postcode);
    $contact->country = strtoupper($json_contact->country);
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
    // $statusMap = ['succeeded' => 'Completed', 'failed' => 'Failed'];
    $frequencyMap = ['one_off' => 'one-off', 'monthly' => 'month', 'weekly' => 'week', 'daily' => 'day'];

    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $json_action->createdAt;
    $donation->status = 'Completed'; //FIXME $statusMap[$json_action->fields->status];
    $donation->amount = intval($json_action->donation->amount) / 100;
    $donation->fee = 0;
    $donation->currency = strtoupper($json_action->donation->currency);
    $donation->frequency = $frequencyMap[$json_action->donation->frequencyUnit];
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
        $donation->donationId = $json_action->donation->payload->subscriptionId;
        $donation->providerDonorId = $json_action->donation->payload->customerId;
      }
    } else if ($provider == "paypal") {
      $donation->paymentId = $json_action->donation->payload->order->id;
      $donation->paymentMethod = 'paypal';
      if ($donation->frequency == 'one-off') {
        $donation->donationId = $donation->paymentId;
      } else {
        $donation->donationId = $json_action->donation->payload->subscriptionId;
      }
    }

    return $donation;
  }


}
