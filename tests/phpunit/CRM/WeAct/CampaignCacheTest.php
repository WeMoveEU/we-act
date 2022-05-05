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

  public function setUp(): void {
    parent::setUp();
  }

  public function buildCache($guzzleClient, $actionType = 'petition') {
    return new CRM_WeAct_CampaignCache(Civi::cache(), $guzzleClient, $actionType);
  }

  // Test that an event from Proca that is not connected to a Speakout Campaign
  // is created - "not connected" means no customFields.speakoutCampaign is set
  public function testProcaCampaignNew() {
    $event  = CRM_WeAct_Action_ProcaMessageFactory::oneoffStripeAction();

    unset($event->action->customFields->speakoutCampaign);

    $action = new CRM_WeAct_Action_Proca($event);
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals($campaign['name'], 'birds/minimumbasicseeds');
    $this->assertEquals($campaign['external_identifier'], 'proca_1');
    $extra_campaign = civicrm_api3('Campaign', 'getsingle', ['id' => $campaign['id'], 'return' => [$camp_cache->settings->customFields['campaign_language']]]);
    $this->assertEquals(
      $extra_campaign[$camp_cache->settings->customFields['campaign_language']], 'en_GB');
  }

  public function testProcaCampaignFromMailing() {
    $mailing_result = civicrm_api3('Mailing', 'create', [
      'campaign_id' => $this->campaignId
    ]);
    $mailingId = $mailing_result['id'];

    $proca_event = CRM_WeAct_Action_ProcaMessageFactory::oneoffStripeAction();

    $utm = (object) [
      'source' => "civimail-$mailingId",
      'medium' => 'testing-medium',
      'campaign' => 'testing-campaign'
    ];
    $proca_event->tracking = $utm;

    $action = new CRM_WeAct_Action_Proca($proca_event);

    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($this->campaignId, $campaign['id']);
  }

  public function testProcaCampaignFromMailingUpdated() {
    $mailing_result = civicrm_api3('Mailing', 'create', [
      'campaign_id' => $this->campaignId
    ]);
    $mailingId = $mailing_result['id'];

    $proca_event = CRM_WeAct_Action_ProcaMessageFactory::oneoffStripeAction();

    $utm = (object) [
      'source' => "civimail-$mailingId",
      'medium' => 'testing-medium',
      'campaign' => 'testing-campaign'
    ];
    $proca_event->tracking = $utm;

    $action = new CRM_WeAct_Action_Proca($proca_event);

    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($campaign['title'], 'Transient campaign');

    civicrm_api3('Campaign', 'create', [
      'id' => $this->campaignId,
      'title' => 'Updated transient campaign',
      $camp_cache->settings->customFields['campaign_slug'] => 'updated-transient-campaign',
    ]);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals('Updated transient campaign', $campaign['title']);
    $this->assertEquals('updated-transient-campaign', $campaign[$camp_cache->settings->customFields['campaign_slug']]);
  }

  public function testProcaCampaignFromSpeakoutNew() {
    $proca_event = CRM_WeAct_Action_ProcaMessageFactory::oneoffStripeAction();

    $utm = (object) [
      "campaign" => "unit-tests",
      "source" => "code",
      "medium" => "phpunit",
      "location" => "https://speakout/campaigns/foo"
    ];
    $proca_event->tracking = $utm;
    $proca_event->action->customFields->speakoutCampaign = '1339';

    $action = new CRM_WeAct_Action_Proca($proca_event);

    define('CIVICRM_SPEAKOUT_USERS', ['speakout' => ['email' => 'api@speakout', 'password' => 'p4ss']]);
    $mockHandler = new MockHandler([
      new Response(200, [], CRM_WeAct_SpeakoutTest::simpleEnglishPetitionJSON(1339)),
      new Response(200, [], CRM_WeAct_SpeakoutTest::parentCampaignJSON(
        1338,
        'some-speakout-parent'
      )),
    ]);
    $mockStack = HandlerStack::create($mockHandler);
    $camp_cache = $this->buildCache(new Client(['handler' => $mockStack]));
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals(1339, $campaign['external_identifier']);
  }

  // Test that an event from Proca that doesn't have
  // customFields.speakoutCampaign set is still connected to the existing
  // speakout campaign using other fields.
  public function testProcaCampaignFromSpeakoutExistingWithoutTracking() {
    $proca_event = CRM_WeAct_Action_ProcaMessageFactory::oneoffStripeAction();

    unset($proca_event->customFields->speakoutCampaign);
    $proca_event->tracking = [];

    $action = new CRM_WeAct_Action_Proca($proca_event);

    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertEquals($this->campaignId, $campaign['id']);
  }

  public function testHoudiniCampaignNew() {
    $action = CRM_WeAct_Action_HoudiniTest::oneoffStripeAction();
    $camp_cache = $this->buildCache(NULL);
    $campaign = $camp_cache->getFromAction($action);
    $this->assertGreaterThan(0, $campaign['id']);
    $this->assertEquals($campaign['name'], 'something-PL');
    $this->assertEquals($campaign['external_identifier'], 'cc_42');
    $extra_campaign = civicrm_api3(
      'Campaign', 'getsingle', [
        'id' => $campaign['id'],
        'return' => [
          $camp_cache->settings->customFields['campaign_language']
        ]
    ]);
    $this->assertEquals(
      $extra_campaign[$camp_cache->settings->customFields['campaign_language']],
      'pl_PL'
    );
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
