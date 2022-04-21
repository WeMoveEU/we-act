<?php

/**
 * Works with surveys from Speakout
 */
class CRM_WeAct_SurveyCache extends CRM_WeAct_CampaignCache {

  protected function prepareSpeakoutAPIUrl(string $speakout_domain, string $speakout_id): string {
    return sprintf("https://%s/api/v1/surveys/%s", $speakout_domain, $speakout_id);
  }

  protected function prepareSpeakoutCampaignUrl(string $speakout_domain, string $slug): string {
    return sprintf("https://%s/surveys/%s", $speakout_domain, $slug);
  }

  protected function prepareCampaignType($categories = []) {
    return $this->campaignType('poll', $categories);
  }

  /**
   * Surveys from Speakout don't share the id sequence of Speakout campaigns,
   * so their external identifier needs to be prefixed in CiviCRM
   * because this mysql field has unique key.
   */
  public function externalIdentifier($system, $id): string {
    return sprintf("%s_survey_%s", $system, $id);
  }

  protected function keyForCache($external_system, $external_id): string {
    return sprintf("WeAct:ActionPage:Survey:%s:%s", $external_system, $external_id);
  }

}
