<?php
/**
 * Server-side bKash callback verifier.
 *
 * Browser callback fields are treated as untrusted. The paymentID is used only
 * to query bKash server-to-server; the verified response becomes authoritative.
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

        $tokenValue = trim((string) ($token['id_token'] ?? $token['idToken'] ?? ''));
        if ($tokenValue === '') {
            throw new RuntimeException('bKash token response did not contain an id token.');
        }

        $verified = $this->client->queryPayment($tokenValue, $paymentId);
        if (!is_array($verified)) {
            throw new RuntimeException('bKash payment verification returned an invalid response.');
        }

        $verifiedPaymentId = trim((string) ($verified['paymentID'] ?? $verified['paymentId'] ?? ''));
        if ($verifiedPaymentId === '' || !hash_equals($verifiedPaymentId, $paymentId)) {
            throw new RuntimeException('bKash verified paymentID does not match the callback paymentID.');
        }

        return $verified;
    }
}
