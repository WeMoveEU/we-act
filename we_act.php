<?php

require_once 'we_act.civix.php';
// phpcs:disable
use CRM_WeAct_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function we_act_civicrm_config(&$config) {
  _we_act_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_xmlMenu
 */
function we_act_civicrm_xmlMenu(&$files) {
  _we_act_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function we_act_civicrm_install() {
  _we_act_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
 */
function we_act_civicrm_postInstall() {
  _we_act_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
 */
function we_act_civicrm_uninstall() {
  _we_act_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function we_act_civicrm_enable() {
  _we_act_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
 */
function we_act_civicrm_disable() {
  _we_act_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_upgrade
 */
function we_act_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _we_act_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function we_act_civicrm_managed(&$entities) {
  _we_act_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function we_act_civicrm_caseTypes(&$caseTypes) {
  _we_act_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function we_act_civicrm_angularModules(&$angularModules) {
  _we_act_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function we_act_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _we_act_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function we_act_civicrm_entityTypes(&$entityTypes) {
  _we_act_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function we_act_civicrm_themes(&$themes) {
  _we_act_civix_civicrm_themes($themes);
}

function we_act_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'Campaign' && $op == 'edit') {
    $entry = civicrm_api3('Campaign', 'getsingle', ['id' => $objectId]);
    $key = "WeAct:Campaign:{$objectId}";
    $cache = new CRM_WeAct_CampaignCache(Civi::cache(), NULL);
    $cache->setCiviCampaign($entry);
  }
}
