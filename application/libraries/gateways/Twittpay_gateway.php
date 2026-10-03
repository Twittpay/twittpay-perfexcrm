<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * TwittPay - Perfex CRM payment gateway
 * ---------------------------------------------------------------------------
 * The API client and the gateway both live in this file, the way Perfex expects a
 * gateway library to be laid out.
 *
 * @version 1.0.0
 */
class TwittPayApi
{
    private $apiKey;
    private $baseUrl;

    public function __construct($apiKey, $apiUrl)
    {
        $this->apiKey  = trim((string) $apiKey);
        $this->baseUrl = $this->normalizeBaseUrl($apiUrl);
    }

    /** Create a payment. Returns the decoded response. */
    public function createPayment($payload)
    {
        return $this->sendRequest('/api/payment/create', $payload);
    }

    /** Ask the gateway what really happened to a transaction. */
    public function verifyPayment($transactionId)
    {
        if (empty($transactionId)) {
            return [];
        }

        return $this->sendRequest('/api/payment/verify', ['transaction_id' => $transactionId]);
    }

    /**
     * The webhook arrives form encoded and unsigned, so the only thing taken from
     * it is the transaction id - and that is then verified against the API.
     */
    public function executePayment()
    {
        $transactionId = $this->transactionIdFromRequest();

        if ($transactionId === '') {
            return [];
        }

        return $this->verifyPayment($transactionId);
    }

    /** The id can be on the URL, in a form body, or in a JSON body. */
    public function transactionIdFromRequest()
    {
        foreach ([$_GET, $_POST] as $bag) {
            foreach (['transactionId', 'transaction_id'] as $key) {
                if (!empty($bag[$key])) {
                    return trim((string) $bag[$key]);
                }
            }
        }

        $raw = file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * The verify status: PENDING, COMPLETED or ERROR when the transaction is real,
     * and an empty string when it is not - a miss answers a number, not text.
     */
    public function readStatus($verified)
    {
        if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back from verify as a JSON string. */
    public function metadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    private function normalizeBaseUrl($apiUrl)
    {
        $raw    = rtrim(trim((string) $apiUrl), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        return $scheme . '://' . $host;
    }

    /** One POST to the API. JSON in, array out. */
    private function sendRequest($endpoint, $data)
    {
        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($data['metadata'])) {
            $data['metadata'] = (object) $data['metadata'];
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'API-KEY: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception('Connection error: ' . $error);
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : [];
    }
}

class Twittpay_gateway extends App_gateway
{
    public bool $processingFees = false;

    public function __construct()
    {
        parent::__construct();

        /**
         * REQUIRED
         * Gateway unique id
         */
        $this->setId('twittpay');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('TwittPay');

        $this->setSettings([
            [
                'name'      => 'api_url',
                'encrypted' => true,
                'label'     => 'Endpoint URL (e.g. https://checkout.twittpay.com)',
            ],
            [
                'name'      => 'api_key',
                'encrypted' => true,
                'label'     => 'Brand Key',
            ],
            [
                'name'          => 'currency_rate',
                'label'         => 'USD to BDT Rate (used only when the invoice is not in BDT)',
                'default_value' => '120',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Payment for Invoice {invoice_number}',
            ],
        ]);
    }

    /**
     * Create the payment and send the customer to the gateway's checkout page.
     *
     * @param  array $data
     * @return mixed
     */
    public function process_payment($data)
    {
        $invoice    = $data['invoice'];
        $invoiceUrl = site_url('invoice/' . $invoice->id . '/' . $invoice->hash);

        $contact = null;

        if (is_client_logged_in()) {
            $contact = $this->ci->clients_model->get_contact(get_contact_user_id());
        } elseif (total_rows(db_prefix() . 'contacts', ['userid' => $invoice->clientid]) == 1) {
            $contact = $this->ci->clients_model->get_contact(get_primary_contact_user_id($invoice->clientid));
        }

        $amount   = number_format($data['amount'], 2, '.', '');
        $currency = strtoupper(trim((string) $invoice->currency_name));

        if ($currency === '') {
            $currency = 'BDT';
        }

        // The webhook URL carries a one-off key. The gateway sends it back inside
        // metadata, so the webhook can tell it is answering about this payment.
        $webhookKey = app_generate_hash();

        $returnUrl = site_url(
            'gateways/twittpay/verify_payment?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash
        );
        $webhookUrl = site_url('gateways/twittpay/webhook/' . $webhookKey);

        $payload = [
            'cus_name'    => $contact ? trim($contact->firstname . ' ' . $contact->lastname) : 'Default Name',
            'cus_email'   => ($contact && !empty($contact->email)) ? $contact->email : 'default@gmail.com',
            'amount'      => number_format($this->toBdt($amount, $currency), 2, '.', ''),
            'success_url' => $returnUrl,
            'cancel_url'  => $invoiceUrl,
            'webhook_url' => $webhookUrl,
            'metadata'    => [
                'invoice_id'       => (string) $invoice->id,
                'invoice_amount'   => $amount,
                'invoice_currency' => $currency,
                'webhook_key'      => $webhookKey,
                'source'           => 'perfexcrm',
            ],
        ];

        try {
            $response = $this->api()->createPayment($payload);
        } catch (\Exception $e) {
            log_activity('TwittPay could not create a payment: ' . $e->getMessage());
            $response = [];
        }

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            redirect($response['payment_url']);
        }

        // The raw response is never shown - an error string can carry the Brand Key
        // back out to the customer.
        set_alert('danger', 'The payment could not be started. Please try again, or contact us if it keeps happening.');
        redirect($invoiceUrl);
    }

    /**
     * Verify a transaction. With no id, the id is read off the current request -
     * that is the webhook path.
     *
     * @param  string $transactionId
     * @return mixed
     */
    public function fetch_payment($transactionId = null)
    {
        try {
            $api = $this->api();

            return $transactionId ? $api->verifyPayment($transactionId) : $api->executePayment();
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /** Has this transaction id already been recorded against an invoice? */
    public function payment_exists($transactionId)
    {
        if (empty($transactionId)) {
            return false;
        }

        return total_rows(db_prefix() . 'invoicepaymentrecords', ['transactionid' => $transactionId]) > 0;
    }

    /** A ready API client built from the saved settings. */
    public function api()
    {
        return new TwittPayApi($this->decryptSetting('api_key'), $this->decryptSetting('api_url'));
    }

    /** The gateway charges BDT. Anything else is converted with the set rate. */
    private function toBdt($amount, $currency)
    {
        if ($currency === 'BDT') {
            return (float) $amount;
        }

        $rate = (float) $this->getSetting('currency_rate');

        if ($rate <= 0) {
            $rate = 1;
        }

        return (float) $amount * $rate;
    }
}
