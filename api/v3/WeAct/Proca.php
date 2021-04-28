<?php

require_once 'common.php'

/**
 * WeAct.Proca API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_we_act_Proca_spec(&$spec) {
  $spec['message'] = [
    'title' => 'Proca JSON message',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1
  ];
}

/**
 * WeAct.Proca API
 * Process an unencrypted Proca message
 * Return API result as expected by Rabbitizen
 */
function civicrm_api3_we_act_Proca($params) {
  return _we_act_process_message(CRM_WeAct_Action_Proca, $params['message']);
}
