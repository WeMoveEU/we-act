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

    // NOTE: The initial recurring payment has UTM codes attached. The call
    // to repeattransaction in Page/Paypal.php triggers a copyCustomValues call
    // to copy those to the payment. That was conflicting with a postSave hook
    // in contributm that checked specifically for recurring contribution
    // payments that didn't have utms set. I don't *really* understand, but
    // we can't have both running. So I removed the contributm check for
    // recurring payments that had no utms set.  This test will fail with a
    // "Duplicate key exists" MySQL error if the contributm code is
    // restored. Whee.

    $event = CRM_WeAct_Action_ProcaMessageFactory::recurringPaypalAction();
    $paypal_subscription_id = $event->action->donation->payload->response->subscriptionID;

    $action = new CRM_WeAct_Action_Proca($event);
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

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
  }

  public function testUnknownRecurringDonation() {
    $page = new CRM_WeAct_Page_Paypal();
    $page->processNotification(json_decode($this->recurringPayment("I-DOESNOTEXIST")));
    $get_result = civicrm_api3('Contribution', 'get', ['trxn_id' => '6M2390528T390274B']);
    $this->assertEquals(0, $get_result['count']);
  }

  protected function recurringPayment($subscription_id) {
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

}

