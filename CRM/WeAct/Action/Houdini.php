<?php

class CRM_WeAct_Action_Houdini extends CRM_WeAct_Action {

  public function __construct($json_msg) {
    $this->actionType = 'donate';
    $this->externalSystem = 'houdini';
    $this->actionPageId = $json_msg->external_id;
    $this->actionPageName = $json_msg->action_name;
    $this->language = $this->determineLanguage($json_msg->action_name);

    $this->contact = new CRM_WeAct_Contact($json_msg->contact);
  }

  protected function determineLanguage($campaignName) {
    $re = "/(.*)[_\\- ]([a-zA-Z]{2})$/";
    if (preg_match($re, $campaignName, $matches)) {
      $country = strtoupper($matches[2]);
      $countryLangMapping = Civi::settings()->get('country_lang_mapping');
      if (array_key_exists($country, $countryLangMapping)) {
        return $countryLangMapping[$country];
      }
    }
    return 'en_GB';
  }
}
