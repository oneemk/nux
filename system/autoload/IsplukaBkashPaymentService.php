<?php
/**
 * Ispluka bKash orchestration boundary.
 *
 * This service coordinates payment-intent data with the bKash HTTP client.
 * It does not write legacy billing records. Credentials are supplied to the
 * client at runtime and never persisted here.
 */
class IsplukaBkashPaymentService
{
    private $client;

    public function __construct(IsplukaBkashClient $client)
    {
        $this->client = $client;
    }

    /**
     * Build a bKash create-payment request from an existing Ispluka intent.
     * No network request is made by this method.
     */
    public function buildCreatePayload($intentId, $callbackUrl, $payerReference = '')
    {
        $intent = IsplukaPaymentService::find($intentId);
        if (!$intent) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }

        if (strtolower((string) $intent->provider) !== 'bkash') {
            throw new RuntimeException('Payment intent provider is not bKash.');
        }

        $callbackUrl = trim((string) $callbackUrl);
        if ($callbackUrl === '' || !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('A valid bKash callback URL is required.');
        }

        $invoice = 'ISPLUKA-' . (int) $intent->id;
        $reference = trim((string) $payerReference);
        if ($reference === '') {
            $reference = $invoice;
        }

        return [
            'mode' => '0011',
            'payerReference' => $reference,
            'callbackURL' => $callbackUrl,
            'amount' => number_format((float) $intent->amount, 2, '.', ''),
            'currency' => (string) $intent->currency,
            'intentId' => (string) $intent->id,
            'merchantInvoiceNumber' => $invoice,
        ];
    }

    public function createPayment($token, array $payload)
    {
        return $this->client->createPayment($token, $payload);
    }

    public function executePayment($token, $paymentId)
    {
        return $this->client->executePayment($token, $paymentId);
    }

    public function queryPayment($token, $paymentId)
    {
        return $this->client->queryPayment($token, $paymentId);
    }
}
