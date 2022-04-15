
<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_WeAct_Action_ProcaTest extends CRM_WeAct_BaseTest {

  public function setUp(): void {
    // $import = new CRM_Utils_Migrate_Import();
    // $import->run('/home/aaron/cividev/drupal/sites/all/modules/civicrm/ext/commitcivi/xml/weekly_custom_fields.xml');
    parent::setUp();
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public static function process($proca_event) {
    $pt = new CRM_WeAct_Action_ProcaTest();
    return $pt->_process($proca_event);
  }

  // public function testPayPalRecurringPayment - see PayPalTest

  public function testTracking() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-oneoff.json'
      )
    );
    $utm = (object) [
      'source' => 'testing-source',
      'medium' => 'testing-medium',
      'campaign' => 'testing-campaign'
    ];
    $proca_event->tracking = $utm;
    $ret = $this->_process($proca_event);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals(
      $contribution[$this->settings->customFields['utm_source']],
      $utm->source
    );
    $this->assertEquals(
      $contribution[$this->settings->customFields['utm_medium']],
      $utm->medium
    );
    $this->assertEquals(
      $contribution[$this->settings->customFields['utm_campaign']],
      $utm->campaign
    );
  }

  public function testDetermineLanguage() {

    // 1. use the custom field is defined, it's set by the hosting page
    // 2. use the country to language mapping
    // 3. use the widget's language
    // 3. en_GB

    $message = json_decode(
      <<<JSON
   {
      "actionPage": {
          "locale": "DE"
      },
      "contact": {
          "country": "PL"
      },
      "action": {
          "customFields": {
              "language": "FR"
          }
      }
    }
JSON
    );

    # 1. custom field
    $this->assertEquals("fr_FR", CRM_WeAct_Action_Proca::determineLanguage($message));

    # 2. country
    unset($message->action->customFields->language);
    $this->assertEquals("pl_PL", CRM_WeAct_Action_Proca::determineLanguage($message));

    # 3. widget
    unset($message->contact->country);
    $this->assertEquals("de_DE", CRM_WeAct_Action_Proca::determineLanguage($message));

    # 4. fallback
    unset($message->actionPage->locale);
    $this->assertEquals("en_GB", CRM_WeAct_Action_Proca::determineLanguage($message));
  }


  public function testSpecialCaseEnglish() {

    // EN -> en_GB and not en_EN

    $message = json_decode(
      <<<JSON
   {
      "actionPage" : {},
      "contact": {},
      "action": {
          "customFields": {
              "language": "EN"
          }
      }
    }
JSON
    );

    $this->assertEquals("en_GB", CRM_WeAct_Action_Proca::determineLanguage($message));
  }

  // shared stuff

  public static function _process($json_msg) {
    $ret = civicrm_api3("Campaign", "create", ["title" => "Proca Test Campaign"]);
    $campaign_id = $ret['id'];

    $action = new CRM_WeAct_Action_Proca($json_msg);

    $json_msg->action->customFields->speakoutCampaign = $campaign_id;

    $processor = new CRM_WeAct_ActionProcessor();
    $contrib = $processor->process($action, $campaign_id);

    // maybe we can't get the return value and just have to hit the db

    return ['contrib' => $contrib, 'action' => $action];
  }
}
