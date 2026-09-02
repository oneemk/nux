<?php
/**
 * Provider-neutral payment gateway callback boundary.
 *
 * Provider-specific adapters must verify the gateway response/signature and
 * normalize it to intent_id, transaction_id, amount and provider before this
 * service is called. This class performs no legacy billing writes.
 */
class IsplukaGatewayCallbackService
{
    public static function complete($intentId, $provider, $gatewayTrxId, $amount, $legacyUserId = 0)
    {
        $provider = strtolower(trim((string) $provider));
        if ($provider === '' || !preg_match('/^[a-z0-9._-]{1,50}$/', $provider)) {
            throw new InvalidArgumentException('Invalid gateway provider.');
        }

        $intent = IsplukaPaymentService::find($intentId, $legacyUserId);
        if (!$intent) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }

        if (strtolower((string) $intent->provider) !== $provider) {
            throw new RuntimeException('Gateway provider does not match the payment intent provider.');
        }

        $completed = IsplukaPaymentService::markPaid(
            $intentId,
            $gatewayTrxId,
            $amount,
            $legacyUserId
        );

        return [
            'intent' => $completed,
            'idempotent' => strtolower((string) $intent->status) === 'paid',
            'legacy_settlement' => 'NOT_PERFORMED',
        ];
    }
}
