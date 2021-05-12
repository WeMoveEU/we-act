<?php

class CRM_WeAct_Action_Donation {

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function isRecurring() {
    return $this->frequency != 'one-off';
  }

  public function findMatchingContribRecur() {
    $get_result = civicrm_api3('ContributionRecur', 'get', [
      'sequential' => 1,
      'trxn_id' => $this->donationId
    ]);
    if ($get_result['count'] > 0) {
      return $get_result['values'][0]['id'];
    }
    return NULL;
  }

  public function findMatchingContrib() {
    $get_result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'trxn_id' => $this->paymentId
    ]);
    if ($get_result['count'] > 0) {
      return $get_result['values'][0]['id'];
    }
    return NULL;
  }

  public function createContribRecur($campaign_id, $contact_id, $utm) {
		$params = [
      'sequential' => 1,
      'contact_id' => $contact_id,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'start_date' => $this->createdAt,
      'create_date' => $this->createdAt,
      'trxn_id' => $this->donationId,
      'contribution_status_id' => 'In Progress',
      'financial_type_id' => $this->settings->financialTypeId,
      'payment_instrument_id' => $this->settings->paymentInstrumentId,
      'payment_processor_id' => $this->settings->paymentProcessorIds[$this->processor],
      'campaign_id' => $campaign_id,
      $this->settings->customFields['recur_source'] => $utm['source'],
      $this->settings->customFields['recur_medium'] => $utm['medium'],
      $this->settings->customFields['recur_campaign'] => $utm['campaign'],
    ];
    $result = civicrm_api3('ContributionRecur', 'create', $params);
    return $result['values'][0];
  }

  public function createContrib($campaign_id, $contact_id, $action_page, $location, $utm, $recurring_id) {
    $statusMap = ['success' => 'Completed', 'failed' => 'Failed'];
    $params = [
      'sequential' => 1,
      'source_contact_id' => $contact_id,
      'contact_id' => $contact_id,
      'contribution_campaign_id' => $campaign_id,
      'financial_type_id' => $this->settings->financialTypeId,
      'payment_instrument_id' => $this->settings->paymentInstrumentId,
      'payment_processor_id' => $this->settings->paymentProcessorIds[$this->processor],
      'receive_date' => $this->createdAt,
      'total_amount' => $this->amount,
      'fee_amount' => $this->fee,
      'net_amount' => ($this->amount - $this->fee),
      'trxn_id' => $this->paymentId,
      'contribution_status' => CRM_Utils_Array::value($this->status, $statusMap, 'Pending'),
      'currency' => $this->currency,
      'subject' => $action_page,
      'source' => $action_page,
      'location' => $location,
      $this->settings->customFields['utm_source'] => $utm['source'],
      $this->settings->customFields['utm_medium'] => $utm['medium'],
      $this->settings->customFields['utm_campaign'] => $utm['campaign'],
    ];
    if ($recurring_id) {
      $params['contribution_recur_id'] = $recurring_id;
    }
    print_r($params);
    civicrm_api3('Contribution', 'create', $params);
  }
}
