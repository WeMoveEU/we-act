<?php

require_once 'common.php';

/**
 * WeAct.Houdini API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_we_act_Houdini_spec(&$spec) {
  $spec['message'] = [
    'title' => 'Houdini JSON message',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1
  ];
}

/**
 * WeAct.Houdini API
 * Process an unencrypted Houdini message
 * Return API result as expected by Rabbitizen
 */
function civicrm_api3_we_act_Houdini($params) {
  return _we_act_process_message('CRM_WeAct_Action_Houdini', $params['message']);
}
