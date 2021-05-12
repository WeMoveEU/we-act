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
    $this->financialTypeId = 1; //FIXME
    $this->paymentInstrumentId = 2; //FIXME
    $this->paymentProcessorIds = $this->fetchPaymentProcessors();
    $this->customFields = $this->fetchCustomFields();
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
      Civi::cache()->set($key, $countries);
    }
    return $countries;
  }

  protected function fetchPaymentProcessors() {
    return [
      'houdini-stripe' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "CommitChange-card",
        'is_test' => 0,
      ])['id'],
      'houdini-sepa' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "CommitChange-sepa",
        'is_test' => 0,
      ])['id'],
    ];
  }

  protected function fetchCustomFields() {
    return [
      'recur_source' => CRM_Contributm_Model_UtmRecur::utmSource(),
      'recur_medium' => CRM_Contributm_Model_UtmRecur::utmMedium(),
      'recur_campaign' => CRM_Contributm_Model_UtmRecur::utmCampaign(),
      'utm_source' => Civi::settings()->get('field_contribution_source'),
      'utm_medium' => Civi::settings()->get('field_contribution_medium'),
      'utm_campaign' => Civi::settings()->get('field_contribution_campaign'),
    ];
  }
  //TODO is this used?
  public function fetchEmailGreetingIds() {
    $filter = ['greeting_type' => 'email_greeting'];
    $emailGreetings = CRM_Core_PseudoConstant::greeting($filter, 'description');
    $re = '/^([a-z]{2,3}_[A-Z]{2})\:(.{0,1})/';
    $emailGreetingIds = [];
    foreach ($emailGreetings as $id => $description) {
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
