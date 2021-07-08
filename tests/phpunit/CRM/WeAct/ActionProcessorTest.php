<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
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
class CRM_WeAct_ActionProcessorTest extends CRM_WeAct_BaseTest {

  public function setUp() : void {
    parent::setUp();
    $contact_result = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual', 'first_name' => 'Transient', 'last_name' => 'Contact'
    ]);
    $this->contactId = $contact_result['id'];

    $campaign_result = civicrm_api3('Campaign', 'create', [
      'campaign_type_id' => 1, 'title' => 'Transient campaign', 'external_identifier' => 42
    ]);
    $this->campaignId = $campaign_result['id'];
  }

  public function testHoudiniContactNew() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $contactId = $processor->getOrCreateContact($action, 66);
    $this->assertGreaterThan(0, $contactId);
    $this->assertConsentRequestSent();
  }

  public function assertContribution($trxn_id) {
    $get_payment = civicrm_api3('Contribution', 'get', ['trxn_id' => $trxn_id]);
    $this->assertEquals($get_payment['count'], 1);
  }

  public function testProcaStripeOneoff() {
    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertContribution('pi_somegarbage');
  }

  public function testProcaStripeRecur() {
    $sub_id = 'sub_scription';
    $action = CRM_WeAct_Action_ProcaTest::recurringStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $get_recur = civicrm_api3('ContributionRecur', 'get', ['trxn_id' => $sub_id]);
    $this->assertEquals($get_recur['count'], 1);
    $this->assertContribution('pi_somegarbage');
    $get_customer = civicrm_api3('StripeCustomer', 'get', ['contact_id' => $this->contactId]);
    $this->assertEquals($get_customer['count'], 1);
  }

  public function testProcaSepaOneoff() {
    $action = CRM_WeAct_Action_ProcaTest::oneoffSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertContribution('proca_5');
    $get_mandate = civicrm_api3('SepaMandate', 'get', ['iban' => 'PL83101010230000261395100000']);
    $this->assertEquals($get_mandate['count'], 1);
  }

  public function testProcaPaypalOneoff() {
    $action = CRM_WeAct_Action_ProcaTest::oneoffPaypalAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertContribution('S0M31D');
  }

  public function testHoudiniStripeRecur() {
    $action = CRM_WeAct_Action_HoudiniTest::recurringStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $get_recur = civicrm_api3('ContributionRecur', 'get', ['trxn_id' => 'cc_1']);
    $this->assertEquals($get_recur['count'], 1);
    $this->assertContribution('ch_1NHwmdLnnERTfiJAMNHyFjAB');
  }

  public function testHoudiniSepaOneoff() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertContribution('cc_100001');
    $get_mandate = civicrm_api3('SepaMandate', 'get', ['iban' => 'PL83101010230000261395100000']);
    $this->assertEquals($get_mandate['count'], 1);
  }

  public function testHoudiniSepaRecur() {
    $action = CRM_WeAct_Action_HoudiniTest::recurringSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $get_recur = civicrm_api3('ContributionRecur', 'get', ['trxn_id' => 'ccr_100001', 'sequential' => 1]);
    $this->assertEquals($get_recur['count'], 1);
    $this->assertEquals($get_recur['values'][0]['cycle_day'], 21); //Created on 13th
    $get_mandate = civicrm_api3('SepaMandate', 'get', ['iban' => 'PL83101010230000261395100000']);
    $this->assertEquals($get_mandate['count'], 1);
  }

}
