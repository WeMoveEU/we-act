<?php
use CRM_WeAct_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_WeAct_Upgrader extends CRM_WeAct_Upgrader_Base {

  public function install() {
    $this->executeCustomDataFileByAbsPath($this->extensionDir . '/xml/campaign_fields.xml');
    $this->executeCustomDataFileByAbsPath($this->extensionDir . '/xml/campaign_types.xml');
  }

}
