<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_WeAct_Action_HoudiniTest extends CRM_WeAct_BaseTest {

  public function testDetermineLanguage() {
    $action = self::oneoffStripeAction();
    $this->assertEquals($action->language, 'pl_PL');
  }

  protected static function oneoffStripeJson() {
    return <<<JSON
    {
      "action_type":"donate",
      "action_technical_type":"cc.wemove.eu:donate",
      "create_dt":"2017-10-25T12:34:56.531Z",
      "action_name":"something-PL",
      "external_id":"cc_42",
      "contact":{
        "firstname":"Test",
        "lastname":"Testowski",
        "emails":[{"email":"test+t1@example.com"}],
        "addresses":[
          {
            "zip":"01-234",
            "country":"pl"
          }
        ]
      },
      "donation":{
        "amount":15.67,
        "amount_charged":0.17,
        "currency":"EUR",
        "card_type":"Visa",
        "payment_processor":"stripe",
        "type":"single",
        "transaction_id":"ch_1NHwmdLnnERTfiJAMNHyFjV4",
        "customer_id":"cus_Bb94Wds2n3xCVB",
        "status":"success"
      },
      "source":{
        "source":"phpunit",
        "medium":"phpstorm",
        "campaign":"testing"
      }
    }
JSON;
  }

  public static function oneoffStripeAction() {
    return new CRM_WeAct_Action_Houdini(json_decode(self::oneoffStripeJson()));
  }

	private static function recurringStripeJson() {
    return <<<JSON
    {
      "action_type":"donate",
      "action_technical_type":"cc.wemove.eu:donate",
      "create_dt":"2017-12-13T11:47:56.531Z",
      "action_name":"campaign-PL",
      "external_id":50002,
      "contact":{
        "firstname":"Test2",
        "lastname":"Testowski2",
        "emails":[{"email":"test+t2@example.com"}],
        "addresses":[
          {
            "zip":"01-234",
            "country":"pl"
          }
        ]
      },
      "donation":{
        "amount":23,
        "amount_charged":0,
        "currency":"EUR",
        "card_type":"Visa",
        "payment_processor":"stripe",
        "type":"recurring",
        "recurring_id":"cc_1",
        "transaction_id":"ch_1NHwmdLnnERTfiJAMNHyFjAB",
        "customer_id":"cus_Bb94Wds2n3xCVB",
        "status":"success"
      },
      "source":{
        "source":"phpunit",
        "medium":"phpstorm",
        "campaign":"testing"
      }
    }
JSON;
  }

	public static function recurringStripeAction() {
		return new CRM_WeAct_Action_Houdini(json_decode(self::recurringStripeJson()));
	}

	public static function oneoffSepaAction() {
		return new CRM_WeAct_Action_Houdini(json_decode(self::oneoffSepaJson()));
	}

  public static function oneoffSepaJson() {
    return <<<JSON
    {
      "action_type":"donate",
      "action_technical_type":"cc.wemove.eu:donate",
      "create_dt":"2017-12-13T14:16:56Z",
      "action_name":"campaign-PL",
      "external_id":50002,
      "contact":{
        "firstname":"Test3",
        "lastname":"Testowski3",
        "emails":[{"email":"test+t3@example.com"}],
        "addresses":[
          {
            "zip":"01-234",
            "country":"pl"
          }
        ]
      },
      "donation":{
        "amount":15,
        "currency":"eur",
        "type":"single",
        "payment_processor":"sepa",
        "amount_charged":0,
        "transaction_id":"cc_100001",
        "iban":"PL83101010230000261395100000",
        "bic":"NOTPROVIDED",
        "account_holder":"test",
        "status":"success"
      },
      "source":{
        "source":"phpunit",
        "medium":"phpstorm",
        "campaign":"testing"
      }
    }
JSON;
  }

  public static function recurringSepaJson() {
    return <<<JSON
    {
      "action_type":"donate",
      "action_technical_type":"cc.wemove.eu:donate",
      "create_dt":"2017-12-13T14:16:56Z",
      "action_name":"campaign-PL",
      "external_id":50002,
      "contact":{
        "firstname":"Test3",
        "lastname":"Testowski3",
        "emails":[{"email":"test+t3@example.com"}],
        "addresses":[
          {
            "zip":"01-234",
            "country":"pl"
          }
        ]
      },
      "donation":{
        "amount":15,
        "currency":"eur",
        "type":"recurring",
        "payment_processor":"sepa",
        "amount_charged":0,
        "recurring_id":"ccr_100001",
        "transaction_id":"cc_100002",
        "iban":"PL83101010230000261395100000",
        "bic":"NOTPROVIDED",
        "account_holder":"test",
        "status":"success"
      },
      "source":{
        "source":"phpunit",
        "medium":"phpstorm",
        "campaign":"testing"
      }
    }
JSON;
  }

	public static function recurringSepaAction() {
		return new CRM_WeAct_Action_Houdini(json_decode(self::recurringSepaJson()));
	}

}
