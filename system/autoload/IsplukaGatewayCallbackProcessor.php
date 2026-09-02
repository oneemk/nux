<?php
/**
 * Provider-neutral gateway callback processor.
 *
 * The processor accepts only a provider adapter that performs verification and
 * normalization. It then settles the Ispluka payment intent. Legacy billing
 * settlement remains an explicit separate operation.
 */
class IsplukaGatewayCallbackProcessor
{
    private $registry;

    public function __construct(IsplukaGatewayAdapterRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Process an untrusted provider callback through a registered adapter.
     *
     * @param string $provider
     * @param array $payload Raw provider callback/request data.
     * @param int $legacyUserId Active legacy user identity for tenant lookup.
     * @return array
     */
    public function process($provider, array $payload, $legacyUserId = 0)
    {
        $provider = strtolower(trim((string) $provider));
        $adapter = $this->registry->get($provider);

        $normalized = $adapter->verifyAndNormalize($payload);
        if (!is_array($normalized)) {
            throw new RuntimeException('Gateway adapter returned an invalid normalized response.');
        }

        if (strtolower((string) ($normalized['provider'] ?? '')) !== $provider) {
            throw new RuntimeException('Gateway adapter provider mismatch.');
        }

        $intentId = isset($normalized['intent_id']) ? (int) $normalized['intent_id'] : 0;
        $trxId = trim((string) ($normalized['gateway_trx_id'] ?? ''));
        $amount = (string) ($normalized['amount'] ?? '');
        $status = strtolower(trim((string) ($normalized['status'] ?? '')));

        if ($intentId < 1 || $trxId === '' || $amount === '') {
            throw new RuntimeException('Gateway callback is missing canonical payment fields.');
        }

        if ($status === 'paid') {
            return IsplukaGatewayCallbackService::complete(
                $intentId,
                $provider,
                $trxId,
                $amount,
                $legacyUserId
            );
        }

        if (!in_array($status, ['failed', 'cancelled'], true)) {
            throw new RuntimeException('Unsupported canonical gateway status.');
        }

        // Capture the previous state so the idempotent flag means "already in
        // this state before processing", rather than always becoming true.
        $previous = IsplukaPaymentService::find($intentId, $legacyUserId);
        if (!$previous) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }
        $wasAlreadyInStatus = strtolower((string) $previous->status) === $status;

        // setStatus signature is (intentId, status, gatewayTrxId, legacyUserId).
        $intent = IsplukaPaymentService::setStatus(
            $intentId,
            $status,
            $trxId,
            $legacyUserId
        );

        return [
            'intent' => $intent,
            'idempotent' => $wasAlreadyInStatus,
            'legacy_settlement' => 'NOT_PERFORMED',
        ];
    }
}
