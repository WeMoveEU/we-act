
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
    parent::setUp();

    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function testCard() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/stripe-oneoff.json'
      )
    );
    $ret = $this->_process($proca_event);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals($contribution['currency'], 'EUR');
    $this->assertEquals(
      $contribution['total_amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals(
      $contribution['trxn_id'],
      $proca_event->action->donation->payload->paymentConfirm->id
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['completed']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      NULL
    );

    // payment token?
    // customer ?
    // Payment? Transaction? What other records are created?

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $test_contact = $proca_event->contact;
    $this->assertEquals($contact['last_name'], $test_contact->lastName);

    // test in other places?

    $address = civicrm_api3('Address', 'getsingle', ["contact_id" => $contact['id']]);
    $this->assertEquals($address['postal_code'], $test_contact->postcode);
    $this->assertEquals(
      $this->settings->countryIds[$test_contact->country],
      $address['country_id']
    );

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);
  }


  public function testSEPA() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-oneoff.json'
      )
    );
    $ret = $this->_process($proca_event);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals(
      $contribution['currency'],
      'EUR'
    );
    $this->assertEquals(
      $contribution['total_amount'],
      number_format(
        $proca_event->action->donation->amount / 100,
        2
      )
    );
    $this->assertEquals(
      $contribution['trxn_id'],
      "proca_" . $proca_event->actionId
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      NULL
    );

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3('SepaMandate', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $contribution['id']]);
    $this->assertEquals($mandate['type'], 'OOFF');
    $this->assertEquals($mandate['iban'], $proca_event->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }

  public function testSEPARecurring() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-monthly.json'
      )
    );
    $ret = $this->_process($proca_event);
    $contribution = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    // print(json_encode($ret['contrib'], JSON_PRETTY_PRINT));

    $this->assertEquals($contribution['currency'], 'EUR');
    $this->assertEquals(
      $contribution['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals($contribution['trxn_id'], "proca_" . $proca_event->actionId);
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );

    $this->assertEquals($contribution['frequency_unit'], 'month');
    $this->assertEquals($contribution['frequency_interval'], 1);
    $this->assertEquals(
      substr($contribution['start_date'], 0, 10),
      substr($proca_event->action->createdAt, 0, 10)
    ); // Hrm, not sure?

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3(
      'SepaMandate',
      'getsingle',
      [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => $contribution['id']
      ]
    );
    $this->assertEquals($mandate['type'], 'RCUR');
    $this->assertEquals($mandate['iban'], $proca_event->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }


  public function testTracking() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-oneoff.json'
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

    $message = json_decode(<<<JSON
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

    $message = json_decode(<<<JSON
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
