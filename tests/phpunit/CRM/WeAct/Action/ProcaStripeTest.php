<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_WeAct_Action_ProcaStripeTest extends CRM_WeAct_Action_ProcaTest {

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
      $proca_event->action->donation->payload->subscriptionId
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

  public function testStripeWeekly() {
    $proca_event = json_decode(
     file_get_contents(
       'tests/proca-messages/stripe-weekly.json'
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
   $this->assertEquals($recurring['trxn_id'], $proca_event->action->donation->payload->subscriptionId);
   $this->assertEquals(
     $recurring['contribution_status_id'],
     $this->settings->contributionStatusIds['in progress']
   );

   $this->assertEquals($recurring['frequency_unit'], 'month'); // TODO: double check
   $this->assertEquals($recurring['frequency_interval'], 1);
   $this->assertEquals(
     substr($recurring['start_date'], 0, 10),
     substr($proca_event->action->createdAt, 0, 10)
   );

   $this->verifyWeekly($recurring, $proca_event->action->customFields->weeklyAmount);
   $this->verifyUTMS($proca_event->tracking, NULL, $recurring);

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

   $contact = civicrm_api3('Contact', 'getsingle', ["id" => $recurring['contact_id']]);
   $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
   $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

   $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
   $this->assertEquals($email['email'], $proca_event->contact->email);
  }

}
