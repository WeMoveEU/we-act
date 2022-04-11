<?php

use Stripe\StripeClient;

require_once('vendor/autoload.php');


class CRM_WeAct_Action_StripeSubscriptionImport extends CRM_WeAct_Action {

  public function __construct($message, $stripe = NULL) {
    $subscription_id = $message['id'];
    $this->actionType = 'donate';
    $this->externalSystem = 'stripe';

    if ($stripe) {
      $this->stripeAPI = $stripe;
    } else {
      $this->stripeAPI = new CRM_WeAct_StripeAPI();
    }

    $subscription = $this->stripeAPI->getSubscription($subscription_id);
    $this->stripeSubscription = $subscription;

    $this->stripeCustomer = $this->loadCustomer($subscription->customer);
    $this->contact = $this->buildContact($this->stripeCustomer);

    $this->createdAt = $subscription->created;

    $language = "en_GB";
    $locales = $this->stripeCustomer->preferred_locales;

    if (count($locales) > 0) {
      $language =  $this->contact->determineLanguage($locales[0]);
    }
    else {
      $language = $this->contact->determineLanguage($this->contact->country);
    }
    $this->language = $language;

    // TODO: figure out a way to automate, so new languages don't blow up. For
    // now
    $ImportSpeakoutCampaigns = [
      'es' => 3041,
      'de' => 3040,
      'pl' => 3039,
      'pt' => 3938,
      'it' => 3037,
      'fr' => 3036,
      'en' => 3035,
    ];
    $this->actionPageId = $ImportSpeakoutCampaigns[strtolower(substr($this->language, 0, 2))];
    $this->actionPageName = 'weact-stripe-subscription-import';

    $this->details = $this->buildDonation($subscription);

    # TODO - recover tracking if we can...
    $this->utm = NULL;
    $this->location = "stripe:resync";
  }

  function loadCustomer($customer_id) {
    return $this->stripeAPI->getCustomer($customer_id);
  }

  function buildContact($customer) {
    $contact = new CRM_WeAct_Contact();

    $contact->name = $customer->name;
    $contact->email = $customer->email;

    if (@$customer->subscriptions->data[0]->metadata->first_name) {
      $m = $customer->subscriptions->data[0]->metadata;
      $contact->first_name = $m->first_name;
      $contact->last_name = $m->last_name;
    } else {
      $name = explode(' ', $customer->name, 2);
      $contact->first_name = $name[0];
      $contact->last_name = $name[1];
    }

    $contact->postcode = @$customer->address->postal_code;
    $contact->country = @$customer->address->country;
    $contact->city = @$customer->address->city;

    return $contact;
  }

  function buildDonation($subscription) {
    $settings = CRM_WeAct_Settings::instance();

    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = gmdate("Y-m-d\TH:i:s\Z", $subscription->created);
    $donation->status = $settings->recurringContributionstatusMap[$subscription->status];

    $product = $subscription->items->data[0];
    $donation->amount = (intval(
      $product->price->unit_amount * $product->quantity
    ) / 100);
    $donation->fee = 0;
    $donation->currency = strtoupper($product->price->currency);
    $donation->frequency = $product->price->recurring->interval;

    // just ignore the CiviCRM test processor, in
    // staging we use the test keys in production we use the live keys
    $donation->isTest = False;

    $donation->processor = 'stripe';
    $donation->paymentMethod = 'card';

    $first_payment = $this->firstPayment($subscription->id);
    $donation->paymentId = $first_payment->payment_intent;
    $donation->donationId = $subscription->id;
    $donation->providerDonorId = $subscription->customer;

    return $donation;
  }

  function postProcess() {
    foreach ($this->invoices as $invoice) {
      CRM_WeAct_Page_Stripe::addRecurringPayment($invoice);
    }
  }

  function firstPayment($subscription_id) {
    $invoices = $this->invoices = $this->stripeAPI->getInvoices($subscription_id);
    if (count($invoices) == 0) {
      throw Exception("StripeSubscriptionImport: No invoices found for subscription $subscription_id");
    }
    $first_payment = null;
    foreach (array_reverse($invoices) as $invoice) {
      if ($invoice->billing_reason == "subscription_create") {
        $first_payment = $invoice;
        break;
      }
    }
    if ($first_payment == null) {
      throw Exception("StripeSubscriptionImport: No subscription_create found for subscription $subscription_id");
    }

    return $first_payment;
  }
}
