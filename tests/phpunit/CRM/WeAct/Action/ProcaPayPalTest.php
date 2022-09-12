<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_WeAct_Action_ProcaPayPalTest extends CRM_WeAct_Action_ProcaTest {


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


  public function testPayPalWeekly() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/paypal-weekly.json'
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

    $this->verifyWeekly($recurring, $proca_event->action->customFields->weeklyAmount);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $test_contact = $proca_event->contact;
    $this->assertEquals($contact['last_name'], $test_contact->lastName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $this->verifyUTMS($proca_event->tracking, $contribution, $recurring);
  }

}
