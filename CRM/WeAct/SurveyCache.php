<?php

/**
 * Works with surveys from Speakout
 */
class CRM_WeAct_SurveyCache extends CRM_WeAct_CampaignCache {

  protected function prepareAPIUrl(string $speakout_domain, string $speakout_id): string {
    return sprintf("https://%s/api/v1/surveys/%s", $speakout_domain, $speakout_id);
  }

  protected function prepareSlug(string $speakout_domain, string $slug): string {
    return sprintf("https://%s/surveys/%s", $speakout_domain, $slug);
  }

  protected function prepareCampaignType($categories = []) {
    return $this->campaignType('poll', $categories);
  }

  /**
   * Surveys from Speakout should have prefix in civicrm_campaign.external_identifier
   * because this mysql field has unique key.
   */
  public function externalIdentifier($system, $id): string {
    if ($system == 'houdini' || $system == 'speakout') {
      return sprintf("survey_%s", $id);
    }

    return sprintf("%s_survey_%s", $system, $id);
  }

  protected function keyForCache($external_system, $external_id): string {
    return sprintf("WeAct:ActionPage:Survey:%s:%s", $external_system, $external_id);
  }

  public function getOrCreateSpeakout($speakout_url, $speakout_id) {
    $entry = $this->getExternalCampaign('speakout', $speakout_id);
    if (!$entry) {
      $urlments = parse_url($speakout_url);
      $speakout_domain = $urlments['host'];
      $this->createSpeakoutCampaign($speakout_domain, $speakout_id);
      $entry = $this->getExternalCampaign('speakout', $speakout_id);
    }
    return $entry;
  }

}
