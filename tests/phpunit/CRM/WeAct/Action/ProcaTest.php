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

  protected static function sepaPayload() {
    return <<<JSON
    {
        "iban": "PL83101010230000261395100000",
        "provider": "sepa"
    }
JSON;
  }

  protected static function stripePayload($frequency) {
    $subscription = "";
    $latest_invoice = "";
    if ($frequency != "one_off") {
      $subscription = ', "subscriptionId": "sub_scription", "customerId": "cus_TomEr"';
      $latest_invoice = ', "latest_invoice": {"id": "in_thevoice"}';
    }
    return <<<JSON
    {
        "paymentConfirm": {
            "payment_method_types": ["card"]
        },
        "paymentIntent": {
            "response": {
                "id": "pi_somegarbage",
                "customer": "cus_someone"
                $latest_invoice
            }
        },
        "provider": "stripe"
        $subscription
    }
JSON;
  }

  protected static function paypalPayload($frequency) {
    $subscription = "";
    if ($frequency != "one_off") {
      $subscription = ', "subscriptionId": "I-SUBSCR1PT10N"';
    }
    return <<<JSON
    {
        "order": {
            "id": "S0M31D"
        },
        "provider": "paypal"
        $subscription
    }
JSON;
  }
  protected static function donationJson($frequency, $payload) {
    return <<<JSON
    {
        "amount": "1000",
        "currency": "EUR",
        "frequencyUnit": "$frequency",
        "payload": $payload
    }
JSON;
  }

  protected static function trackingFields($tracking) {
    if (isset($tracking['speakout_campaign'])) {
      return '{"speakoutCampaign": "' . $tracking['speakout_campaign'] . '"}';
    } else {
      return '{}';
    }
  }

  public static function utmTracking($source = "tester") {
    return [ 'utm' => [
        "campaign" => "unit-tests",
        "source" => $source,
        "medium" => "phpunit"
    ]];
  }

  public static function speakoutTracking() {
    return [
      'speakout_campaign' => '666',
      'utm' => [
        "campaign" => "unit-tests",
        "source" => "code",
        "medium" => "phpunit",
        "location" => "https://speakout/campaigns/foo"
      ]
    ];
  }

  public static function speakoutTrackingNoUtm($speakout_id) {
    return ['speakout_campaign' => "$speakout_id", 'utm' => NULL];
  }

  protected static function eventJson($donation, $fields, $tracking) {
    if ($tracking && array_key_exists('utm', $tracking)) {
      $trackingJson = ', "tracking": ' . json_encode($tracking['utm']);
    } else {
      $trackingJson = '';
    }
    return <<<JSON
    {
        "action":
        {
            "actionType": "donate",
            "createdAt": "2021-05-20T08:25:31",
            "donation": $donation,
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
        "stage": "deliver"
        $trackingJson
    }
JSON;
  }

  public static function oneoffSepaAction($tracking = NULL) {
    return new CRM_WeAct_Action_Proca(json_decode(self::eventJson(
      self::donationJson("one_off", self::sepaPayload()),
      self::trackingFields($tracking),
      $tracking
    )));
  }

  public static function oneoffStripeAction($tracking = NULL) {
    return new CRM_WeAct_Action_Proca(json_decode(self::eventJson(
      self::donationJson("one_off", self::stripePayload("one_off")),
      self::trackingFields($tracking),
      $tracking
    )));
  }

  public static function oneoffPaypalAction() {
    return new CRM_WeAct_Action_Proca(json_decode(self::eventJson(
      self::donationJson("one_off", self::paypalPayload("one_off")),
      self::trackingFields(NULL),
      NULL
    )));
  }

  public static function recurringStripeAction($frequency = 'monthly', $tracking = NULL) {
    return new CRM_WeAct_Action_Proca(json_decode(self::eventJson(
      self::donationJson($frequency, self::stripePayload($frequency)),
      self::trackingFields($tracking),
      $tracking
    )));
  }

  public static function recurringPaypalAction($tracking = NULL) {
    return new CRM_WeAct_Action_Proca(json_decode(self::eventJson(
      self::donationJson("monthly", self::paypalPayload("monthly")),
      self::trackingFields($tracking),
      $tracking
    )));
  }

}
