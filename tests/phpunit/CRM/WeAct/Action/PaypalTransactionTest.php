<?php

class CRM_WeAct_Action_PaypalTransactionTest {

  public static function oneoffAction($trxn_id) {
    return new CRM_WeAct_Action_PaypalTransaction(json_decode(self::eventJson(
      self::transactionJson($trxn_id),
      self::payerJson()
    )));
  }

  public static function transactionJson($trxn_id, $subscription_id = NULL) {
    return <<<JSON
    {
        "transaction_id": "$trxn_id",
        "transaction_event_code": "T0013",
        "transaction_initiation_date": "2021-11-20T07:38:28+0000",
        "transaction_amount": {
            "currency_code": "EUR",
            "value": "12.00"
        },
        "fee_amount": {
            "currency_code": "EUR",
            "value": "-0.56"
        },
        "transaction_status": "S",
        "custom_field": "%7B%7D"
    }
JSON;
  }

  public static function payerJson() {
    return <<<JSON
    {
      "email_address": "tester@wemove.eu",
      "payer_name": {
          "given_name": "Elisabeth",
          "surname": "The Queen"
      },
      "country_code": "AT"
    }
JSON;
  }

  public static function eventJson($transaction_info, $payer_info) {
    return "{\"transaction_info\": $transaction_info, \"payer_info\": $payer_info}";
  }
}
