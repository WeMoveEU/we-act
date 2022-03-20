<?php

/*
 * Webhook to process Paypal payments for recurring donations created outside of CiviCRM
 * N.B. The name of the class must contain Page (c.f. CRM_Core_Invoke)
 */
class CRM_WeAct_Page_Paypal extends CRM_Core_Page {

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function run() {
    $json_body = json_decode(file_get_contents('php://input'));
    $this->processNotification($json_body);
  }

  public function processNotification($json_body) {
    if ($this->isRecurringPayment($json_body)) {
      try {
        $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $json_body->resource->billing_agreement_id]);
      } catch(CiviCRM_API3_Exception $ex) {
        $contrib_recur = NULL;
        CRM_Core_Error::debug_log_message("Could not find any recurring contribution with transaction id {$json_body->resource->billing_agreement_id}, skipping.");
      }
      if ($contrib_recur) {
        $payment_params = [
          'contribution_recur_id' => $contrib_recur['id'],
          'receive_date' => $json_body->resource->create_time,
          'trxn_id' => $json_body->resource->id,
          'contribution_status_id' => $this->settings->contributionStatusIds[$json_body->resource->state],
          'campaign_id' =>       $contrib_recur['campaign_id'],
          'contact_id' => $contrib_recur['contact_id'],
          // 'source' => @$metadata->utm_source,
          'total_amount' => $contrib_recur['amount'],
          'currency' => $contrib_recur['currency'],
          'payment_instrument_id' => $contrib_recur['payment_instrument_id'],
          'payment_processor_id' => $contrib_recur['payment_processor_id'],
          'financial_type_id' => $this->settings->financialTypeId,
        ];
        CRM_Core_Error::debug_log_message("handleRecurringPayment: Creating contribution with " . json_encode($payment_params));

        $ret = civicrm_api3('Contribution', 'create', $payment_params);
        return $ret['values'][$ret['id']]; // so so weird      }
    }
  }
}

  public function isRecurringPayment($json_body) {
    return $json_body->event_type == 'PAYMENT.SALE.COMPLETED';
  }

}
