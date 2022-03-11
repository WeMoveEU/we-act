<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;



define('CIVICRM_SPEAKOUT_USERS', [
  'speakout' => ['email' => 'api@speakout', 'password' => 'p4ss'],
  'act.not-a2.eu' =>  ['email' => 'api@speakout', 'password' => 'p4ss']
]);

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
    return \Civi\Test::headless()
      ->install(['eu.wemove.gidipirus', 'eu.wemove.contributm', 'org.project60.sepa', 'mjwshared', 'com.drastikbydesign.stripe'])
      ->sql("UPDATE civicrm_sdd_creditor SET creditor_type = 'SEPA' WHERE creditor_type IS NULL")
      ->installMe(__DIR__)
      ->callback(function($ctx) {
        CRM_WeAct_Upgrader::setRequiredSettingsForTests($ctx);
      }, 8)
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

    $contact_result = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual', 'first_name' => 'Transient', 'last_name' => 'Contact'
    ]);
    $this->contactId = $contact_result['id'];

    $settings = CRM_WeAct_Settings::instance();
    $campaign_result = civicrm_api3('Campaign', 'create', [
      'campaign_type_id' => 1,
      'title' => 'Transient campaign',
      'external_identifier' => 42,
      $settings->customFields['campaign_slug'] => 'transient-campaign',
    ]);
    $this->campaignId = $campaign_result['id'];
  }

  public function assertConsentRequestSent() {
    $this->assertGreaterThan(0, count($this->consentRequests));
  }

  public function assertExists($entity, $filter) {
    $get_entity = civicrm_api3($entity, 'get', ['sequential' => 1] + $filter);
    $this->assertEquals(1, $get_entity['count']);
    return $get_entity['values'][0];
  }

}
