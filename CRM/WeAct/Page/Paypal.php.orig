<?php

/*
 * Webhook to process Paypal payments for recurring donations created outside of CiviCRM
 * N.B. The name of the class must contain Page (c.f. CRM_Core_Invoke)
 */
class CRM_WeAct_Page_Paypal extends CRM_Core_Page {

  //FIXME add missing statuses
  private $civiStatus = [
    'completed' => 'Completed'
  ];

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
        $repeat_params = [
          'contribution_recur_id' => $contrib_recur['id'],
          'contribution_status_id' => $this->civiStatus[$json_body->resource->state],
          //Paypal sends this date in UTC in ISO8601 format, which civi understands fine
          'receive_date' => $json_body->resource->create_time,
          'trxn_id' => $json_body->resource->id,
        ];
        civicrm_api3('Contribution', 'repeattransaction', $repeat_params);
      }
    }
  }

  public function isRecurringPayment($json_body) {
    return $json_body->event_type == 'PAYMENT.SALE.COMPLETED';
  }

}
