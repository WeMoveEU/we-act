<?php

class CRM_WeAct_Action_PaypalTransaction extends CRM_WeAct_Action {

  public function __construct($json_transaction) {
    $transaction_info = $json_transaction->transaction_info;
    $contact_info = $json_transaction->payer_info;
    $custom_fields = json_decode(urldecode($transaction_info->custom_field));

    $this->actionType = 'donate';
    $this->externalSystem = 'paypal';
    $this->createdAt = $transaction_info->transaction_initiation_date;
    $this->language = 'en_GB';
    $this->actionPageName = "Paypal button";

    $this->contact = $this->buildContact($contact_info);
    $this->details = $this->buildDonation($transaction_info);

    $this->locationId = @$custom_fields->speak;
    // We store the speakout page id but not its location, so we assume it's prod act...
    // A better solution would be to make this location a parameter or setting
    $this->location = "https://act.wemove.eu";
    $this->utm = [
      'source' => @$custom_fields->utm_source,
      'medium' => @$custom_fields->utm_medium,
      'campaign' => @$custom_fields->utm_campaign,
    ];
  }

  protected function buildContact($contact_info) {
    $contact = new CRM_WeAct_Contact();
    $contact->firstname = $contact_info->payer_name->given_name;
    $contact->lastname = $contact_info->payer_name->surname;
    $contact->email = $contact_info->email_address;
    $contact->country = $contact_info->country_code;
    return $contact;
  }

  protected function buildDonation($trxn) {
    $statusMap = ['D' => 'Failed', 'P' => 'Pending', 'S' => 'Completed', 'V' => 'Refunded'];
    $trxnTypeMap = ['T0013' => 'one-off', 'T0002' => 'month'];

    $donation = new CRM_WeAct_Action_Donation();
    $donation->createdAt = $trxn->transaction_initiation_date;
    $donation->status = $statusMap[$trxn->transaction_status];
    $donation->amount = floatval($trxn->transaction_amount->value);
    $donation->fee = -floatval($trxn->fee_amount->value);
    $donation->currency = $trxn->transaction_amount->currency_code;
    $donation->frequency = $trxnTypeMap[$trxn->transaction_event_code];
    $donation->processor = 'paypal-button';
    $donation->paymentMethod = 'paypal';
    $donation->isTest = FALSE;
    $donation->paymentId = $trxn->transaction_id;
    if ($donation->frequency == 'one-off') {
      $donation->donationId = $donation->paymentId;
    } else {
      $donation->donationId = $trxn->paypal_reference_id;
    }

    return $donation;
  }
}
