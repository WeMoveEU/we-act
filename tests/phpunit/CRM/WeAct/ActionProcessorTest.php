<?php

use CRM_WeAct_ExtensionUtil as E;

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
  }

  public function frequencyProvider() {
    return [['monthly', 'month'], ['weekly', 'week'], ['daily', 'day']];
  }

  public function isTestProvider() {
    return [[0], [1]];
  }

  public function testHoudiniContactNew() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $contactId = $processor->getOrCreateContact($action, 66);
    $this->assertGreaterThan(0, $contactId);
    $this->assertConsentRequestSent();
  }

  public function testHoudiniStripeRecur() {
    $action = CRM_WeAct_Action_HoudiniTest::recurringStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertExists('ContributionRecur', ['trxn_id' => 'cc_1']);
    $this->assertExists('Contribution', ['trxn_id' => 'ch_1NHwmdLnnERTfiJAMNHyFjAB']);
  }

  public function testHoudiniSepaOneoff() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertExists('Contribution', ['trxn_id' => 'cc_100001']);
    $this->assertExists('SepaMandate', ['iban' => 'PL83101010230000261395100000']);
  }

  public function testHoudiniSepaRecur() {
    $action = CRM_WeAct_Action_HoudiniTest::recurringSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    //Created on 13th means the cycle day should be 21st
    $this->assertExists('ContributionRecur', ['trxn_id' => 'ccr_100001', 'cycle_day' => 21]);
    $this->assertExists('SepaMandate', ['iban' => 'PL83101010230000261395100000']);
  }

  public function testPaypalImportOneoff() {
    $action = CRM_WeAct_Action_PaypalTransactionTest::oneoffAction('1MP0RT3D1D');
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $this->assertExists('Contribution', ['trxn_id' => '1MP0RT3D1D']);
  }

}
