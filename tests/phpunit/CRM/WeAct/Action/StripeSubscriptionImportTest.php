<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Stripe\StripeClient;

/**
 * @group headless
 */
class CRM_WeAct_Action_StripeSubscriptionImportTest extends CRM_WeAct_BaseTest {

  public function setUp(): void {
    parent::setUp();
    $this->settings = CRM_WeAct_Settings::instance();
  }

  protected function process($subscription) {
    $mockAPI = $this->createStub(CRM_WeAct_StripeAPI::class);

    $mockAPI->method('getCustomer')
      ->willReturn(json_decode(file_get_contents(
        'tests/stripe-messages/customer-object.json'
      )));

    $mockAPI->method('getInvoices')
      ->willReturn(
        json_decode(
          file_get_contents(
            'tests/stripe-messages/invoices-for-subscription.json'
          )
        )
      );

    $subscription = json_decode(file_get_contents(
      'tests/stripe-messages/subscription.json'
    ));
    $mockAPI->method('getSubscription')->willReturn($subscription);

    $importer = new CRM_WeAct_Action_StripeSubscriptionImport($subscription->id, $mockAPI);
    $processor = new CRM_WeAct_ActionProcessor();

    return $processor->process($importer);
  }

  public function testSubscriptionImport() {
    $s = json_decode(file_get_contents(
      'tests/stripe-messages/subscription.json'
    ));
    $subscription = $this->process($s->id);
    $this->assertEquals($s->id, $subscription['trxn_id']);

    $payments = civicrm_api3('Contribution', 'get', ['contribution_recur_id' => $subscription['id']]);
    $this->assertEquals(2, $payments['count']);
  }
}
