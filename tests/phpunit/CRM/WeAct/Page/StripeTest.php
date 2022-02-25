<?php

use CRM_WeAct_ExtensionUtil as E;

/**
 * @group headless
 */
class CRM_WeAct_Page_StripeTest extends CRM_WeAct_BaseTest {

  public function setUp(): void {
    parent::setUp();
  }

  public function testRefund() {
    // as long as we're using Proca to get the first donation, be sure
    // to test using that processor!!

    $action = CRM_WeAct_Action_ProcaTest::oneoffStripeAction();

    $charge_id = 'ch_vuhQqUhzksniV';
    $action->details->paymentId = $charge_id;

    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation(
      $action,
      $this->campaignId,
      $this->contactId
    );

    $refund = json_decode(file_get_contents("./tests/phpunit/events/charge-refunded.json"));
    $refund->data->object->id = $charge_id;

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($refund);

    # find the contribution and check it's marked refunded

    $contribution = civicrm_api3('Contribution', 'getsingle', ['trxn_id' => $charge_id]);
    $this->assertTrue($contribution != NULL);
    $this->assertEquals($contribution['contribution_status_id'], 7);
  }

  public function testSubscriptionCreate() {

    // TODO : test with a create customer

    // # create the customer
    $customer_event = json_decode(file_get_contents("./tests/phpunit/events/customer.json"));
    $page = new CRM_WeAct_Page_Stripe();
    $contact = $page->processNotification($customer_event);

    $contact_id = $contact['id'];
    $_ENV['testing_contact_id'] = $contact_id;

    # create the contribution_recur (and the contact if needed, which it is here)
    $subscription_event = json_decode(file_get_contents("./tests/phpunit/events/subscription.json"));
    $page->processNotification($subscription_event);

    $subscription = $subscription_event->data->object;
    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $subscription->id]);
    $this->assertTrue($recurring != NULL);
    $this->assertEquals($recurring['contact_id'], $contact_id);

    $charge = civicrm_api3(
      'Contribution',
      'getsingle',
      ['contribution_recur_id' => $recurring['id']]
    );
    $this->assertEquals($charge['contact_id'], $contact_id);
    $this->assertEquals(
        substr($charge['trxn_id'], 0, 3), 'ch_'
      );

    unset($_ENV['testing_contact_id']);
  }

  public function testSubscriptionUpdateAmount() {
    $subscription = "sub_uqWHvXgyuwzTQ";
    $amount = 2000;
    $action = CRM_WeAct_Action_ProcaTest::recurringStripeAction(
      'monthly',
      null,
      $subscription,
      $amount
    );

    // print(json_encode($action)); # // details->amount

    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $contribution = civicrm_api3(
      'ContributionRecur',
      'getsingle',
      ['trxn_id' => $subscription]
    );
    $this->assertEquals("20.00", $contribution['amount']);

    $amount = 4000;

    $stripe_event = $this->customerSubscriptionUpdatedEvent(
      $subscription,
      $amount,
    );

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification(json_decode($stripe_event));

    $contribution = civicrm_api3(
      'ContributionRecur',
      'getsingle',
      ['trxn_id' => $subscription]
    );

    $this->assertEquals("40.00", $contribution['amount']);
  }

  // Test update status

  public function testSubscriptionCancel() {

    $subscription = "sub_1KQYMRLEJyfuWvBB831StfM3";
    $amount = 1400;
    $action = CRM_WeAct_Action_ProcaTest::recurringStripeAction(
      'monthly',
      null,
      $subscription,
      $amount
    );

    // print(json_encode($action)); # // details->amount

    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $contribution = civicrm_api3(
      'ContributionRecur',
      'getsingle',
      ['trxn_id' => $subscription]
    );
    $this->assertEquals("14.00", $contribution['amount']);

    $cancellation = json_decode(file_get_contents("./tests/phpunit/events/subscription-cancelled.json"));
    $cancellation->data->object->id = $subscription;

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification($cancellation);

    $contribution = civicrm_api3(
      'ContributionRecur',
      'getsingle',
      ['trxn_id' => $subscription]
    );
    $this->assertTrue($contribution != NULL);
    $this->assertEquals($contribution['contribution_status_id'], 3);
    $this->assertNotEquals($contribution['cancel_date'], NULL);
  }

  public function testNewinvoicePaymentSucceededEvent() {
    $subscription = "sub_AccmDyDhCXVvJtXf";
    $action = CRM_WeAct_Action_ProcaTest::recurringStripeAction('monthly', null, $subscription);
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $invoice = "in_sZJpHDMwEnahHziF";
    $charge = "ch_rgWvkHQrADdcgPdY";
    $payment_intent = "pi_uFoDjxBJLjaZpqBn";

    $created = '2022-01-28 01:34:00';
    $created_dt = new DateTime($created);

    $stripe_event = $this->invoicePaymentSucceededEvent(
      $subscription,
      $invoice,
      $charge,
      $payment_intent,
      $created_dt->getTimestamp()
    );

    $page = new CRM_WeAct_Page_Stripe();
    $page->processNotification(json_decode($stripe_event));

    $contribution = civicrm_api3('Contribution', 'getsingle', ['trxn_id' => $invoice]);
    $this->assertEquals($contribution['receive_date'], $created);
    $this->assertEquals($contribution['contribution_status_id'], 1); # Completed

    # do it again, make sure we don't add it again
    $page->processNotification(json_decode($stripe_event));
    $contributions = civicrm_api3('Contribution', 'get', ['trxn_id' => $invoice]);
    $this->assertEquals($contributions['count'], 1);
  }

  public function testUnknownEvent() {
    $page = new CRM_WeAct_Page_Stripe();
    $event = <<<JSON
      { "type": "invoice.idontexist", "id": "evt_ineverheardof" }
JSON;
    $page->processNotification(json_decode($event));
    $this->assertTrue(true);
  }

  // TODO: Unknown subscription - But what should it do? Create the subscription right?
  // public function testUnknownRecurringDonation() {}

  protected function invoicePaymentSucceededEvent($subscription, $invoice, $charge, $payment_intent, $created) {
    return <<<JSON
        {
        "id": "evt_1KRJgJLEJyfuWvBBiCLjk75H",
        "object": "event",
        "api_version": "2020-03-02",
        "created": 1644426615,
        "data": {
          "object": {
            "id": "{$invoice}",
            "object": "invoice",
            "account_country": "DE",
            "account_name": "WeMove Europe SCE mbH",
            "amount_due": 1000,
            "amount_paid": 1000,
            "charge": "{$charge}",
            "created": $created,
            "currency": "pln",
            "customer": "cus_KOUpSAAbZ7p2hi",
            "customer_email": "aaronelliotross+pln+12102021@gmail.com",
            "lines": {
              "object": "list",
              "data": []
            },
            "paid": true,
            "payment_intent": "{$payment_intent}",
            "status": "paid",
            "subscription": "{$subscription}",
            "subtotal": 1000,
            "total": 1000
          }
        },
        "paid": true,
        "type": "invoice.payment_succeeded"
        }
JSON;
  }

  protected function customerSubscriptionUpdatedEvent($subscription, $amount) {
    return <<<JSON
    {
      "id": "evt_1KRJgJLEJyfuWvBBiCLjk75H",
      "object": "event",
      "api_version": "2020-03-02",
      "created": 1644426615,
      "data": {
        "object": {
          "id": "{$subscription}",
          "object": "subscription",
          "canceled_at": 1645023460,
          "ended_at": 1645023460,
          "status": "canceled",
          "items": {
            "data": [
                {
                  "id": "si_subscriptionitemid",
                  "price": {
                    "id": "price_1J6ErRLEJyfuWvBBEnN3Y6La",
                    "object": "price",
                    "active": true,
                    "billing_scheme": "per_unit",
                    "created": 1644426615,
                    "currency": "eur",
                    "recurring": {
                      "aggregate_usage": null,
                      "interval": "month",
                      "interval_count": 1,
                      "usage_type": "licensed"
                    },
                    "type": "recurring",
                    "unit_amount": {$amount},
                    "unit_amount_decimal": "{$amount}"
                  },
                  "quantity": 1,
                  "subscription": "{$subscription}"
                }
              ]
          }
        }
      },
      "paid": true,
      "type": "customer.subscription.updated"
    }
JSON;
  }
}
