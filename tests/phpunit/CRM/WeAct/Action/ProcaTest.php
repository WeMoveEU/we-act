<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_WeAct_Action_ProcaTest extends CRM_WeAct_BaseTest {

  public function setUp(): void {
    parent::setUp();

    $this->settings = CRM_WeAct_Settings::instance();
  }

  public function testSEPA() {
    $test_contribution = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-oneoff.json'
      )
    );
    $ret = $this->_process($test_contribution);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals($contribution['currency'], 'EUR');
    $this->assertEquals($contribution['total_amount'], number_format($test_contribution->action->donation->amount / 100, 2));
    $this->assertEquals($contribution['trxn_id'], "proca_" . $test_contribution->actionId);
    $this->assertEquals($contribution['contribution_status_id'], $this->settings->contributionStatusIds['pending']);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $this->assertEquals($contact['last_name'], $test_contribution->contact->lastName);
    $this->assertEquals($contact['first_name'], $test_contribution->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $test_contribution->contact->email);

    $mandate = civicrm_api3('SepaMandate', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $contribution['id']]);
    $this->assertEquals($mandate['type'], 'OOFF');
    $this->assertEquals($mandate['iban'], $test_contribution->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }

  public function testTracking() {
    $test_contribution = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-oneoff.json'
      )
    );
    $utm = (object) [
      'source' => 'testing-source',
      'medium' => 'testing-medium',
      'campaign' => 'testing-campaign',
      'content' => 'testing-content',
      // in proca messages we also have location .. ?
    ];
    $test_contribution->tracking = $utm;
    $ret = $this->_process($test_contribution);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);


    $this->assertEquals($contribution[$this->settings->customFields['utm_source']], $utm->source);
    $this->assertEquals($contribution[$this->settings->customFields['utm_medium']], $utm->medium);
    $this->assertEquals($contribution[$this->settings->customFields['utm_campaign']], $utm->campaign);
  }

  public function testSEPARecurring() {
    $test_contribution = json_decode(
      file_get_contents(
        'tests/phpunit/CRM/WeAct/Action/proca-messages/sepa-monthly.json'
      )
    );
    $ret = $this->_process($test_contribution);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals($contribution['currency'], 'EUR');
    $this->assertEquals($contribution['total_amount'], number_format($test_contribution->action->donation->amount / 100, 2));
    $this->assertEquals($contribution['trxn_id'], "proca_" . $test_contribution->actionId);
    $this->assertEquals($contribution['contribution_status_id'], $this->settings->contributionStatusIds['pending']);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $this->assertEquals($contact['last_name'], $test_contribution->contact->lastName);
    $this->assertEquals($contact['first_name'], $test_contribution->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $test_contribution->contact->email);

    $mandate = civicrm_api3('SepaMandate', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $contribution['id']]);
    $this->assertEquals($mandate['type'], 'OOFF');
    $this->assertEquals($mandate['iban'], $test_contribution->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }



  public function testDetermineLanguage() {
    // TODO
    $this->assert(FALSE);
  }

  // shared stuff

  public static function _process($json_msg) {
    $ret = civicrm_api3("Campaign", "create", ["title" => "Proca Test Campaign"]);
    $campaign_id = $ret['id'];

    $action = new CRM_WeAct_Action_Proca($json_msg);

    $json_msg->action->customFields->speakoutCampaign = $campaign_id;

    $processor = new CRM_WeAct_ActionProcessor();
    $contrib = $processor->process($action, $campaign_id);

    // maybe we can't get the return value and just have to hit the db

    return ['contrib' => $contrib, 'action' => $action];
  }
}
