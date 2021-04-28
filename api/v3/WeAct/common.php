<?php

use CRM_WeAct_ExtensionUtil as E;

function _we_act_process_message($clazz, $message) {
  $json_msg = json_decode($message);
  if ($json_msg) {
    try {
      $action = new $clazz($json_msg);
      $processor = new CRM_WeAct_ActionProcessor();
      $processor->process($action);
      return civicrm_api3_create_success();
    } catch (CiviCRM_API3_Exception $ex) {
      $extraInfo = $ex->getExtraParams();
      $retry = strpos(CRM_Utils_Array::value('debug_information', $extraInfo), "try restarting transaction");
      return civicrm_api3_create_error(CRM_Core_Error::formatTextException($ex), ['retry_later' => $retry]);
    } catch (Exception $ex) {
      return civicrm_api3_create_error(CRM_Core_Error::formatTextException($ex), ['retry_later' => FALSE]);
    }
  }
  else {
    return civicrm_api3_create_error("Could not decode {$params['message']}", ['retry_later' => FALSE]);
  }
}