<?php

require_once('vendor/autoload.php');

/*
 * Webhook to process Stripe payments for recurring donations created with
 * Houdini / CommitChange / Proca Widget.
 *
 * We only handle a small subset of events and make strong assumptions about the
 * context. For example, that a contribution recur connected to a subsription
 * already exists because some other code created it and created it with very
 * specific IDs and related objects.
 *
 * So don't go trying to make this code handle any old events from Stripe -
 * you'll probably end up with three arms and one leg. OK? OK?
 *
 * Other things:
 *
 *  - _findPayment tries really really hard to use any and all previously used
 *    identifiers to locate a contribution. To simplify that code, we would need
 *    to update the consumers to always save the same identifier:
 *
 *        - commitcivi
 *        - proca
 *        - this code
 *
 *     PaymentIntent id is probably the best option, it seems to be sent with
 *     most events (so no need to call the stripe api when handling an event).
 *
 */

class CRM_WeAct_Page_Stripe extends CRM_Core_Page {

  public function __construct() {
    $this->settings = CRM_WeAct_Settings::instance();
  }

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

    /* Dispatch events !
     *
     * Surprising but true, we do not create subscriptions or handle single
     * payments. RabbitMQ consumers commitcivi and WeAct.proca do that. Be
     * warned that the trxn_ids created in contribution and contribution_recur
     * are not always the same identifier. There might be a pi_ or a ch_ or a
     * in_ in there. Or all three. So if you start adding payments and
     * subscriptions to the db, be very afraid of duplicates.
     *
     */

    switch ($event->type) {
        // payment on recurring contribution
      case 'invoice.payment_succeeded':
      case 'invoice.payment_failed':
        return $this->handleRecurringPayment($event->data->object);
        // change in amount or cancellation
      case 'customer.subscription.updated':
      case 'customer.subscription.deleted':
        return $this->handleSubscriptionUpdate($event->data->object);
        // refunds
      case 'charge.refunded':
        return $this->handleRefund($event->data->object);
        // creates new contacts
      case 'customer.created':
        return $this->handleCustomerCreate($event->data->object);
      default:
        CRM_Core_Error::debug_log_message("Stripe: Ignoring event: {$event->id} of type {$event->type}");
        return NULL;
    }
  }

  private function handleSubscriptionUpdate($subscription) {
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
      CRM_Core_Error::debug_log_message("Stripe: handleSubscriptionUpdate: Skipping unknown status $status for recurring contribution {$contrib_recur['id']}");
    }

    $item = $subscription->items->data[0];
    $amount = $item->price->unit_amount * $item->quantity; // meh, but why not
    $to_update['amount'] = $amount / 100;

    civicrm_api3('ContributionRecur', 'create', $to_update);
  }

  private function handleRefund($charge) {
    $found = $this->_findContribution($charge);
    if (!$found) {
      throw new Exception("handleRefund: No contribution found for charge: " . json_encode($charge));
    }
    CRM_Core_Error::debug_log_message("Stripe: handleRefund: Refunding " . json_encode($found) . " Stripe Charge {$charge->id}");

    civicrm_api3('Contribution', 'create', [
      'id' => $found['id'],
      'contribution_status_id' => 'Refunded',
    ]);
  }
  private function handleRecurringPayment($invoice) {
    try {
      // i bet we'll need to try more than one field here ...
      $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $invoice->subscription]);
    } catch (CiviCRM_API3_Exception $ex) {
      # print("\nhandlePayment: No recurring contribution with trxn_id={$invoice->subscription} Exception: {$ex}");
      CRM_Core_Error::debug_log_message("Stripe: handleRecurringPayment: No recurring contribution with trxn_id={$invoice->subscription} Exception: {$ex}");
      return;
    }

    if ($invoice->amount_paid == 0) {
      // Trial periods have an initial invoice. I don't think there are any
      // other 0.00 amount invoices we want to keep.
      CRM_Core_Error::debug_log_message("Stripe: handleRecurringPayment: got an invoice for {$invoice->subscription} for 0.00, skipping it");
      return;
    }

    # # print("handleRecurringPayment: Found recurring contribution {$contrib_recur['id']}\n");
    # print("\nhandlePayment: Found recurring contribution {$contrib_recur['id']}");
    CRM_Core_Error::debug_log_message("Stripe: handleRecurringPayment: Found recurring contribution {$contrib_recur['id']}");

    // check for duplicate - don't limit to the current recurring, we might have
    // it anyway. =(  Also, we need to check three IDs - charge, payment intent
    // and invoice
    $previous = $this->_findContribution($invoice);
    if ($previous) {
       CRM_Core_Error::debug_log_message(
        "handlePayment: Found existing contribution for contribution recur " .
          "{$contrib_recur['id']} contribution " . json_encode($previous) . "\n"
      );
      return;
    }

    // stripe copies the metadata for us, but older subscriptions won't have it,
    // so careful!
    $metadata = $invoice->lines->data[0]->metadata;
    $created_dt = new DateTime("@{$invoice->created}");

    // take what we can get...
    $a = $invoice->payment_intent;
    $b = $invoice->charge;
    $c = $invoice->id;
    $trxn_id = ($a ? $a : ($b ? $b : $c));

    $payment_params = [
      'contribution_recur_id' => $contrib_recur['id'],
      'receive_date' => $created_dt->format('Y-m-d H:i:s T'),
      'trxn_id' => $trxn_id, // "{$invoice->id}",
      'invoice_id' => $invoice->id,
      'contribution_status_id' => $this->settings->contributionStatusIds[$invoice->paid ? 'completed' : 'failed'],
      'campaign_id' =>       $contrib_recur['campaign_id'],
      'contact_id' => $contrib_recur['contact_id'],
      'source' => @$metadata->utm_source,
      'total_amount' => $invoice->amount_paid / 100,
      'currency' => $contrib_recur['currency'],
      'payment_instrument_id' => $contrib_recur['payment_instrument_id'],
      'payment_processor_id' => $contrib_recur['payment_processor_id'],
      'financial_type_id' => $this->settings->financialTypeId,
    ];
    CRM_Core_Error::debug_log_message("Stripe: handleRecurringPayment: Creating contribution with " . json_encode($payment_params));

    $ret = civicrm_api3('Contribution', 'create', $payment_params);
    return $ret['values'][$ret['id']]; // so so weird
  }

  private function handleCustomerCreate($customer) {
    return $this->createContactFromCustomer($customer);
  }

  // private function _findContactId($customer_id) {

  //   if ($_ENV['testing_contact_id']) {
  //     return $_ENV['testing_contact_id']; // cheap mock
  //   }

  //   $stripe = $this->getStripeClient();
  //   $customer = $stripe->customers->retrieve($customer_id);
  //   $email = $customer->email;

  //   if (!$email) {
  //     return [];
  //   }

  //   $contact = new CRM_WeAct_Contact();
  //   $contact->email = $email;

  //   $ids = $contact->getMatchingIds();
  //   if (count($ids) == 0) {
  //     $created = $this->createContactFromCustomer($customer);
  //     $contact_id = $created['id'];
  //   } else {
  //     $contact_id = min($ids);
  //   }
  //   return $contact_id;
  // }

  public function logEvent($msg) {
    // CRM_Core_Error::debug_log_message("Stripe: request: $msg");
    $queryParams = [
      1 => [$msg, 'String'],
    ];
    try {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_stripe_webhook_log (event) VALUES (%1)",
        $queryParams
      );
    } catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message("Stripe: Stripe Webhook not logged: {$msg} {$e}");
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


  private function _findContribution($charge) {
    # Find the charge using charge_id or invoice_id or paymentintent_id or all
    # three! .. - this is totally mad because we have so many systems sending
    # payments / charges to our db.
    #
    # contribution.trxn_id = charge->id
    # if invoice
    #   contribution.trxn_id = charge->invoice
    #   contribution.trxn_id like 'charge_id  ... %'
    #   contribution.trxn_id like 'invoice_id  ... %'
    #
    # What a mess... let's update the db and make sure everything saves a
    # charge id to the contribution table.

    if ($charge->payment_intent) {
      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => [$charge->payment_intent, 'String']]
      );
      if ($contrib_id) {
        return ['id' => $contrib_id, 'trxn_id' => $charge->payment_intent];
      }
    }

    # this is a bit sneaky, this could be the id for a charge or an invoice
    $contrib_id = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
      [1 => [$charge->id, 'String']]
    );
    if ($contrib_id) {
      return ['id' => $contrib_id, 'trxn_id' => $charge->id];
    }

    if (substr($charge->id, 0, 3) == 'in_') {
      $invoice = $charge->id;
      $charge = $charge->charge;
      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => [$invoice, 'String']]
      );
      if ($contrib_id) {
        return ['id' => $contrib_id, 'trxn_id' => $invoice];
      }

      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => ["{$invoice},{$charge}", 'String']]
      );
      if ($contrib_id) {
        return ['id' => $contrib_id, 'trxn_id' => "{$invoice},{$charge}"];
      }

      $contrib_id = CRM_Core_DAO::singleValueQuery(
        "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
        [1 => ["{$charge},{$invoice}", 'String']]
      );
      if ($contrib_id) {
        return ['id' => $contrib_id, 'trxn_id' => "{$charge},{$invoice}"];
      }
    }

    return false;
  }
}
