
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

  public static function process($proca_event) {
    $pt = new CRM_WeAct_Action_ProcaTest();
    return $pt->_process($proca_event);
  }

  public function testStripe() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/stripe-oneoff.json'
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

    $this->verifyUTMS($proca_event->tracking, $contribution);
  }

  public function testStripeMonthly() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/stripe-monthly.json'
      )
    );
    $ret = $this->_process($proca_event);

    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    $contribution = civicrm_api3('Contribution', 'getsingle', ["contribution_recur_id" => $ret['contrib']['id']]);

    $this->assertEquals($recurring['currency'], 'EUR');
    $this->assertEquals(
      $recurring['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals(
      $recurring['trxn_id'],
      $proca_event->action->donation->payload->paymentIntent->response->latest_invoice->lines->data[0]->subscription
    );
    $this->assertEquals(
      $recurring['contribution_status_id'],
      $this->settings->contributionStatusIds['in progress']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      $recurring['id']
    );

    $this->assertEquals(
      $contribution['total_amount'],
      $recurring['amount']
    );
    $this->assertEquals(
      $contribution['trxn_id'],
      $proca_event->action->donation->payload->paymentIntent->response->latest_invoice->id
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['completed']
    );
    $this->assertEquals(
      $recurring['frequency_unit'],
      'month',
    );
    $this->assertEquals(
      $recurring['frequency_interval'],
      1
    );

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

    $this->verifyUTMS($proca_event->tracking, $contribution, $recurring);

    // OK, I know this is a very long test, but ... now test a payment can be processed.

    $stripe_message = json_decode(file_get_contents(
      'tests/proca-messages/stripe-monthly-payment.json'
    ));

    // XXX: is_test is fucking us here - IPN doesn't find the contribution, but
    // that's not all. it's very hard to figure out how to test here. So just
    // try it in staging not in testing.

    // And try out the new endpoint already.

    $page = new CRM_WeAct_Page_Stripe();
    $created = $page->processNotification($stripe_message);

    $this->assertTrue($created != NULL);
//    $payment = $stripe_message->data->object;

    // is payment there?
    // does it have tracking?
  }

  public function testSEPA() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-oneoff.json'

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

    // $this->verifyUTMS()
  }

  public function testSEPAMonthly() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-monthly.json'
      )
    );
    $ret = $this->_process($proca_event);
    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    // print(json_encode($ret['contrib'], JSON_PRETTY_PRINT));

    $this->assertEquals($recurring['currency'], 'EUR');
    $this->assertEquals(
      $recurring['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals($recurring['trxn_id'], "proca_" . $proca_event->actionId);
    $this->assertEquals(
      $recurring['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );

    $this->assertEquals($recurring['frequency_unit'], 'month');
    $this->assertEquals($recurring['frequency_interval'], 1);
    $this->assertEquals(
      substr($recurring['start_date'], 0, 10),
      substr($proca_event->action->createdAt, 0, 10)
    ); // Hrm, not sure?

    $this->verifyUTMS($proca_event->tracking, NULL, $recurring);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $recurring['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3(
      'SepaMandate',
      'getsingle',
      [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => $recurring['id']
      ]
    );
    $this->assertEquals($mandate['type'], 'RCUR');
    $this->assertEquals($mandate['iban'], $proca_event->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }

  public function testPayPalOneOff() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/paypal-oneoff.json'
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
      $proca_event->action->donation->payload->response->orderID
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['completed']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      NULL
    );

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $test_contact = $proca_event->contact;
    $this->assertEquals($contact['last_name'], $test_contact->lastName);

    // test in other places?

    $address = civicrm_api3('Address', 'getsingle', ["contact_id" => $contact['id']]);
    // no postal code with paypal...
    // $this->assertEquals($address['postal_code'], $test_contact->postcode);
    $this->assertEquals(
      $this->settings->countryIds[$test_contact->country],
      $address['country_id']
    );

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $this->verifyUTMS($proca_event->tracking, $contribution);
  }

  public function testPayPalMonthly() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/paypal-monthly.json'
      )
    );
    $ret = $this->_process($proca_event);

    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    $contribution = civicrm_api3('Contribution', 'getsingle', ["contribution_recur_id" => $ret['contrib']['id']]);

    $this->assertEquals($recurring['currency'], 'EUR');
    $this->assertEquals(
      $recurring['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals(
      $recurring['trxn_id'],
      $proca_event->action->donation->payload->response->subscriptionID
    );
    $this->assertEquals(
      $recurring['contribution_status_id'],
      $this->settings->contributionStatusIds['in progress']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      $recurring['id']
    );

    $this->assertEquals(
      $contribution['total_amount'],
      $recurring['amount']
    );
    $this->assertEquals(
      $recurring['frequency_unit'],
      'month',
    );
    $this->assertEquals(
      $recurring['frequency_interval'],
      1
    );
    $this->assertEquals(
      $contribution['trxn_id'],
      $proca_event->action->donation->payload->response->orderID
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['completed']
    );

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $test_contact = $proca_event->contact;
    $this->assertEquals($contact['last_name'], $test_contact->lastName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $this->verifyUTMS($proca_event->tracking, $contribution, $recurring);
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

  public function testContactNew() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/stripe-oneoff.json'
      )
    );
    $this->_process($proca_event);
    // $this->assertGreaterThan(0, $contactId);
    $this->assertConsentRequestSent();
  }

  public function testContactExisting() {

    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/stripe-oneoff.json'
      )
    );

    $contact = civicrm_api3('Contact', 'create', [
      'email' => $proca_event->contact->email,  // matches test Stripe action
      'contact_type' => 'Individual'
    ]);
    civicrm_api3('GroupContact', 'create', [ 'contact_id' => $contact['id'], 'group_id' => $this->groupId ]);


    $this->_process($proca_event);
    $this->assertConsentRequestNotSent();
  }

  public function testDeprecated() {
    civicrm_api3("WeAct", "Proca", [ "message" => "Hey!"] );
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
