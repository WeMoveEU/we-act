<?php

class CRM_WeAct_SpeakoutTest {

  public static function parentCampaignJSON($id, $slug) {
    return <<<JSON
    {
      "id": $id,
      "slug": "$slug",
      "internal_name": "2021-06-$slug-PARENT",
      "name": "$slug",
      "parent_campaign_id": null,
      "locale": "en_GB",
      "consents": { },
      "thankyou_from_email": "",
      "thankyou_from_name": "",
      "thankyou_subject": "",
      "thankyou_body": "",
      "twitter_share_text": ""
    }
JSON;
  }

  public static function simpleEnglishPetitionJSON($id) {
    $parent_id = $id - 1;
    return <<<JSON
    {
      "id": $id,
      "slug": "some-speakout-campaign",
      "internal_name": "2021-06-some-speakout-campaign-EN",
      "name": "Some Speakout Campaign",
      "language": "en",
      "locale": "en_GB",
      "parent_campaign_id": $parent_id,
      "consents": {
        "2.1.0-en": {
          "input": "popup"
        }
      },
      "twitter_share_text": "@WeMoveEU test their software! #awesomeness",
      "thankyou_from_email": "info@wemove.test",
      "thankyou_from_name": "The Tech team",
      "thankyou_subject": "Thanks for testing your code!",
      "thankyou_body": "It's as usueful as testing a campaign"
    }
JSON;
  }

  public static function surveyJSON($id) {
    return <<<JSON
    {
      "id": $id,
      "slug": "some-speakout-survey",
      "internal_name": "2021-06-some-speakout-survey-EN",
      "title": "Some Speakout Survey",
      "language": "en",
      "locale": "en_GB",
      "twitter_share_text": null,
      "thankyou_from_email": null,
      "thankyou_from_name": null,
      "thankyou_subject": null,
      "thankyou_body": null
    }
JSON;
  }

}
