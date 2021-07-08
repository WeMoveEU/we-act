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
      civicrm_api3('ContributionRecur', 'create', [
        'id' => $create_mandate['values'][0]['entity_id'],
        $this->settings->customFields['recur_utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['recur_utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['recur_utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ]);
    } else {
      $processor_id = $this->settings->paymentProcessorIds[$this->processor];

      if ($this->processor == 'proca-stripe') {
        //Stripe webhook requires a link customer<->contact to process events, so we create it here if needed
        $customer_params = [
          'customer_id' => $this->providerDonorId,
          'contact_id' => $contact_id,
          'processor_id' => $processor_id
        ];
        $customer = civicrm_api3('StripeCustomer', 'get', $customer_params);
        if ($customer['count'] == 0) {
          civicrm_api3('StripeCustomer', 'create', $customer_params);
        }
      }

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
        'payment_instrument_id' => $this->settings->paymentInstrumentIds[$this->paymentMethod],
        'payment_processor_id' => $processor_id,
        'campaign_id' => $campaign_id,
        $this->settings->customFields['recur_utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['recur_utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['recur_utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ];
      $create_recur = civicrm_api3('ContributionRecur', 'create', $params);
      $this->createContrib($campaign_id, $contact_id, $action_page, $location, $utm, $create_recur['id']);
    }
  }

  public function createContrib($campaign_id, $contact_id, $action_page, $location, $utm, $recurring_id = NULL) {
    $statusMap = ['success' => 'Completed', 'failed' => 'Failed'];
    if (substr($this->processor, -5) == '-sepa') {
      $create_mandate = $this->createMandate($contact_id, 'OOFF', $campaign_id, $action_page);
      //Mandates don't have utm fields, so associate them to recurring contrib created along with mandate
      civicrm_api3('Contribution', 'create', [
        'id' => $create_mandate['values'][0]['entity_id'],
        $this->settings->customFields['utm_source'] => CRM_Utils_Array::value('source', $utm),
        $this->settings->customFields['utm_medium'] => CRM_Utils_Array::value('medium', $utm),
        $this->settings->customFields['utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
      ]);
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
        'contribution_status' => CRM_Utils_Array::value($this->status, $statusMap, 'Pending'),
        'currency' => $this->currency,
        'subject' => $action_page,
        'source' => $action_page,
        'location' => $location,
      ];
      if ($recurring_id) {
        $params['contribution_recur_id'] = $recurring_id;
        //The utm params will be set by a hook in contributm extension, let's not mess with it
      }
      else {
        $params[$this->settings->customFields['utm_source']] = CRM_Utils_Array::value('source', $utm);
        $params[$this->settings->customFields['utm_medium']] = CRM_Utils_Array::value('medium', $utm);
        $params[$this->settings->customFields['utm_campaign']] = CRM_Utils_Array::value('campaign', $utm);
      }
      civicrm_api3('Contribution', 'create', $params);
    }
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
