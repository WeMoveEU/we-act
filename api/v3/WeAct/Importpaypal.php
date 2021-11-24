<?php
use CRM_WeAct_ExtensionUtil as E;

function _civicrm_api3_we_act_Importpaypal_spec(&$spec) {
  $spec['start'] = [
    'name' => 'start',
    'title' => ts('Start date'),
    'description' => 'Date from which the recovery should start in format ISO 8601',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];
  $spec['end'] = [
    'name' => 'end',
    'title' => ts('End date'),
    'description' => 'Date at which the recovery should end in format ISO 8601',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];
  $spec['payment_processor_id'] = [
    'name' => 'payment_processor_id',
    'title' => ts('Payment processor id'),
    'description' => 'Payment processor id',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 1,
  ];
}

/**
 * Import into CiviCRM all Paypal donations and payments returned by the transaction search.
 * Create contacts and campaigns when required.
 * N.B.: Paypal does not return transactions for the creation of recurring donations,
 * so the start date of recurring donations is the payment date. It may not be the first payment!!
 */
function civicrm_api3_we_act_Importpaypal($params) {
  $payment_processor = civicrm_api3('PaymentProcessor', 'getsingle', [ 'id' => $params['payment_processor_id'] ]);
  $http_client = new \GuzzleHttp\Client();
  $access_token = _we_act_get_paypal_token($http_client, $payment_processor);

  $history_response = $http_client->request('GET', "{$payment_processor['url_api']}/v1/reporting/transactions", [
    'query' => ['start_date' => $params['start'], 'end_date' => $params['end'], 'fields' => 'payer_info,transaction_info', 'page_size' => '500'],
    'headers' => ['Authorization' => "Bearer $access_token", 'Content-Type' => 'application/json'],
  ]);
  if ($history_response->getStatusCode() == 200) {
    $result = [ 'processed' => [], 'not_processed' => [] ];
    $action_processor = new CRM_WeAct_ActionProcessor(FALSE);
    foreach (json_decode($history_response->getBody())->transaction_details as $transaction) {
      if (in_array($transaction->transaction_info->transaction_event_code, ['T0013', 'T0002'])) {
        try {
          $action = new CRM_WeAct_Action_PaypalTransaction($transaction);
          $action_processor->process($action);
          $result['processed'][] = $action->details->donationId;
        }
        catch (Exception $e) {
          $result['not_processed'][] = $transaction->transaction_info->transaction_id . " - " . $e->getMessage();
        }
      }
      else {
        $result['not_processed'][] = $transaction->transaction_info->transaction_id . " - Event code is " . $transaction->transaction_info->transaction_event_code;
      }
    }
    $result['count'] = count($result['processed']) + count($result['not_processed']);
    return civicrm_api3_create_success($result);
  }
  else {
    throw new API_Exception("Paypal transaction search failed");
  }
}

function _we_act_get_paypal_token($http_client, $payment_processor) {
  $base_url = $payment_processor['url_api'];
  $auth_response = $http_client->request('POST', "$base_url/v1/oauth2/token", [
    'auth' => [$payment_processor['user_name'], $payment_processor['password']],
    'body' => 'grant_type=client_credentials',
  ]);
  if ($auth_response->getStatusCode() == 200) {
    return json_decode($auth_response->getBody())->access_token;
  }
  else {
    throw new API_Exception("Paypal authentication failed");
  }
}
