<?php

class CRM_WeAct_Settings {
  private static $instance = NULL;

  private function __construct() {
    $this->anonymousId = Civi::settings()->get('anonymous_id');
    $this->defaultSender = CRM_Core_BAO_Setting::getItem('Speakcivi API Preferences', 'from');
    $this->membersGroupId = Civi::settings()->get('group_id');
    //Mapping of locale => greeting id
    $this->emailGreetingIds = $this->fetchEmailGreetingIds();
    //Mapping of country code => country id
    $this->countryIds = $this->fetchCountryIds();
    $this->financialTypeId = 1; // FIXME
    $this->paymentInstrumentIds = $this->fetchPaymentInstruments();
    $this->paymentProcessorIds = $this->fetchPaymentProcessors();
    $this->contributionStatusIds = $this->fetchContributionStatus();
    $this->customFields = $this->fetchCustomFields();
    $this->countryCodeToLocale = Civi::settings()->get('country_lang_mapping');
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

  protected function fetchPaymentInstruments() {
    //Identification is based on label because the names were already misleading in prod when this code was written
    return [
      'paypal' => civicrm_api3('OptionValue', 'getsingle', [
        'return' => ['value'],
        'label' => 'Paypal',
        'option_group_id' => 'payment_instrument'
      ])['value'],
      'card' => civicrm_api3('OptionValue', 'getsingle', [
        'return' => ['value'],
        'label' => 'Stripe',
        'option_group_id' => 'payment_instrument'
      ])['value']
    ];
  }

  protected function fetchContributionStatus() {
    $dao = CRM_Core_DAO::executeQuery(<<<SQL
      select lower(label) name, value from civicrm_option_value
      where option_group_id = (select id from civicrm_option_group where name = 'contribution_status')
SQL
    );
    $map = [];
    while ($dao->fetch()) {
      $map[$dao->name] = $dao->value;
    }
    return $map;
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
      'proca-stripe' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "Credit Card",
        'is_test' => 0,
      ])['id'],
      'proca-sepa' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "Proca-sepa",
        'is_test' => 0,
      ])['id'],
      'proca-paypal' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "Proca-paypal",
        'is_test' => 0,
      ])['id'],
      'paypal-button' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "Paypal-button",
        'is_test' => 0,
      ])['id'],
      'stripe' => civicrm_api3('PaymentProcessor', 'getsingle', [
        'return' => ["id"],
        'name' => "Credit Card",
        'is_test' => 0,
      ])['id'],
    ];
  }

  protected function fetchCustomFields() {
    $custom_fields = [];
    foreach (['', 'recur_'] as $group) {
      foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as $field_name) {
        $custom_fields[$group . $field_name] = $this->getCustomField($group . 'utm', $field_name);
      }
    }
    $campaign_fields = [
      'language' => 'language',
      'sender' => 'sender_email',
      'url' => 'url_campaign',
      'slug' => 'utm_campaign',
      'twitter_share' => 'twitter_share_text',
      'confirm_subject' => 'subject_new',
      'confirm_body' => 'message_new',
      'postaction_subject' => 'subject_member',
      'postaction_body' => 'message_member',
      'consent_ids' => 'Consent_IDs',
    ];
    foreach ($campaign_fields as $key => $field_name) {
      $custom_fields["campaign_$key"] = $this->getCustomField('speakout_integration', $field_name);
    }
    return $custom_fields;
  }

  protected function getCustomField($group, $name) {
    $get_field = civicrm_api3('CustomField', 'get', [
      'custom_group_id' => $group,
      'name' => $name,
    ]);
    return 'custom_' . $get_field['id'];
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
