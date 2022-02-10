<?php

use CRM_WeAct_ExtensionUtil as E;

/**
 * @group headless
 */
class CRM_WeAct_Page_PaypalTest extends CRM_WeAct_BaseTest {

  public function setUp() : void {
    parent::setUp();
  }

  // Create the subscription from a Proca event, then test an IPN notification
  // from PayPal finds the subscription and saves it.
  public function testNewRecurringPayment() {
    $event = CRM_WeAct_Action_ProcaMessageFactory::recurringPaypalAction();
    $paypal_subscription_id = $event->action->donation->payload->response->subscriptionID;

    $action = new CRM_WeAct_Action_Proca($event);
    $processor = new CRM_WeAct_ActionProcessor();
    $recurring = $processor->process($action, $this->contactId);

    // OK, now handle the IPN notification
    $page = new CRM_WeAct_Page_Paypal();
    $page->processNotification(json_decode($this->recurringPayment($paypal_subscription_id)));

    $contribution = $this->assertExists('Contribution', [
      'trxn_id' => '6M2390528T390274B',
      'receive_date' => '2021-07-20 14:05:20'
    ]);
    $recurring = $this->assertExists('ContributionRecur', [
      'trxn_id' => $paypal_subscription_id

    ]);
    $this->assertEquals($contribution['contribution_recur_id'], $recurring['id']);

    $this->verifyUTMS($event->tracking, $contribution, $recurring);
  }

  public function testUnknownRecurringDonation() {
    $page = new CRM_WeAct_Page_Paypal();
    $page->processNotification(json_decode($this->recurringPayment("I-DOESNOTEXIST")));
    $get_result = civicrm_api3('Contribution', 'get', ['trxn_id' => '6M2390528T390274B']);
    $this->assertEquals(0, $get_result['count']);
  }

  protected function recurringPayment($subscription_id) {
    // do the real events contain an amount?
    return <<<JSON
    {
      "event_type": "PAYMENT.SALE.COMPLETED",
      "resource":
      {
          "id": "6M2390528T390274B",
          "state": "completed",
          "billing_agreement_id": "$subscription_id",
          "create_time": "2021-07-20T14:05:20Z"
      }
    }
JSON;
  }

  // TODO
  // protected function recurringCancel($subscription_id) {}

}

