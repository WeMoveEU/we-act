<?php

class CRM_WeAct_Contact {

  public $firstname;
  public $lastname;
  public $email;
  public $postcode;
  public $country;
  public $isMember = false;

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function isAnonymous() {
    return !$this->email;
  }

  public function getMatchingIds() {
    $query = "SELECT e.contact_id
              FROM civicrm_email e
                JOIN civicrm_contact c ON e.contact_id = c.id
              WHERE email = %1 AND c.is_deleted = 0
              ORDER BY e.contact_id ";
    $params = [
      1 => [$this->email, 'String'],
    ];
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    $ids = [];
    while ($dao->fetch()) {
      $ids[] = $dao->contact_id;
    }
    return $ids;
  }

  public function create($language, $source) {

    $country_code = NULL;
    if (key_exists($this->country, $this->settings->countryIds)) {
      $country_code = $this->settings->countryIds[$this->country];
    }

    $create_params = [
      'sequential' => 1,
      'contact_type' => 'Individual',
      'first_name' => $this->firstname,
      'last_name' => $this->lastname,
      'email' => $this->email,
      'email_greeting_id' => $this->settings->getEmailGreetingId($language),
      'preferred_language' => $language,
      'source' => $source,
      'api.Address.create' => [
        'location_type_id' => 1,
        'postal_code' => $this->postcode,
        'country_id' => $country_code,
      ]
    ];
    $create_result = civicrm_api3('Contact', 'create', $create_params);
    $contact = $create_result['values'][0];

    // Indicate to caller that the contact is not in the members group, because
    // we just created them
    // $contact['api.GroupContact.get']['count'] = 0;
    $this->isMember = false;

    return $contact;
  }

  public function determineLanguage($code) {
    $language = strtoupper($code);
    $countryLangMapping = Civi::settings()->get('country_lang_mapping');
    if (array_key_exists($language, $countryLangMapping)) {
      return $countryLangMapping[$language];
    }
    return 'en_GB';
  }

  public function createOrUpdate($language, $source) {
    if ($this->isAnonymous()) {
      return $this->settings->anonymousId;
    }

    $ids = $this->getMatchingIds();
    if (count($ids) == 0) {
      $contact = $this->create($language, $source);
    } else {
      //There shouldn't be more than one contact, but if does we'll simply use the "oldest" one
      //TODO send an alert to someone that a merge is required if more than one id
      $contact = $this->getAndUpdate(min($ids));
    }

    return $contact;
  }


  public function sendConsents($contact_id, $campaign_id, $utms = []) {

    Civi::log()->debug("Checking for group membership - {$this->isMember}");

        // Membership was retrieved from a joined query to GroupContact for the members group
    if (! $this->isMember) {
      Civi::log()->debug("Sending consent request to contact {$contact_id}");
      $consentParams = [
        'contact_id' => $contact_id,
        'campaign_id' => $campaign_id,
        'utm_source' => CRM_Utils_Array::value('source', $utms),
        'utm_medium' => CRM_Utils_Array::value('medium', $utms),
        'utm_campaign' => CRM_Utils_Array::value('campaign', $utms),
      ];
      civicrm_api3('Gidipirus', 'send_consent_request', $consentParams);
    }

  }


  public function getAndUpdate($contact_id) {
    $get_params = [
      'id' => $contact_id,
      'api.Address.get' => array(
        'id' => '$value.address_id',
        'contact_id' => '$value.id',
      ),
      'api.GroupContact.get' => array(
        'group_id' => $this->settings->membersGroupId,
        'contact_id' => '$value.id',
        'status' => 'Added',
      ),
      'return' => 'id,email,first_name,last_name,preferred_language,is_opt_out',
    ];
    $get_result = civicrm_api3('Contact', 'get', $get_params);
    $contact = $get_result['values'][$contact_id];

    $contact = $this->updateAddress($contact);

    return $contact;
  }

  /**
   * Creates a CRM address for the contact if it cannot be found in $contact (get result)
   */
  protected function updateAddress($contact) {
    $countryId = $this->settings->countryIds[$this->country];
    $has_address = FALSE;
    foreach ($contact['api.Address.get']['values'] as $addr) {
      if ($addr['postal_code'] == $this->postcode && $addr['country_id'] == $countryId) {
        $has_address = TRUE;
        break;
      }
    }
    if (!$has_address) {
      $addr_result = civicrm_api3('Address', 'create', [
        'sequential' => 1,
        'contact_id' => $contact['id'],
        'location_type_id' => 1,
        'postal_code' => $this->postcode,
        'country_id' => $countryId,
      ]);
      $contact['api.Address.get']['values'][] = $addr_result['values'][0];
    }
    return $contact;
  }
}
