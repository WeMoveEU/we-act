<?php

/**
 * Works with surveys from Speakout
 */
class CRM_WeAct_SurveyCache extends CRM_WeAct_CampaignCache {

  /**
   * @param string $speakout_domain
   * @param string $speakout_id
   *
   * @return string
   */
  protected function prepareAPIUrl(string $speakout_domain, string $speakout_id): string {
    return sprintf("https://%s/api/v1/surveys/%s", $speakout_domain, $speakout_id);
  }

  /**
   * @param string $speakout_domain
   * @param string $slug
   *
   * @return string
   */
  protected function prepareSlug(string $speakout_domain, string $slug): string {
    return sprintf("https://%s/surveys/%s", $speakout_domain, $slug);
  }

  /**
   * @param array $categories
   *
   * @return int|string
   * @throws \Exception
   */
  protected function prepareCampaignType($categories = []) {
    return $this->campaignType('poll', $categories);
  }

  /**
   * Surveys from Speakout should have prefix in civicrm_campaign.external_identifier
   * because this mysql field has unique key.
   * @param $system
   * @param $id
   *
   * @return string
   */
  public function externalIdentifier($system, $id): string {
    if ($system == 'houdini' || $system == 'speakout') {
      return sprintf("survey_%s", $id);
    }

    return sprintf("%s_survey_%s", $system, $id);
  }

  /**
   * @param $external_system
   * @param $external_id
   *
   * @return string
   */
  protected function keyForCache($external_system, $external_id): string {
    return sprintf("WeAct:ActionPage:Survey:%s:%s", $external_system, $external_id);
  }

  /**
   * @param string $speakout_url
   * @param string $speakout_id
   *
   * @return array|mixed|null
   */
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
