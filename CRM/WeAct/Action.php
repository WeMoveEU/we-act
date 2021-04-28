<?php

class CRM_WeAct_Action {

  public function source() {
    return $this->externalSystem . ' ' . $this->actionType . ' ' . $this->actionPageId;
  }
}
