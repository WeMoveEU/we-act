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

function _civicrm_api3_we_act_stripe_spec(&$spec) {
  $spec['id'] = [
    'title' => 'Stripe Subscription ID (sub_...)',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1
  ];
}

function civicrm_api3_we_act_stripe($params) {
    return _we_act_process_message(
        'CRM_WeAct_Action_StripeSubscriptionImport',
        $params['id']
    );
}
