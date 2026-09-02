<?php
/**
 * bKash Checkout application service.
 *
 * Coordinates token acquisition, payment creation and server-side execution
 * without persisting gateway credentials or touching legacy billing tables.
 */
class IsplukaBkashCheckoutService
{
    private $paymentService;

    public function __construct(IsplukaBkashPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Create a bKash payment after obtaining a runtime grant token.
     * The returned response is the provider response and is not persisted.
     */
    public function create($intentId, $callbackUrl, $payerReference = '', $legacyUserId = 0)
    {
        $token = $this->grantTokenValue();
        $payload = $this->paymentService->buildCreatePayload(
            $intentId,
            $callbackUrl,
            $payerReference,
            $legacyUserId
        );

        $response = $this->paymentService->createPayment($token, $payload);
        if (!is_array($response)) {
            throw new RuntimeException('bKash create-payment returned an invalid response.');
        }

        return [
            'payment' => $response,
            'payload' => $payload,
        ];
    }

    /**
     * Execute an existing bKash payment using a freshly obtained token.
     */
    public function execute($paymentId)
    {
        return $this->paymentService->executePayment(
            $this->grantTokenValue(),
            $paymentId
        );
    }

    /**
     * Query an existing bKash payment using a freshly obtained token.
     */
    public function query($paymentId)
    {
        return $this->paymentService->queryPayment(
            $this->grantTokenValue(),
            $paymentId
        );
    }

    private function grantTokenValue()
    {
        $token = $this->paymentService->getClient()->grantToken();
        if (!is_array($token)) {
            throw new RuntimeException('bKash token response is invalid.');
        }

        $statusCode = trim((string) ($token['statusCode'] ?? ''));
        if ($statusCode !== '' && $statusCode !== '0000') {
            throw new RuntimeException((string) ($token['statusMessage'] ?? 'bKash token grant failed.'));
        }

        $tokenValue = trim((string) ($token['id_token'] ?? $token['idToken'] ?? ''));
        if ($tokenValue === '') {
            throw new RuntimeException('bKash token response did not contain an id token.');
        }

        return $tokenValue;
    }
}
