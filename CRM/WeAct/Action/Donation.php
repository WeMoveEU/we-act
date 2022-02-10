<?php

class CRM_WeAct_Action_Donation {

  const CYCLE_DAY_FIRST = 6;
  const CYCLE_DAY_SECOND = 21;

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function isRecurring() {
    return $this->frequency != 'one-off';
  }

  public function cycleDay($date) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $date);
    $day = $dt->format('d');
    if ($day >= self::CYCLE_DAY_SECOND || $day < self::CYCLE_DAY_FIRST) {
      return self::CYCLE_DAY_FIRST;
    }
    return self::CYCLE_DAY_SECOND;
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

  public function createContribRecur($campaign_id, $contact_id, $action_page, $location, $utm) {
    if (substr($this->processor, -5) == '-sepa') {
      $create_mandate = $this->createMandate($contact_id, 'RCUR', $campaign_id, $action_page);
      //Mandates don't have utm fields, so associate them to recurring contrib created along with mandate
      $created = civicrm_api3('ContributionRecur', 'create', [
        'id' => $create_mandate['values'][0]['entity_id'],
        $this->settings->customFields['recur_utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['recur_utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['recur_utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ]);
      // careful, a real create returns a list, an update returns a map.
      return $created['values'][$created['id']];
    } else {
      $processor_id = $this->settings->paymentProcessorIds[$this->processor];

      $params = [
        'sequential' => 1,
        'contact_id' => $contact_id,
        'amount' => $this->amount,
        'currency' => $this->currency,
        'frequency_unit' => $this->frequency,
        'frequency_interval' => 1,
        'start_date' => $this->createdAt,
        'create_date' => $this->createdAt,
        'trxn_id' => $this->donationId,
        'contribution_status_id' => 'In Progress',
        'financial_type_id' => $this->settings->financialTypeId,
        'payment_instrument_id' => $this->settings->paymentInstrumentIds[$this->paymentMethod],
        'payment_processor_id' => $processor_id,
        'campaign_id' => $campaign_id,
        'is_test' => false, // $this->isTest,
        $this->settings->customFields['recur_utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['recur_utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['recur_utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ];
      $create_recur = civicrm_api3('ContributionRecur', 'create', $params);
      $this->createContrib($campaign_id, $contact_id, $action_page, $location, $utm, $create_recur['id']);
      return $create_recur['values'][0];
    }
  }

  public function createContrib($campaign_id, $contact_id, $action_page, $location, $utm, $recurring_id = NULL) {
    $statusMap = ['success' => 'Completed', 'failed' => 'Failed'];
    $contribution = NULL;
    if (substr($this->processor, -5) == '-sepa') {
      $create_mandate = $this->createMandate($contact_id, 'OOFF', $campaign_id, $action_page);
      // Mandates don't have utm fields, so associate them to the contribution created along with mandate
      $contribution = civicrm_api3('Contribution', 'create', [ // "create" is a lie, this is an update
        'id' => $create_mandate['values'][0]['entity_id'],
        $this->settings->customFields['utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ]);
      // careful, a real create returns a list, an update returns a map.
      return $contribution['values'][$contribution['id']];
    }
    else {
      $params = [
        'sequential' => 1,
        'source_contact_id' => $contact_id,
        'contact_id' => $contact_id,
        'contribution_campaign_id' => $campaign_id,
        'financial_type_id' => $this->settings->financialTypeId,
        'payment_instrument_id' => $this->settings->paymentInstrumentIds[$this->paymentMethod],
        'payment_processor_id' => $this->settings->paymentProcessorIds[$this->processor],
        'receive_date' => $this->createdAt,
        'total_amount' => $this->amount,
        'fee_amount' => $this->fee,
        'net_amount' => ($this->amount - $this->fee),
        'trxn_id' => $this->paymentId,
        'invoice_id' => @$this->donationId, // ? $this->invoiceId : $this->paymentId,
        'contribution_status' => CRM_Utils_Array::value($this->status, $statusMap, 'Pending'),
        'currency' => $this->currency,
        'subject' => $action_page,
        'source' => $action_page,
        'location' => $location,
        'is_test' => false, // $this->isTest,
      ];
      if ($recurring_id) {
        $params['contribution_recur_id'] = $recurring_id;
        // The utm params get copied by repeattransation *and* set by a hook in contributm extension, let's not mess with it?
      }
      else {
        $params[$this->settings->customFields['utm_source']] = CRM_Utils_Array::value('source', $utm);
        $params[$this->settings->customFields['utm_medium']] = CRM_Utils_Array::value('medium', $utm);
        $params[$this->settings->customFields['utm_campaign']] = CRM_Utils_Array::value('campaign', $utm);
      }
      $contribution = civicrm_api3('Contribution', 'create', $params);
    }
    return $contribution['values'][0];
  }

  protected function createMandate($contact_id, $mandate_type, $campaign_id, $source) {
    $params_mandate = [
      'sequential' => 1,
      'contact_id' => $contact_id,
      'type' => $mandate_type,
      'iban' => $this->iban,
      'bic' => $this->bic,
      'start_date' => $this->createdAt,
      'creation_date' => $this->createdAt,
      'amount' => $this->amount,
      'currency' => $this->currency,
      'frequency_interval' => 1,
      'financial_type_id' => $this->settings->financialTypeId,
      'payment_processor_id' => $this->settings->paymentProcessorIds[$this->processor],
      'campaign_id' => $campaign_id,
      'trxn_id' => $this->donationId,
      'source' => $source,
    ];
    if ($mandate_type == 'RCUR') {
      $params_mandate['cycle_day'] = $this->cycleDay($this->createdAt);
    }
    return civicrm_api3('SepaMandate', 'createfull', $params_mandate);
  }
}
