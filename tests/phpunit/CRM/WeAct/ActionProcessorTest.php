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

  public function setUp() {
    parent::setUp();
    $contact_result = civicrm_api3('Contact', 'create', [
      'contact_type' => 'Individual', 'first_name' => 'Transient', 'last_name' => 'Contact'
    ]);
    $this->contactId = $contact_result['id'];
    $campaign_result = civicrm_api3('Campaign', 'create', [
      'campaign_type_id' => 1, 'title' => 'Transient campaign'
    ]);
    $this->campaignId = $campaign_result['id'];
  }

  public function testHoudiniCampaignNew() {
    $action = CRM_WeAct_Action_HoudiniTest::singleStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $campaign = $processor->getOrCreateCampaign($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals($campaign['name'], 'something-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
  }

  public function testHoudiniCampaignExisting() {
    $campaign_result = civicrm_api3('Campaign', 'create', [
      'name' => 'existing-PL',
      'title' => 'existing',
      'external_identifier' => 'cc_42'
    ]);
    $action = CRM_WeAct_Action_HoudiniTest::singleStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $campaign = $processor->getOrCreateCampaign($action);
    $this->assertEquals($campaign['id'], $campaign_result['id']);
    $this->assertEquals($campaign['name'], 'existing-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
  }

  public function testHoudiniContactNew() {
    $action = CRM_WeAct_Action_HoudiniTest::singleStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $contactId = $processor->getOrCreateContact($action, 66);
    $this->assertGreaterThan(0, $contactId);
    $this->assertConsentRequestSent();
  }

  public function testHoudiniStripeRecur() {
    $action = CRM_WeAct_Action_HoudiniTest::recurringStripeAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $get_recur = civicrm_api3('ContributionRecur', 'get', ['trxn_id' => 'cc_1']);
    $this->assertEquals($get_recur['count'], 1);
    $get_payment = civicrm_api3('Contribution', 'get', ['trxn_id' => 'ch_1NHwmdLnnERTfiJAMNHyFjAB']);
    $this->assertEquals($get_payment['count'], 1);
  }

  public function testHoudiniSepaOneoff() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffSepaAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $get_contrib = civicrm_api3('Contribution', 'get', ['trxn_id' => 'cc_100001', 'sequential' => 1]);
    $this->assertEquals($get_contrib['count'], 1);
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
