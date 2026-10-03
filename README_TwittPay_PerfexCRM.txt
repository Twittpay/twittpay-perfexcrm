===========================================================================
 TWITTPAY - Perfex CRM payment gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your Perfex CRM root - the folder that has application/
   and index.php in it. Two files land in place:

     application/controllers/gateways/Twittpay.php
     application/libraries/gateways/Twittpay_gateway.php

   Keep the file names exactly as they are. Perfex finds a gateway by matching
   the file name to the class name, and Linux servers are case sensitive.

 INSTALL
   1. Setup -> Settings -> Payment Gateways.
   2. Open the "TwittPay" tab and fill in:

        Endpoint URL      your own gateway address, e.g.
                          https://checkout.twittpay.com
                          (the API host shown on your gateway's developer page)

        Brand Key           from your gateway dashboard, under Brands

        USD to BDT Rate   only used when the invoice is not already in BDT

        Description       what shows on the payment record

   3. Tick "Active", save, and pay a test invoice.

 HOW IT WORKS
   * Choosing this gateway on an invoice creates the payment and sends the
     customer straight to the gateway's checkout page.
   * The customer comes back to gateways/twittpay/verify_payment.
   * The gateway's own server calls gateways/twittpay/webhook/<key>.
   * Whichever arrives first verifies the transaction against the API and records
     the payment. The other one sees the payment is already recorded and stops -
     so an invoice cannot be paid twice.
   * PENDING records nothing. The customer has sent the money and your merchant
     has not approved it. The customer is told it is being checked, and the
     webhook records the payment when it clears. Do not ask them to pay twice.

 CURRENCY
   The gateway charges BDT.

   * A BDT invoice is sent as it is.
   * Any other currency is multiplied by the USD to BDT Rate, and the invoice's
     own amount and currency ride along in metadata - so the payment Perfex
     records stays in the invoice's currency.

 WHAT TO WATCH
   * The Endpoint URL is your API host. Pasting the whole endpoint or a trailing
     /api is fine - only the scheme and host are used.
   * The webhook URL must be reachable from the internet. Your gateway's server
     calls it directly.
   * Refunds are not done through the API. Refund on the gateway side, then
     record it in Perfex by hand.

 FIXES OVER THE ORIGINAL
   * The PipraPay API class called $this->decryptSetting() and
     $this->normalizeBaseURL() on itself - neither method existed on that class,
     so every verification died with a fatal error. This port's API class carries
     its own key and URL and has both helpers.
   * The webhook compared an Brand Key sent in a request header. This gateway's
     webhook is not signed and sends no key, so that check would have refused
     every real call. Instead the webhook URL carries a one-off key that is
     checked against the one stored in the payment's metadata, and the payment is
     always verified against the API - a made-up transaction id simply does not
     verify.
   * The original could record the same payment twice, once from the return and
     once from the webhook. Both paths now check the payment records first.
   * The original printed the raw API response into an alert on failure. An error
     string can carry your Brand Key back out, so this port shows a plain message.
   * PENDING was reported to the customer as a danger alert. It is not a failure -
     it is now a warning that says the payment is being checked.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
