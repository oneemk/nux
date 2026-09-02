<?php
/**
 * Server-side bKash callback verifier.
 *
 * Browser callback fields are treated as untrusted. The paymentID is used only
 * to query/execute bKash server-to-server; the verified response becomes
 * authoritative.
 */
class IsplukaBkashVerifiedResolver
{
    private $client;

    public function __construct(IsplukaBkashClient $client)
    {
        $this->client = $client;
    }

    public function __invoke(array $payload)
    {
        $paymentId = trim((string) ($payload['paymentID'] ?? $payload['paymentId'] ?? ''));
        if ($paymentId === '') {
            throw new InvalidArgumentException('bKash callback paymentID is required.');
        }

        $token = $this->client->grantToken();
        if (!is_array($token)) {
            throw new RuntimeException('bKash token response is invalid.');
        }

        $tokenStatus = trim((string) ($token['statusCode'] ?? ''));
        if ($tokenStatus !== '' && $tokenStatus !== '0000') {
            throw new RuntimeException((string) ($token['statusMessage'] ?? 'bKash token grant failed.'));
        }

        $tokenValue = trim((string) ($token['id_token'] ?? $token['idToken'] ?? ''));
        if ($tokenValue === '') {
            throw new RuntimeException('bKash token response did not contain an id token.');
        }

        // Query first. If the transaction is already completed, do not execute
        // it again. This makes repeated callbacks safe.
        $verified = $this->client->queryPayment($tokenValue, $paymentId);
        $this->assertPaymentId($verified, $paymentId);

        $transactionStatus = strtolower(trim((string) ($verified['transactionStatus'] ?? '')));
        if ($transactionStatus !== 'completed') {
            // The callback status is only a signal to decide whether execution
            // should be attempted; the final payment state always comes from
            // the server-side bKash response.
            $callbackStatus = strtolower(trim((string) ($payload['status'] ?? '')));
            if (in_array($callbackStatus, ['success', 'completed'], true)) {
                $executed = $this->client->executePayment($tokenValue, $paymentId);
                $this->assertPaymentId($executed, $paymentId);

                $verified = $this->client->queryPayment($tokenValue, $paymentId);
                $this->assertPaymentId($verified, $paymentId);
            }
        }

        return $verified;
    }

    private function assertPaymentId(array $response, $paymentId)
    {
        $verifiedPaymentId = trim((string) ($response['paymentID'] ?? $response['paymentId'] ?? ''));
        if ($verifiedPaymentId === '' || !hash_equals($verifiedPaymentId, $paymentId)) {
            throw new RuntimeException('bKash verified paymentID does not match the callback paymentID.');
        }
    }
}
