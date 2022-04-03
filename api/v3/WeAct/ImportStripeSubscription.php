<?php

require_once 'common.php';

/**
 *
 * WeAct.StripeSubscriptionImport
 *
 *    - message is a subscription object from the Stripe API
 *    - imports all the invoices as well
 *
 *
**/

function _civicrm_api3_we_act_StripeSubcriptionImport_spec(&$spec) {
  $spec['message'] = [
    'title' => 'Stripe Subscription JSON object / message',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1
  ];
}

function civicrm_api3_we_act_StripeSubscriptionImport($params) {
    return _we_act_process_message(
        'CRM_WeAct_Action_StripeSubscriptionImport', 
        $params['message']
    );
}
