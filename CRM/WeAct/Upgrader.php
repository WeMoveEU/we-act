<?php
use CRM_WeAct_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_WeAct_Upgrader extends CRM_WeAct_Upgrader_Base {

  public static $setRequiredSettings_version = 8;

  public function install() {
    $this->executeCustomDataFileByAbsPath($this->extensionDir . '/xml/campaign_fields.xml');
    $this->executeCustomDataFileByAbsPath($this->extensionDir . '/xml/campaign_types.xml');
  }

  /**
   * Callback for setUpHeadless, for settings values that cannot be set by the install process
   * but are required to be set by the extension
   * N.B.: may also be called by other extension tests that depend on we-act,
   * this is why this function is put here rather than in a test class: the class loader of other extension tests don't see this extension's test classes
   * Increase the version value in CRM_WeAct_BaseTest::setUpHeadless whenever modifying the callback so that the env builder detects the change
   */
  public static function setRequiredSettingsForTests($ctx) {
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'Credit Card', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'CommitChange-card', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'CommitChange-sepa', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'Proca-sepa', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'Proca-paypal', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('PaymentProcessor', 'create', ['name' => 'Paypal-button', 'payment_processor_type_id' => 'Dummy']);
    civicrm_api3('OptionValue', 'create', ['label' => 'Paypal', 'option_group_id' => 'payment_instrument']);
    civicrm_api3('OptionValue', 'create', ['label' => 'Stripe', 'option_group_id' => 'payment_instrument']);
    //Sepa extension creates a Dummy creditor on install, but it doesn't have a type
    civicrm_api3('Setting', 'create', ['batching_default_creditor' => 1]);

    civicrm_api3('OptionValue', 'create', ['option_group_id' => "email_greeting", 'description' => "pl_PL:", 'name' => "Dzień dobry"]);
    //The API does not seem to like receiving an array
    Civi::settings()->set('country_lang_mapping', ['PL' => 'pl_PL']);
  }

}
