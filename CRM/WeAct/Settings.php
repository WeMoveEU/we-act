<?php

class CRM_WeAct_Settings {
  private static $instance = NULL;

  private function __construct() {
    $this->anonymousId = Civi::settings()->get('anonymous_id');
    $this->campaignLanguageField = Civi::settings()->get('field_language');
    $this->membersGroupId = Civi::settings()->get('group_id');
    //Mapping of locale => greeting id
    $this->emailGreetingIds = $this->fetchEmailGreetingIds();
    //Mapping of country code => country id
    $this->countryIds = $this->fetchCountryIds();
  }

  public static function instance() {
    if (self::$instance == NULL) {
      self::$instance = new CRM_WeAct_Settings();
    }
    return self::$instance;
  }

  public function fetchCountryIds() {
    $key = "WeAct:Countries";
    $countries = Civi::cache()->get($key);
    if (!$countries) {
      $result = civicrm_api3('Country', 'get', [
        'return' => 'id,iso_code',
        'options' => ['limit' => 0],
      ]);
      $countries = array();
      foreach ($result['values'] as $country) {
        $countries[$country['iso_code']] = $country['id'];
      }
      Civi::cache()->set($key, $mapping);
    }
    return $countries;
  }

  //TODO is this used?
  public function fetchEmailGreetingIds() {
    //TODO cache
    $re = '/^([a-z]{2,3}_[A-Z]{2})\:(.{0,1})/';
    $emailGreetingIds = [];
    CRM_Core_OptionGroup::getAssoc('email_greeting', $group, FALSE, 'name');
    foreach ($group['description'] as $id => $description) {
      if (preg_match($re, $description, $matches)) {
        $emailGreetingIds[$matches[1]][$matches[2]] = $id;
      }
    }
    return $emailGreetingIds;
  }

  public function getEmailGreetingId($locale) {
    if (array_key_exists($locale, $this->emailGreetingIds)) {
      return $this->emailGreetingIds[$locale][''];
    }
    return 0;
  }
}
