<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Base class for tests with common set-up
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
abstract class CRM_WeAct_BaseTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    $build_version = 7;  //Increase this value whenever modifying the callback so that the env builder detects the change
    return \Civi\Test::headless()
      ->install(['eu.wemove.gidipirus', 'eu.wemove.contributm', 'org.project60.sepa', 'mjwshared', 'com.drastikbydesign.stripe'])
      ->callback(function($ctx) {
        civicrm_api3('PaymentProcessor', 'create', ['name' => 'Credit Card', 'payment_processor_type_id' => 'Dummy']);
        civicrm_api3('PaymentProcessor', 'create', ['name' => 'CommitChange-card', 'payment_processor_type_id' => 'Dummy']);
        civicrm_api3('PaymentProcessor', 'create', ['name' => 'CommitChange-sepa', 'payment_processor_type_id' => 'Dummy']);
        civicrm_api3('PaymentProcessor', 'create', ['name' => 'Proca-sepa', 'payment_processor_type_id' => 'Dummy']);
        civicrm_api3('PaymentProcessor', 'create', ['name' => 'Proca-paypal', 'payment_processor_type_id' => 'Dummy']);
        civicrm_api3('OptionValue', 'create', ['label' => 'Paypal', 'option_group_id' => 'payment_instrument']);
        civicrm_api3('OptionValue', 'create', ['label' => 'Stripe', 'option_group_id' => 'payment_instrument']);
        //Sepa extension creates a Dummy creditor on install, but it doesn't have a type
        civicrm_api3('Setting', 'create', ['batching_default_creditor' => 1]);
      }, $build_version)
      ->sql("UPDATE civicrm_sdd_creditor SET creditor_type = 'SEPA' WHERE creditor_type IS NULL")
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() : void {
    parent::setUp();
    $consentRequests = [];
    $this->consentRequests = &$consentRequests;

		$this->apiKernel = \Civi::service('civi_api_kernel');
		$this->adhocProvider = new \Civi\API\Provider\AdhocProvider(3, 'Gidipirus');
		$this->apiKernel->registerApiProvider($this->adhocProvider);
		$this->adhocProvider->addAction('send_consent_request', 'access CiviCRM',
			function ($apiRequest) use (&$consentRequests) {
        $consentRequests[] = $apiRequest;
				return civicrm_api3_create_success(TRUE);
			}
		);

    Civi::settings()->set('country_lang_mapping', ['PL' => 'pl_PL']);
    civicrm_api3('OptionValue', 'create', [
      'option_group_id' => "email_greeting",
      'description' => "pl_PL:",
      'name' => "Dzień dobry",
    ]);

    $contact_result = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual', 'first_name' => 'Transient', 'last_name' => 'Contact'
    ]);
    $this->contactId = $contact_result['id'];

    $campaign_result = civicrm_api3('Campaign', 'create', [
      'campaign_type_id' => 1, 'title' => 'Transient campaign', 'external_identifier' => 42
    ]);
    $this->campaignId = $campaign_result['id'];
  }

  public function assertConsentRequestSent() {
    $this->assertGreaterThan(0, count($this->consentRequests));
  }

  public function assertContributionExists($filter) {
    $get_payment = civicrm_api3('Contribution', 'get', ['sequential' => 1] + $filter);
    $this->assertEquals($get_payment['count'], 1);
    return $get_payment['values'][0];
  }

}
