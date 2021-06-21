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
class CRM_WeAct_Action_ProcaTest extends CRM_WeAct_BaseTest {

  public function testDetermineLanguage() {
    $action = self::oneoffStripeAction();
    $this->assertEquals($action->language, 'pl_PL');
  }

  protected static function oneoffSepaFields() {
    return <<<JSON
    {
        "amount": "1000",
        "created": "1621499098",
        "currency": "eur",
        "IBAN": "PL83101010230000261395100000",
        "BIC": "NOTPROVIDED",
        "id": "some_sepa_id",
        "status": "succeeded",
        "payment_method_types": "sepa"
    }
JSON;
  }

  protected static function oneoffStripeFields() {
    return <<<JSON
    {
        "amount": "1000",
        "capture_method": "automatic",
        "client_secret": "pi_some_secret_gargbage",
        "confirmation_method": "automatic",
        "created": "1621499098",
        "currency": "eur",
        "id": "pi_somegarbage",
        "object": "payment_intent",
        "payment_method": "pm_somecard",
        "payment_method_types": "card",
        "status": "succeeded"
    }
JSON;
  }

  protected static function utmTracking() {
    return <<<JSON
    {
        "campaign": "unit-tests",
        "source": "tester",
        "medium": "phpunit"
    }
JSON;
  }

  protected static function donationJson($fields, $tracking = "null") {
    return <<<JSON
    {
        "action":
        {
            "actionType": "donate",
            "createdAt": "2021-05-20T08:25:31",
            "fields": $fields
        },
        "actionId": 5,
        "actionPage":
        {
            "locale": "pl",
            "name": "fund/us",
            "thankYouTemplateRef": null
        },
        "actionPageId": 3,
        "campaign":
        {
            "externalId": null,
            "name": "STC",
            "title": "Some Test Campaign"
        },
        "campaignId": 2,
        "contact":
        {
            "email": "romain@test.eu",
            "firstName": "Romain",
            "payload": "{\"area\":\"FR\",\"country\":\"FR\",\"email\":\"romain@test.eu\",\"firstName\":\"Romain\",\"lastName\":\"Tester\",\"postcode\":\"12345\"}",
            "ref": "E2Cc0yLjyseWUVfDzlelGaALVP3QAZNNYuL1RCybAl8"
        },
        "orgId": 3,
        "privacy":
        {
            "communication": false,
            "givenAt": "2021-05-20T08:25:01Z"
        },
        "schema": "proca:action:1",
        "stage": "deliver",
        "tracking": $tracking
    }
JSON;
  }

  public static function oneoffSepaAction() {
    return new CRM_WeAct_Action_Proca(json_decode(self::donationJson(
      self::oneoffSepaFields()
    )));
  }

  public static function oneoffStripeAction() {
    return new CRM_WeAct_Action_Proca(json_decode(self::donationJson(
      self::oneoffStripeFields(), self::utmTracking()
    )));
  }

}
