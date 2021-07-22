<?php

use CRM_WeAct_ExtensionUtil as E;

/**
 * @group headless
 */
class CRM_WeAct_Page_PaypalTest extends CRM_WeAct_BaseTest {

  public function setUp() : void {
    parent::setUp();
  }

  public function testNewRecurringPayment() {
    $action = CRM_WeAct_Action_ProcaTest::recurringPaypalAction();
    $processor = new CRM_WeAct_ActionProcessor();
    $processor->processDonation($action, $this->campaignId, $this->contactId);

    $page = new CRM_WeAct_Page_Paypal();
    $page->processNotification(json_decode($this->recurringPayment()));

    $this->assertExists('Contribution', ['trxn_id' => '6M2390528T390274B', 'receive_date' => '2021-07-20 16:05:20']);
  }

  protected function recurringPayment() {
    return <<<JSON
    {
      "event_type": "PAYMENT.SALE.COMPLETED",
      "resource":
      {
          "id": "6M2390528T390274B",
          "state": "completed",
          "billing_agreement_id": "I-SUBSCR1PT10N",
          "create_time": "2021-07-20T14:05:20Z"
      }
    }
JSON;
  }

}

