<?php

/*
 * Webhook to process Stripe payments for recurring donations.
 *
 *  - invoice.payment_succeeded
 *  - invoice.payment_failed
 *
 *
 * TODO:
 *    //// TODO - MOVE ALL THE PROCESSING TO AN ACTION!!!
 *    //// TODO - MOVE ALL THE PROCESSING TO AN ACTION!!!
 *    //// TODO - MOVE ALL THE PROCESSING TO AN ACTION!!!
 *    //// TODO - MOVE ALL THE PROCESSING TO AN ACTION!!!
 *    //// TODO - MOVE ALL THE PROCESSING TO AN ACTION!!!
 *
 *
 *  - cancelled / updated subscriptions: customer.subscription.updated
 *
 * N.B. The name of the class must contain Page (c.f. CRM_Core_Invoke)
 */

class CRM_WeAct_Page_Stripe extends CRM_Core_Page
{

    public function run()
    {
        $post = file_get_contents('php://input');
        $this->logEvent($post);

        $request = json_decode($post);
        if (!$request) {
            throw new CiviCRM_API3_Exception("Unable to parse JSON in POST: $post");
        }
        $this->processNotification($request);
    }

    public function processNotification($event)
    {
        switch ($event->type) {
            case 'invoice.payment_succeeded':
            case 'invoice.payment_failed':
                $this->handlePayment($event->data->object);
                break;
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $this->handleSubscriptionUpdate($event->data->object);
                break;
            case 'customer.subscription.created':
                $this->handleSubscriptionCreate($event->data->object);
                break;
            case 'charge.refunded':
            case 'charge.voided':
                $this->handleRefund($event->data->object);
                break;
            case 'customer.created':
                $this->handleCustomerCreate($event->data->object);
                break;
            default:
                CRM_Core_Error::debug_log_message("Ignoring event: {$event->id} of type {$event->type}");
        }
    }

    private function handleSubscriptionUpdate($subscription)
    {
        $id = $subscription->id;
        $status = $subscription->status;

        # find the subscription
        try {
            $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['trxn_id' => $subscription->id]);
        } catch (CiviCRM_API3_Exception $ex) {
            CRM_Core_Error::debug_log_message("handleSubscriptionUpdate: No recurring contribution with trxn_id={$subscription->id} Exception: {$ex}");
            // TODO: Try harder - look up using other keys?
            return;
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

    private function _findContribution($charge, $invoice)
    {

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
        if ($contrib_id) {return $contrib_id;}

        if ($invoice) {
            $contrib_id = CRM_Core_DAO::singleValueQuery(
                "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
                [1 => [$invoice, 'String']]
            );
            if ($contrib_id) {return $contrib_id;}

            $contrib_id = CRM_Core_DAO::singleValueQuery(
                "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
                [1 => ["{$invoice},{$charge}", 'String']]
            );
            if ($contrib_id) {return $contrib_id;}

            $contrib_id = CRM_Core_DAO::singleValueQuery(
                "SELECT id FROM civicrm_contribution WHERE trxn_id = %1",
                [1 => ["{$charge},{$invoice}", 'String']]
            );
            if ($contrib_id) {return $contrib_id;}
        }

        return false;
    }

    private function handleRefund($charge)
    {
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

    private function handlePayment($invoice)
    {
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

    private function handleCustomerCreate($customer)
    {
        return $this->createContactFromCustomer($customer);

    }

    private function createContactFromCustomer($customer)
    {
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
    private function getStripeClient()
    {
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

    /*
     * Handle Subscription Create
     *
     *     event: customer.subscription.created
     *
     */
    public function handleSubscriptionCreate($subscription)
    {

        try {
            $existing = civicrm_api3(
                'ContributionRecur',
                'get',
                [
                    'sequential' => 1,
                    'trxn_id' => $subscription->id,
                ]
            );

            if ($existing) {
                return;
            }
        } catch (CiviCRM_API3_Exception $ex) {
            CRM_Core_Error::debug_log_message("handleSubscriptionCreate: didn't find an existing Houdini subscription, that's fine: {$ex}");
        }

        $customer_id = $subscription->customer;
        $stripe = $this->getStripeClient();
        $customer = $stripe->customers->retrieve($customer_id, []);
        $email = $customer->email;

        $contact = new CRM_WeAct_Contact();
        $contact->email = $email;
        $ids = $contact->getMatchingIds();
        if (count($ids) == 0) {
            $created = $this->createContactFromCustomer($customer);
            $contact_id = $created->id;
        } else {
            $contact_id = min($ids);
        }
        $item = $subscription->items->data[0];
        $price = $item->price;

        civicrm_api3('ContributionRecur', 'create', [
            'trxn_id' => $subscription->id,
            'processor_id' => 1, # Stripe Live (or test in staging)
            'amount' => ($price->unit_amount * $item->quantity) / 100,
            'start_date' => "@{$subscription->start_date}",
            'currency' => $price->currency,
            'contact_id' => $contact_id,
        ]);

    }

    public function logEvent($msg)
    {
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

}
