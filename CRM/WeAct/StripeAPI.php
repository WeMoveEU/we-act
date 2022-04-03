<?php

/* Wrap the Stripe Api to make testing easier */

class CRM_WeAct_StripeAPI {

  public function __construct() {
    $settings = CRM_WeAct_Settings::instance();
    // TODO - update settings to load the user / pass in getPaymentProcessorIds
    $stripeSettings = civicrm_api3(
      'PaymentProcessor',
      'getsingle',
      ['id' => $settings->paymentProcessorIds['proca-stripe']]
    );
    $this->stripeAPI = new \Stripe\StripeClient($stripeSettings['password']);
  }

  public function getCustomer($customer_id) {
    return $this->stripeAPI->customer->get($customer_id);
  }

  public function getSubscription($subscription_id) {
    return $this->stripeAPI->subscription->get($subscription_id);
  }

  public function getInvoices($subscription_id) {
    $invoices = $this->stripeAPI->invoices->get(["subscription" => $subscription_id]);
    $all = [];
    while (true) {
      foreach ($invoices['data'] as $invoice) {
        array_push($all, $invoice);
      }
      if ($invoices->has_more) {
        $invoices = $this->stripeAPI->invoices->get([
          "subscription" => $subscription_id, "starting_after" => $invoice->id
        ]);
      }
      else {
        // no more!
        break;
      }
    }
    return $all;
  }

}
