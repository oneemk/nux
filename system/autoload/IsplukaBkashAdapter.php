<?php
/**
 * bKash adapter boundary for Ispluka.
 *
 * The adapter deliberately does not contain merchant credentials or hard-coded
 * production secrets. A verified-response resolver must be injected by the
 * integration layer. Unverified browser callback fields are never trusted.
 */
class IsplukaBkashAdapter implements IsplukaGatewayAdapterInterface
{
    private $verifiedResponseResolver;

    /**
     * @param callable $verifiedResponseResolver
     *   Receives the raw callback payload and must perform server-to-server
     *   bKash verification/query, returning the verified response array.
     */
    public function __construct(callable $verifiedResponseResolver)
    {
        $this->verifiedResponseResolver = $verifiedResponseResolver;
    }

    public function provider()
    {
        return 'bkash';
    }

    public function verifyAndNormalize(array $payload)
    {
        $verified = call_user_func($this->verifiedResponseResolver, $payload);
        if (!is_array($verified)) {
            throw new RuntimeException('bKash verification did not return a valid response.');
        }

        $status = strtolower(trim((string) ($verified['transactionStatus'] ?? '')));
        $statusCode = trim((string) ($verified['statusCode'] ?? ''));
        $paymentId = trim((string) ($verified['paymentID'] ?? $verified['paymentId'] ?? ''));
        $trxId = trim((string) ($verified['trxID'] ?? $verified['trxId'] ?? ''));
        $amount = trim((string) ($verified['amount'] ?? ''));
        $intentId = $this->extractIntentId($payload, $verified);

        if ($statusCode !== '0000') {
            throw new RuntimeException('bKash verification was not successful.');
        }
        if ($paymentId === '' || $trxId === '' || $amount === '' || $intentId < 1) {
            throw new RuntimeException('bKash verified response is missing required payment fields.');
        }

        if ($status === 'completed') {
            $canonicalStatus = 'paid';
        } elseif (in_array($status, ['failed', 'failure'], true)) {
            $canonicalStatus = 'failed';
        } elseif (in_array($status, ['cancelled', 'canceled', 'cancel'], true)) {
            $canonicalStatus = 'cancelled';
        } else {
            throw new RuntimeException('Unsupported bKash transaction status.');
        }

        return [
            'intent_id' => $intentId,
            'gateway_trx_id' => $trxId,
            'amount' => $amount,
            'status' => $canonicalStatus,
            'provider' => $this->provider(),
            'payment_id' => $paymentId,
        ];
    }

    private function extractIntentId(array $payload, array $verified)
    {
        $candidates = [
            $payload['intent_id'] ?? null,
            $verified['intent_id'] ?? null,
            $verified['merchantInvoiceNumber'] ?? null,
            $verified['merchantInvoice'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && preg_match('/^[1-9][0-9]*$/', trim($candidate)))) {
                return (int) $candidate;
            }

            if (is_string($candidate) && preg_match('/^ISPLUKA-([1-9][0-9]*)$/i', trim($candidate), $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
