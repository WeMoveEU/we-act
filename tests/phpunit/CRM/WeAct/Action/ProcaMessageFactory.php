<?php

class CRM_WeAct_Action_ProcaMessageFactory {

  protected static function sepaPayload() {
    return <<<JSON
    {
        "iban": "PL83101010230000261395100000",
        "provider": "sepa"
    }
JSON;
  }

  protected static function stripePayload($frequency, $livemode = "true") {
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
                "livemode": $livemode,
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


  protected static function trackingFields($tracking) {
    if (isset($tracking['speakout_campaign'])) {
      return '{"speakoutCampaign": "' . $tracking['speakout_campaign'] . '"}';
    } else {
      return '{}';
    }
  }

  public static function utmTracking($source = "tester") {
    return (object) [ 'utm' => [
        "campaign" => "unit-tests",
        "source" => $source,
        "medium" => "phpunit"
    ]];
  }

  public static function speakoutTracking() {
    return [
      'speakout_campaign' => '1339',
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
            "name": "birds/minimumbasicseeds",
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

  public static function oneoffStripeAction($tracking = NULL, $is_test = FALSE) {
    return json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/stripe-oneoff.json'
      )
    );

  //   // TODO - where is this used?
  //   if ($is_test == FALSE) {
  //     $proca_event->action->donation->payload->paymentConfirm->livemode = $is_test;
  //   }

  //   if ($tracking) {
  //     $utm = $tracking;
  //   } else {
  //     $utm = (object) [
  //       'source' => 'testing-source',
  //       'medium' => 'testing-medium',
  //       'campaign' => 'testing-campaign'
  //     ];
  //   }
  //   $proca_event->tracking = $utm;

  //   return new CRM_WeAct_Action_Proca($proca_event);

  //   return new CRM_WeAct_Action_Proca(json_decode(
  //     self::eventJson(
  //       self::donationJson(
  //         "one_off",
  //         self::stripePayload("one_off", $is_test ? "false" : "true")
  //       ),
  //       self::trackingFields($tracking),
  //     $tracking
  //   )
  // ));
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