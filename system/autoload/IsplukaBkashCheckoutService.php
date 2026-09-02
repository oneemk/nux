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
    public function create($intentId, $callbackUrl, array $config, $payerReference = '')
    {
        $token = $this->paymentService->getClient()->grantToken();
        $payload = $this->paymentService->buildCreatePayload(
            $intentId,
            $callbackUrl,
            $payerReference
        );

        $response = $this->paymentService->createPayment($token['id_token'] ?? $token['idToken'] ?? '', $payload);
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
    public function execute($paymentId, array $config)
    {
        $token = $this->paymentService->getClient()->grantToken();
        $tokenValue = $token['id_token'] ?? $token['idToken'] ?? '';
        if ($tokenValue === '') {
            throw new RuntimeException('bKash token response did not contain an id token.');
        }

        return $this->paymentService->executePayment($tokenValue, $paymentId);
    }

    /**
     * Query an existing bKash payment using a freshly obtained token.
     */
    public function query($paymentId, array $config)
    {
        $token = $this->paymentService->getClient()->grantToken();
        $tokenValue = $token['id_token'] ?? $token['idToken'] ?? '';
        if ($tokenValue === '') {
            throw new RuntimeException('bKash token response did not contain an id token.');
        }

        return $this->paymentService->queryPayment($tokenValue, $paymentId);
    }
}
