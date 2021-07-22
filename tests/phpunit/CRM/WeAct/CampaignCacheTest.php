<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * @group headless
 */
class CRM_WeAct_CampaignCacheTest extends CRM_WeAct_BaseTest {

  public function setUp() : void {
    parent::setUp();
  }

  public function buildCache($guzzleClient) {
    return new CRM_WeAct_CampaignCache(Civi::cache(), $guzzleClient);
  }

  public function testProcaCampaignNew() {
    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction();
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals($campaign['name'], 'fund/us');
    $this->assertEquals($campaign['external_identifier'], 'proca_3');
    $extra_campaign = civicrm_api3('Campaign', 'getsingle', ['id' => $campaign['id'], 'return' => [$camp_cache->settings->customFields['campaign_language']]]);
    $this->assertEquals($extra_campaign[$camp_cache->settings->customFields['campaign_language']], 'pl_PL');
  }

  public function testProcaCampaignFromMailing() {
    $mailing_result = civicrm_api3('Mailing', 'create', [
      'campaign_id' => $this->campaignId
    ]);
    $mailingId = $mailing_result['id'];

    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction(CRM_WeAct_Action_ProcaTest::utmTracking("civimail-$mailingId"));
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($this->campaignId, $campaign['id']);
  }

  public function testProcaCampaignFromMailingUpdated() {
    $mailing_result = civicrm_api3('Mailing', 'create', [
      'campaign_id' => $this->campaignId
    ]);
    $mailingId = $mailing_result['id'];

    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction(CRM_WeAct_Action_ProcaTest::utmTracking("civimail-$mailingId"));
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($campaign['title'], 'Transient campaign');
    civicrm_api3('Campaign', 'create', ['id' => $this->campaignId, 'title' => 'Updated transient campaign']);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals('Updated transient campaign', $campaign['title']);
  }

  public function testProcaCampaignFromSpeakoutNew() {
    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction(CRM_WeAct_Action_ProcaTest::speakoutTracking());
    define('CIVICRM_SPEAKOUT_USERS', ['speakout' => ['email' => 'api@speakout', 'password' => 'p4ss']]);
    $mockHandler = new MockHandler([
      new Response(200, [], CRM_WeAct_SpeakoutTest::simpleEnglishPetitionJSON(666)),
      new Response(200, [], CRM_WeAct_SpeakoutTest::parentCampaignJSON(665, 'some-speakout-parent')),
    ]);
    $mockStack = HandlerStack::create($mockHandler);
    $camp_cache = $this->buildCache(new Client(['handler' => $mockStack]));
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals(666, $campaign['external_identifier']);
  }

  public function testHoudiniCampaignNew() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffStripeAction();
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals($campaign['name'], 'something-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
    $extra_campaign = civicrm_api3('Campaign', 'getsingle', ['id' => $campaign['id'], 'return' => [$camp_cache->settings->customFields['campaign_language']]]);
    $this->assertEquals($extra_campaign[$camp_cache->settings->customFields['campaign_language']], 'pl_PL');
  }

  public function testHoudiniCampaignExisting() {
    $campaign_result = civicrm_api3('Campaign', 'create', [
      'name' => 'existing-PL',
      'title' => 'existing',
      'external_identifier' => 'cc_42'
    ]);
    $action = CRM_WeAct_Action_HoudiniTest::oneoffStripeAction();
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($campaign['id'], $campaign_result['id']);
    $this->assertEquals($campaign['name'], 'existing-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
  }


}
