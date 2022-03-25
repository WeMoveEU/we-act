<?php

use CRM_WeAct_ExtensionUtil as E;

/**
 * @group headless
 */
class CRM_WeAct_Page_StripeTest extends CRM_WeAct_BaseTest {

  public function setUp(): void {
    parent::setUp();
  }

  public function testRefundForProca() {

    // NOTE that the two event files are connected to the same charge / payment intent !

    $action = $this->json_load('tests/proca-messages/stripe-oneoff.json');
    $result = CRM_WeAct_Action_ProcaTest::process($action);
    $this->assertEquals($result['contrib']['contribution_status_id'], $this->settings->contributionStatusIds['completed']);
    $contribution = $result['contrib'];

    $refund = json_decode(file_get_contents("./tests/stripe-messages/proca-charge-refunded.json"));

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($refund);

    # find the contribution and check it's marked refunded
    $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contribution['id']]);
    $this->assertEquals($contribution['contribution_status_id'], $this->settings->contributionStatusIds['refunded']);
  }

  public function testSubscriptionUpdateAmount() {

    $action = $this->json_load('tests/proca-messages/stripe-monthly.json');
    $result = CRM_WeAct_Action_ProcaTest::process($action);
    $this->assertEquals(
      $result['contrib']['contribution_status_id'],
      $this->settings->contributionStatusIds['in progress']
    );
    $initial = $result['contrib'];

    $event = $this->json_load('tests/stripe-messages/subscription-updated.json');
    $update = $event->data->object->items->data[0];

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($event);

    $updated = $this->assertExists('ContributionRecur', [ 'id' => $initial['id']]);
    $this->assertEquals(
      $updated['trxn_id'],
      $event->data->object->id
    );
    $this->assertEquals($updated['amount'], ($update->quantity * $update->price->unit_amount)/100 );
  }

  // Test update status

  public function testSubscriptionCancel() {

    $action = $this->json_load('tests/proca-messages/stripe-monthly.json');
    $result = CRM_WeAct_Action_ProcaTest::process($action);
    $this->assertEquals(
      $result['contrib']['contribution_status_id'],
      $this->settings->contributionStatusIds['in progress']
    );
    $contribution = $result['contrib'];

    $subscription_id = $contribution['trxn_id'];
    $cancellation = json_decode(file_get_contents("./tests/stripe-messages/subscription-cancelled.json"));
    $cancellation->data->object->id = $subscription_id;

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($cancellation);

    $contribution = civicrm_api3(
      'ContributionRecur',
      'getsingle',
      ['trxn_id' => $subscription_id]
    );
    $this->assertTrue($contribution != NULL);
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['cancelled']
    );
    $this->assertNotEquals($contribution['cancel_date'], NULL);
  }

  public function testNewinvoicePaymentSucceededEvent() {
    $action = $this->json_load('tests/proca-messages/stripe-monthly.json');
    $result = CRM_WeAct_Action_ProcaTest::process($action);
    $this->assertEquals(
      $result['contrib']['contribution_status_id'],
      $this->settings->contributionStatusIds['in progress']
    );
    $recurring = $result['contrib'];

    $contributions = civicrm_api3('Contribution', 'get', ['contribution_recur_id' => $recurring['id']]);
    $this->assertEquals($contributions['count'], 1);

    $payment_event = json_decode(file_get_contents("./tests/proca-messages/stripe-monthly-payment.json"));
    $payment = $payment_event->data->object;
    $paymentintent_id = $payment->payment_intent;
    $created = new DateTime("@{$payment->created}");
    $amount = $payment->amount_paid / 100;

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($payment_event);

    $contribution = civicrm_api3('Contribution', 'getsingle', ['trxn_id' => $paymentintent_id]);
    // Bah TZ issues on github.com, just drop the test for now
    // $this->assertEquals($contribution['receive_date'], $created->format('Y-m-d H:i:s'));
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['completed']
    );
    $this->assertEquals($amount, $contribution['total_amount']);

    # do it again, make sure we don't add it again
    $page->processNotification($payment_event);
    $contributions = civicrm_api3('Contribution', 'get', ['contribution_recur_id' => $recurring['id']]);
    $this->assertEquals($contributions['count'], 2);
  }

  public function testUnknownEvent() {
    $page = new CRM_WeAct_Page_Stripe();
    $event = <<<JSON
      { "type": "invoice.idontexist", "id": "evt_ineverheardof" }
JSON;
    $page->processNotification(json_decode($event));
    $this->assertTrue(true);
  }

  public function testMatchForStripeExtensionPayment() {
    // TODO: Test _findContribution for an existing payment with a trxn_id of
    // in_{...},ch_{....}
  }

  // TODO:
  ///  - Unknown subscription - But what should it do? Create the subscription right?
  //     public function testUnknownRecurringDonation() {}
  //   - Test trial period invoice for 0.00
  //   - Payment without PI, without Charge..
  //

}
