<?php

class CRM_WeAct_Contact {

  public function __constructor($json_contact) {
    $this->settings = CRM_WeAct_Settings::instance();
    $this->firstname = trim($json_contact->firstname);
    $this->lastname = trim($json_contact->lastname);
    $this->email = trim($json_contact->emails[0]->email);
    $this->postcode = trim($json_contact->addresses[0]->zip);
    $this->country = strtoupper($json_contact->addresses[0]->country);
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
    $create_params = [
      'first_name' => $this->firstname,
      'last_name' => $this->lastname,
      'email_greeting_id' => $this->settings->getEmailGreeting($language),
      'preferred_language' => $language,
      'source' => $source,
      'api.Address.create' => [
        'location_type_id' => 1,
        'postcal_code' => $this->postcode,
        'country_id' => $this->settings->countryIds[$this->country],
      ];
    ];
    $create_result = civicrm_api3('Contact', 'create', $params);
    $contact = $create_result['values'][0];
    //Indicate to caller that the contact is not in the members group
    $contact['api.GroupContact.get']['count'] = 0;
    return $contact;
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
      if ($addr['postcal_code'] == $this->postcode && $addr['country_id'] == $countryId) {
        $has_address = TRUE;
        break;
      }
    }
    if (!$has_address) {
      $addr_result = civicrm_api3('Address', 'create', [
        'sequential' => 1,
        'contact_id' => $contact['id'],
        'location_type_id' => 1,
        'postcal_code' => $this->postcode,
        'country_id' => $countryId,
      ]);
      $contact['api.Address.get']['values'][] = $addr_result['values'][0];
    }
    return $contact;
  }
}
