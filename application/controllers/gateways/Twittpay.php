<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * TwittPay - Perfex CRM gateway controller
 * ---------------------------------------------------------------------------
 * verify_payment()  the customer coming back from the checkout page
 * webhook()         the gateway's own server telling us what happened
 *
 * Both verify the transaction against the API before any payment is recorded, and
 * both refuse a transaction id that has already been recorded - so the two of them
 * arriving for the same payment cannot pay an invoice twice.
 *
 * @version 1.0.0
 */
class Twittpay extends App_Controller
{
    /**
     * Where the customer lands after paying.
     *
     * @return mixed
     */
    public function verify_payment()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            show_404();
        }

        $transactionId = $this->input->get('transactionId');

        if (empty($transactionId)) {
            $transactionId = $this->input->get('transaction_id');
        }

        try {
            if (empty($transactionId)) {
                set_alert('warning', 'No transaction was received. If you have paid, the payment will be recorded shortly.');
            } elseif ($this->twittpay_gateway->payment_exists($transactionId)) {
                // The webhook got here first. Nothing left to do.
                set_alert('success', _l('online_payment_recorded_success'));
            } else {
                $this->record($transactionId, $invoice, true);
            }
        } catch (\Exception $e) {
            log_activity('TwittPay return failed: ' . $e->getMessage());
            set_alert('danger', 'The payment could not be checked right now. Please contact us if it is not recorded shortly.');
        }

        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
    }

    /**
     * The gateway's webhook. It fires again when a pending payment is decided, so
     * this has to stay safe to call more than once.
     *
     * @param  string $key
     * @return mixed
     */
    public function webhook($key = null)
    {
        $response = $this->twittpay_gateway->fetch_payment();
        $api      = $this->twittpay_gateway->api();
        $status   = $api->readStatus($response);

        if ($status === '') {
            log_activity('TwittPay webhook called for a transaction the gateway does not know.');

            return;
        }

        $meta = $api->metadata($response);

        // The webhook URL carries a one-off key that was put into metadata when the
        // payment was created. If they do not match, this call is not about this
        // payment.
        if (!empty($key) && !empty($meta['webhook_key']) && !hash_equals((string) $meta['webhook_key'], (string) $key)) {
            log_activity('TwittPay webhook key did not match.');

            return;
        }

        if (empty($meta['invoice_id'])) {
            log_activity('TwittPay webhook carried no invoice reference.');

            return;
        }

        $this->db->where('id', $meta['invoice_id']);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice) {
            log_activity('TwittPay webhook named an invoice that does not exist: ' . $meta['invoice_id']);

            return;
        }

        $transactionId = isset($response['transaction_id']) ? $response['transaction_id'] : $api->transactionIdFromRequest();

        if ($this->twittpay_gateway->payment_exists($transactionId)) {
            return;
        }

        try {
            $this->record($transactionId, $invoice, false);
        } catch (\Exception $e) {
            log_activity('TwittPay webhook failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify once more and, if the payment really is complete, record it.
     *
     * @param  string $transactionId
     * @param  object $invoice
     * @param  bool   $showAlert   only the customer's own page shows messages
     */
    private function record($transactionId, $invoice, $showAlert)
    {
        $api      = $this->twittpay_gateway->api();
        $response = $this->twittpay_gateway->fetch_payment($transactionId);
        $status   = $api->readStatus($response);

        if ($status === '') {
            if ($showAlert) {
                set_alert('danger', 'The gateway does not know this transaction.');
            }

            return;
        }

        if ($status === 'PENDING') {
            // Sent, not approved by the merchant yet. The gateway calls the webhook
            // again with the answer, so nothing is recorded now.
            if ($showAlert) {
                set_alert('warning', 'Your payment is being checked. The invoice will be updated once it clears.');
            }

            log_activity('TwittPay payment pending for invoice ' . $invoice->id . '. Transaction: ' . $transactionId);

            return;
        }

        if ($status !== 'COMPLETED') {
            if ($showAlert) {
                set_alert('danger', 'The payment was not completed.');
            }

            log_activity('TwittPay payment not completed for invoice ' . $invoice->id . '. Status: ' . $status);

            return;
        }

        $meta = $api->metadata($response);

        // The invoice's own amount, not the converted BDT one.
        $amount = isset($meta['invoice_amount']) ? $meta['invoice_amount'] : (isset($response['amount']) ? $response['amount'] : 0);

        $this->twittpay_gateway->addPayment([
            'amount'        => $amount,
            'invoiceid'     => $invoice->id,
            'paymentmethod' => !empty($response['payment_method']) ? $response['payment_method'] : 'TwittPay',
            'transactionid' => $transactionId,
        ]);

        if ($showAlert) {
            set_alert('success', _l('online_payment_recorded_success'));
        }
    }
}
