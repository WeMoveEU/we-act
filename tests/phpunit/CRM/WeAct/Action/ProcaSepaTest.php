<?php

use CRM_WeAct_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_WeAct_Action_ProcaSepaTest extends CRM_WeAct_Action_ProcaTest {

  public function testSEPA() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-oneoff.json'

        )
    );
    $ret = $this->_process($proca_event);

    $contribution = civicrm_api3('Contribution', 'getsingle', ["id" => $ret['contrib']['id']]);

    $this->assertEquals(
      $contribution['currency'],
      'EUR'
    );
    $this->assertEquals(
      $contribution['total_amount'],
      number_format(
        $proca_event->action->donation->amount / 100,
        2
      )
    );
    $this->assertEquals(
      $contribution['trxn_id'],
      "proca_" . $proca_event->actionId
    );
    $this->assertEquals(
      $contribution['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );
    $this->assertEquals(
      $contribution['contribution_recur_id'],
      NULL
    );

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $contribution['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3('SepaMandate', 'getsingle', ['entity_table' => 'civicrm_contribution', 'entity_id' => $contribution['id']]);
    $this->assertEquals($mandate['type'], 'OOFF');
    $this->assertEquals($mandate['iban'], $proca_event->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);

    // $this->verifyUTMS()
  }



  public function testSEPAWeekly() {
     $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-weekly.json'
      )
    );

    $ret = $this->_process($proca_event);
    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    // print(json_encode($ret['contrib'], JSON_PRETTY_PRINT));

    $this->assertEquals($recurring['currency'], 'EUR');
    $this->assertEquals(
      $recurring['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals($recurring['trxn_id'], "proca_" . $proca_event->actionId);
    $this->assertEquals(
      $recurring['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );

    $this->assertEquals($recurring['frequency_unit'], 'month'); // TODO: double check
    $this->assertEquals($recurring['frequency_interval'], 1);
    $this->assertEquals(
      substr($recurring['start_date'], 0, 10),
      substr($proca_event->action->createdAt, 0, 10)
    );

    $this->verifyWeekly($recurring, $proca_event->action->customFields->weeklyAmount);
    $this->verifyUTMS($proca_event->tracking, NULL, $recurring);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $recurring['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3(
      'SepaMandate',
      'getsingle',
      [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => $recurring['id']
      ]
    );
    $this->assertEquals($mandate['type'], 'RCUR');
    $this->assertEquals($mandate['iban'], str_replace(' ', '', $proca_event->action->donation->payload->iban));
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }

  public function testSEPAMonthly() {
    $proca_event = json_decode(
      file_get_contents(
        'tests/proca-messages/sepa-monthly.json'
      )
    );
    $ret = $this->_process($proca_event);
    $recurring = civicrm_api3('ContributionRecur', 'getsingle', ["id" => $ret['contrib']['id']]);
    // print(json_encode($ret['contrib'], JSON_PRETTY_PRINT));

    $this->assertEquals($recurring['currency'], 'EUR');
    $this->assertEquals(
      $recurring['amount'],
      number_format($proca_event->action->donation->amount / 100, 2)
    );
    $this->assertEquals($recurring['trxn_id'], "proca_" . $proca_event->actionId);
    $this->assertEquals(
      $recurring['contribution_status_id'],
      $this->settings->contributionStatusIds['pending']
    );

    $this->assertEquals($recurring['frequency_unit'], 'month');
    $this->assertEquals($recurring['frequency_interval'], 1);
    $this->assertEquals(
      substr($recurring['start_date'], 0, 10),
      substr($proca_event->action->createdAt, 0, 10)
    ); // Hrm, not sure?

    $this->verifyUTMS($proca_event->tracking, NULL, $recurring);

    $contact = civicrm_api3('Contact', 'getsingle', ["id" => $recurring['contact_id']]);
    $this->assertEquals($contact['last_name'], $proca_event->contact->lastName);
    $this->assertEquals($contact['first_name'], $proca_event->contact->firstName);

    $email = civicrm_api3('Email', 'getsingle', ["contact_id" => $contact['id'], "limit" => 1]);
    $this->assertEquals($email['email'], $proca_event->contact->email);

    $mandate = civicrm_api3(
      'SepaMandate',
      'getsingle',
      [
        'entity_table' => 'civicrm_contribution_recur',
        'entity_id' => $recurring['id']
      ]
    );
    $this->assertEquals($mandate['type'], 'RCUR');
    $this->assertEquals($mandate['iban'], $proca_event->action->donation->payload->iban);
    $this->assertEquals($mandate['contact_id'], $contact['id']);
  }

}