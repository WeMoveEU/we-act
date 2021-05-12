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

  public function testHoudiniCampaignNew() {
    $action = new CRM_WeAct_Action_Houdini(
      CRM_WeAct_Action_HoudiniTest::singleStripeEvent()
    );
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
    $action = new CRM_WeAct_Action_Houdini(
      CRM_WeAct_Action_HoudiniTest::singleStripeEvent()
    );
    $processor = new CRM_WeAct_ActionProcessor();
    $campaign = $processor->getOrCreateCampaign($action);
    $this->assertEquals($campaign['id'], $campaign_result['id']);
    $this->assertEquals($campaign['name'], 'existing-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
  }

  public function testHoudiniContactNew() {
    $action = new CRM_WeAct_Action_Houdini(
      CRM_WeAct_Action_HoudiniTest::singleStripeEvent()
    );
    $processor = new CRM_WeAct_ActionProcessor();
    $contactId = $processor->getOrCreateContact($action, 66);
    $this->assertGreaterThan(0, $contactId);
    $this->assertConsentRequestSent();
  }

}
