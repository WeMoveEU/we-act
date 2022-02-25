<?php

require_once('vendor/autoload.php');

/*
 * Webhook to process Stripe payments for recurring donations.
 */

class CRM_WeAct_Page_Stripe extends CRM_Core_Page {

  /*
     * Notes:
     *
     *  - Main feature : to handle recurring payments from Proca and Houdini
     *    created / migrated subscriptions, and any "god knows
     *    where" from subscriptions.
     *
     *  - Contributions in CiviCRM should *always* be saved and found with the
     *    Stripe charge_id.

     *  - If needed, update the db to sync trxn_id = Stripe.charge_id, don't add
     *    code. The _findTransaction can go away once that's done.
     *
     *  - handleSubscriptionCreate does *NOT* create a civicrm contribution, just
     *    a civicrm contributionrecur. handlePayment will create the civicrm
     *    contribution and attach it to the contributionrecur.
     *
     * Questions:
     *
     *  - Why do we have to look up the contribution when calling
     *    repeattransaction? We don't always, but we're better at it than
     *    CiviCRM because we use fewer criteria. Donation.createContrib should
     *    do the same?
     *
     * Refactoring notes:
     *
     *  - Move more of this code to Action classes, specifically anything that
     *    creates Contribution and ContributionRecur should re-sue the code in
     *    Action/Donation.php; We want to have an Activity entry anytime a
     *    ContributionRecur is created, but that's not a priority.
     * - Action/Stripe.php would have the switch / case and the handling code
     * - Page/Stripe.php would decode, instantiate a Stripe instance and hand
     *   off processing.
     * - Action Stripe.php could be split into multiple classes: StripePayment,
     *   StripeSubscription and so on. Yeesh, this is starting to look a lot
     *   like the Stripe extension!
     */

  public function run() {
    $post = file_get_contents('php://input');
    $this->logEvent($post);

    $request = json_decode($post);
    if (!$request) {
      throw new CiviCRM_API3_Exception("Unable to parse JSON in POST: $post");
    }
    $this->processNotification($request);
  }

  public function processNotification($event) {
    switch ($event->type) {
      case 'invoice.payment_succeeded':
      case 'invoice.payment_failed':
        return $this->handlePayment($event->data->object);
      case 'customer.subscription.updated':
      case 'customer.subscription.deleted':
        return $this->handleSubscriptionUpdate($event->data->object);
      case 'customer.subscription.created':
        return $this->handleSubscriptionCreate($event->data->object);
      case 'charge.succeeded':
        return $this->handleChargeSucceeded($event->data->object);
      case 'charge.refunded':
      case 'charge.voided':
        return $this->handleRefund($event->data->object);
      case 'customer.created':
        return $this->handleCustomerCreate($event->data->object);
      default:
        CRM_Core_Error::debug_log_message("Ignoring event: {$event->id} of type {$event->type}");
        return NULL;
      }

  }

  private function handleSubscriptionUpdate($subscription) {
    $id = $subscription->id;
    $status = $subscription->status;

    # find the subscription
    try {
      $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $subscription->id]);
    } catch (CiviCRM_API3_Exception $ex) {
      throw new Exception("handleSubscriptionUpdate: No recurring contribution with trxn_id={$subscription->id} Exception: {$ex}");
    }

    $CIVI_STATUS = [
      'active' => 'In Progress',
      'past_due' => 'Failed', // end state for us, since no more payment attempts are made
      'unpaid' => 'Failed', // same
      'canceled' => 'Cancelled',
      // 'incomplete'
      'incomplete_expired' => 'Failed', // terminal state
      // 'trialing'
    ];

    $to_update = ['id' => $contrib_recur['id']];

    // only update the status if we know what it is ? Not sure what to do here really.
    if (array_key_exists($status, $CIVI_STATUS)) {

      $to_update['contribution_status_id'] = $CIVI_STATUS[$status];

      if ($status == 'canceled') {
        $canceled_at = new DateTime("@{$subscription->canceled_at}");
        $to_update['cancel_date'] = $canceled_at->format('Y-m-d H:i:s T');
      }

      if ($subscription->ended_at) {
        $ended_at = new DateTime("@{$subscription->ended_at}");
        $to_update['end_date'] = $ended_at->format('Y-m-d H:i:s T');
      }
    } else {
      CRM_Core_Error::debug_log_message("handleSubscriptionUpdate: Skipping unknown status $status for recurring contribution {$contrib_recur['id']}");
    }

    $item = $subscription->items->data[0];
    $amount = $item->price->unit_amount * $item->quantity; // meh, but why not
    $to_update['amount'] = $amount / 100;

    civicrm_api3('ContributionRecur', 'create', $to_update);
  }

  private function handleRefund($charge) {
    $contribution_id = $this->_findContribution($charge->id, $charge->invoice);
    if (!$contribution_id) {
      CRM_Core_Error::debug_log_message("handleRefund: No contribution found for charge {$charge->id}");
      return;
    }

    CRM_Core_Error::debug_log_message("handleRefund: Refunding $contribution_id Stripe Charge {$charge->id}");

    civicrm_api3('Contribution', 'create', [
      'id' => $contribution_id,
      'contribution_status_id' => 'Refunded',
    ]);
  }

  private function handleChargeSucceeded($charge) {

    // charges with an invoice are not our problem - let invoice.payment_succeeded
    // those
    if ($charge->invoice != NULL) {
      return;
    }

    throw new Exception("Single payments aren't handled here! The webhook shouldn't send them.");
  }

  private function handlePayment($invoice) {

    if ($invoice->subscription == NULL) {
      return $this->handleSinglePayment($invoice);
    }

    try {
      // i bet we'll need to try more than one field here ...
      $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $invoice->subscription]);
    } catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::debug_log_message("handlePayment: No recurring contribution with trxn_id={$invoice->subscription} Exception: {$ex}");
      // TODO: Try harder - look up using other keys?
      return;
    }

    CRM_Core_Error::debug_log_message("handlePayment: Found recurring contribution {$contrib_recur['id']}");

    try {
      $contrib = civicrm_api3('Contribution', 'getsingle', [
        'contribution_recur_id' => $contrib_recur['id'],
        'options' => ['limit' => 1, 'sort' => 'id DESC'],
      ]);
    } catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::debug_log_message("handlePayment: No contribution found for recurring: {$contrib_recur['id']}");
      // TODO: Try harder - create a contribution
      return;
    }

    $contrib_id = $contrib['id'];

    if ($contrib['trxn_id'] == $invoice->id) {
      CRM_Core_Error::debug_log_message("handlePayment: Already got this contribution: $contrib_id for recurring {$contrib_recur['id']}");
      return;
    }

    CRM_Core_Error::debug_log_message("handlePayment: Found contribution $contrib_id for recurring {$contrib_recur['id']}");

    $created_dt = new DateTime("@{$invoice->created}");
    $repeat_params = [
      'contribution_recur_id' => $contrib_recur['id'],
      'original_contribution_id' => $contrib_id,
      'contribution_status_id' => $invoice->paid ? 'Completed' : 'Failed', # XXX: only works for payment_succeeded and payment_failed
      'receive_date' => $created_dt->format('Y-m-d H:i:s T'),
      'trxn_id' => "{$invoice->id}", #,{$invoice->charge},{$invoice->payment_intent}", # invoice / charge / payment intent - PI is new!
      # 'processor_id' => "{$invoice->id}"
    ];
    CRM_Core_Error::debug_log_message("handlePayment: Repeating contribution with " . json_encode($repeat_params));

    civicrm_api3('Contribution', 'repeattransaction', $repeat_params);
  }

  private function handleCustomerCreate($customer) {
    return $this->createContactFromCustomer($customer);
  }

  /*
     * Handle Subscription Create
     *
     *     event: customer.subscription.created
     *
     */
  public function handleSubscriptionCreate($subscription) {

    $settings = CRM_WeAct_Settings::instance();

    $existing = civicrm_api3(
      'ContributionRecur',
      'get',
      [
        'sequential' => 1,
        'trxn_id' => $subscription->id,
      ]
    );

    if ($existing['count'] > 0) {
      CRM_Core_Error::debug_log_message("handleSubscriptionCreate: ignoring subscription we've already got: {$subscription->id}");
      return;
    }

    $customer_id = $subscription->customer;
    $contact_id = $this->_findContactId($customer_id);

    $item = $subscription->items->data[0];
    $price = $item->price;

    $contribution = [
      'amount' => ($price->unit_amount * $item->quantity) / 100,
      'contact_id' => $contact_id,
      'contribution_status_id' => 'In Progress',
      'create_date' => "@{$subscription->created}",
      'currency' => strtoupper($price->currency),
      'financial_type_id' => $settings->financialTypeId,
      'frequency_interval' => $price->recurring->interval_count,
      'frequency_unit' => $price->recurring->interval,  // Stripe and CiviCRM Agree!!!
      'payment_instrument_id' => $settings->paymentInstrumentIds['card'],
      'payment_processor_id' => $settings->paymentProcessorIds['stripe'],
      'start_date' => "@{$subscription->start_date}",
      'trxn_id' => $subscription->id,
      # TODO:
      # 'campaign_id' => $campaign_id,
      # 'is_test' => $this->isTest,
      # $this->settings->customFields['recur_utm_source'] => CRM_Utils_Array::value('source', $utm),
      # $this->settings->customFields['recur_utm_medium'] => CRM_Utils_Array::value('medium', $utm),
      # $this->settings->customFields['recur_utm_campaign'] => CRM_Utils_Array::value('campaign', $utm),
    ];

    $recur = civicrm_api3('ContributionRecur', 'create', $contribution);

    # All this (creating a contribution) because CiviCRM has to have an
    # "Orgininal Contribution".

    $contribution['contribution_recur_id'] = $recur['id'];
    $contribution['contribution_status_id'] = 'Completed';
    unset($contribution['start_date']);
    $contribution['receive_date'] = $contribution['create_date'];
    unset($contribution['create_date']);

    if ($_ENV['CIVICRM_UF'] == 'UnitTests') {
      $charge_id = 'ch_sosofakethisidisfake';
    } else {
      $stripe = $this->getStripeClient();
      $invoice = $stripe->invoices->retrieve($subscription->latest_invoice);
      $charge_id = $invoice->charge;
    }
    $contribution['trxn_id'] = $charge_id;
    $contribution['total_amount'] = $contribution['amount'];

    civicrm_api3('Contribution', 'create', $contribution);
  }

  private function _findContactId($customer_id) {
    $stripe = $this->getStripeClient();

    if ($_ENV['CIVICRM_UF'] == 'UnitTests') {
      return $_ENV['testing_contact_id']; // cheap mock
    }

    $customer = $stripe->customers->retrieve($customer_id);
    $email = $customer->email;

    if (!$email) {
      return [];
    }

    $contact = new CRM_WeAct_Contact();
    $contact->email = $email;

    $ids = $contact->getMatchingIds();
    if (count($ids) == 0) {
      $created = $this->createContactFromCustomer($customer);
      $contact_id = $created['id'];
    } else {
      $contact_id = min($ids);
    }
    return $contact_id;
  }

  public function logEvent($msg) {
    // CRM_Core_Error::debug_log_message("request: $msg");
    $queryParams = [
      1 => [$msg, 'String'],
    ];
    try {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_stripe_webhook_log (event) VALUES (%1)",
        $queryParams
      );
    } catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message("Stripe Webhook not logged: {$msg} {$e}");
    }
  }

  private function createContactFromCustomer($customer) {
    $contact = new CRM_WeAct_Contact();
    $contact->name = $customer->name;
    $contact->email = $customer->email;
    $contact->postcode = $customer->address ? $customer->address->postal_code : '';
    $contact->country = $customer->address ? $customer->address->country : '';

    # Stripe locales aren't country specific, so we're fucked trying to
    # match up. This is why integrations are hell. Happy happy!
    $locales = $customer->preferred_locales;
    $language = count($locales) > 0
      ? $contact->determineLanguage($locales[0])
      : "en_GB";

    return $contact->createOrUpdate($language, 'stripe');
  }

  // TODO - move to a shared place with Proca.php::_lookupCharge
  private function getStripeClient() {
    $sk = CRM_Core_DAO::singleValueQuery(
      "SELECT password FROM civicrm_payment_processor WHERE id = 1" // I know, but it works
    );
    if (!$sk) {
      $sk = getenv("STRIPE_SECRET_KEY");
    }
    if (!$sk) {
      throw new Exception("Oops, couldn't find a secret key for Stripe. Can't go on!");
    }
    return new \Stripe\StripeClient($sk);
  }


  private function _findContribution($charge, $invoice) {

    # Find the charge using charge_id or invoice_id or ... - this is totally mad
    # because we have so many systems sending payments / charges to our db.
    #
    # contribution.trxn_id = charge->id
    # if invoice
    #   contribution.trxn_id = charge->invoice
    #   contribution.trxn_id like 'charge_id  ... %'
    #   contribution.trxn_id like 'invoice_id  ... %'
    #
    # What a mess... let's update the db and make sure everything saves a
    # charge id to the contribution table.

    $contrib_id = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
      [1 => [$charge, 'String']]
    );
    if ($contrib_id) {
      return $contrib_id;
    }

    if ($invoice) {
      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => [$invoice, 'String']]
      );
      if ($contrib_id) {
        return $contrib_id;
      }

      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => ["{$invoice},{$charge}", 'String']]
      );
      if ($contrib_id) {
        return $contrib_id;
      }

      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => ["{$charge},{$invoice}", 'String']]
      );
      if ($contrib_id) {
        return $contrib_id;
      }
    }

    return false;
  }
}
