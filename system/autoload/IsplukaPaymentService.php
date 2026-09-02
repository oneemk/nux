<?php
/**
 * Ispluka payment intent service.
 *
 * Tenant-scoped idempotency and gateway callback state live only in
 * Ispluka-owned tables. Legacy billing remains a separate settlement step.
 */
class IsplukaPaymentService
{
    private static function tenantId($legacyUserId = 0)
    {
        $tenantId = IsplukaTenantScope::tenantId($legacyUserId);
        if ($tenantId <= 0) {
            throw new RuntimeException('No active Ispluka tenant context.');
        }
        return $tenantId;
    }

    private static function normalizeKey($key)
    {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) > 191) {
            throw new InvalidArgumentException('A valid idempotency key is required.');
        }
        return $key;
    }

    private static function normalizeAmount($amount)
    {
        if (!is_numeric($amount) || (float) $amount < 0) {
            throw new InvalidArgumentException('Payment amount must be a non-negative number.');
        }
        return number_format((float) $amount, 2, '.', '');
    }

    private static function normalizeProvider($provider)
    {
        $provider = strtolower(trim((string) $provider));
        if ($provider === '' || strlen($provider) > 50 || !preg_match('/^[a-z0-9._-]+$/', $provider)) {
            throw new InvalidArgumentException('Invalid payment provider.');
        }
        return $provider;
    }

    private static function normalizeGatewayTrxId($gatewayTrxId)
    {
        $gatewayTrxId = trim((string) $gatewayTrxId);
        if ($gatewayTrxId === '' || strlen($gatewayTrxId) > 191) {
            throw new InvalidArgumentException('A valid gateway transaction ID is required.');
        }
        return $gatewayTrxId;
    }

    public static function findByIdempotencyKey($key, $legacyUserId = 0)
    {
        $tenantId = self::tenantId($legacyUserId);
        $key = self::normalizeKey($key);
        return ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->find_one();
    }

    public static function createIntent($key, $provider, $amount, $customerLegacyId = null, array $metadata = [], $legacyUserId = 0)
    {
        $tenantId = self::tenantId($legacyUserId);
        $key = self::normalizeKey($key);
        $provider = self::normalizeProvider($provider);
        $amount = self::normalizeAmount($amount);

        $existing = self::findByIdempotencyKey($key, $legacyUserId);
        if ($existing) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $intent = ORM::for_table('tbl_ispluka_payment_intents')->create();
        $intent->tenant_id = $tenantId;
        $intent->idempotency_key = $key;
        $intent->provider = $provider;
        $intent->amount = $amount;
        $intent->currency = 'BDT';
        $intent->customer_legacy_id = $customerLegacyId === null ? null : max(0, (int) $customerLegacyId);
        $intent->status = 'pending';
        $intent->metadata = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $intent->created_at = $now;
        $intent->updated_at = $now;

        try {
            $intent->save();
        } catch (PDOException $e) {
            $existing = self::findByIdempotencyKey($key, $legacyUserId);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
        return $intent;
    }

    public static function find($intentId, $legacyUserId = 0)
    {
        $tenantId = self::tenantId($legacyUserId);
        $intentId = (int) $intentId;
        if ($intentId <= 0) {
            throw new InvalidArgumentException('A valid payment intent ID is required.');
        }
        return ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('id', $intentId)
            ->find_one();
    }

    /**
     * Complete a verified gateway payment. Replaying the same callback is
     * idempotent; a different gateway transaction ID is rejected.
     */
    public static function markPaid($intentId, $gatewayTrxId, $gatewayAmount, $legacyUserId = 0)
    {
        $gatewayTrxId = self::normalizeGatewayTrxId($gatewayTrxId);
        $gatewayAmount = self::normalizeAmount($gatewayAmount);
        $intent = self::find($intentId, $legacyUserId);
        if (!$intent) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }

        $expectedAmount = self::normalizeAmount($intent->amount);
        if (function_exists('bccomp')) {
            $amountMatches = bccomp($expectedAmount, $gatewayAmount, 2) === 0;
        } else {
            $amountMatches = ((float) $expectedAmount === (float) $gatewayAmount);
        }
        if (!$amountMatches) {
            throw new RuntimeException('Gateway amount does not match the payment intent amount.');
        }

        $tenantId = self::tenantId($legacyUserId);
        $sameGateway = ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('gateway_trx_id', $gatewayTrxId)
            ->find_one();
        if ($sameGateway && (int) $sameGateway->id !== (int) $intent->id) {
            throw new RuntimeException('Gateway transaction ID is already linked to another payment intent.');
        }

        $status = strtolower((string) $intent->status);
        if ($status === 'paid') {
            if (trim((string) $intent->gateway_trx_id) === $gatewayTrxId) {
                return $intent;
            }
            throw new RuntimeException('Payment intent is already paid with a different gateway transaction ID.');
        }
        if ($status !== 'pending') {
            throw new RuntimeException('Only pending payment intents can be marked paid.');
        }

        $intent->status = 'paid';
        $intent->gateway_trx_id = $gatewayTrxId;
        $intent->updated_at = date('Y-m-d H:i:s');
        $intent->save();
        return $intent;
    }

    /**
     * Controlled state transition. Paid/cancelled states cannot be downgraded.
     */
    public static function setStatus($intentId, $status, $gatewayTrxId = null, $legacyUserId = 0)
    {
        $allowed = ['pending', 'paid', 'failed', 'cancelled'];
        $status = strtolower(trim((string) $status));
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid payment intent status.');
        }

        $intent = self::find($intentId, $legacyUserId);
        if (!$intent) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }

        $current = strtolower((string) $intent->status);
        if ($current === 'paid' && $status !== 'paid') {
            throw new RuntimeException('A paid payment intent cannot be downgraded.');
        }
        if ($current === 'cancelled' && $status !== 'cancelled') {
            throw new RuntimeException('A cancelled payment intent cannot change state.');
        }
        if ($current === 'failed' && $status === 'pending') {
            throw new RuntimeException('A failed payment intent cannot return to pending.');
        }

        if ($gatewayTrxId !== null) {
            $gatewayTrxId = self::normalizeGatewayTrxId($gatewayTrxId);
            $tenantId = self::tenantId($legacyUserId);
            $other = ORM::for_table('tbl_ispluka_payment_intents')
                ->where('tenant_id', $tenantId)
                ->where('gateway_trx_id', $gatewayTrxId)
                ->find_one();
            if ($other && (int) $other->id !== (int) $intent->id) {
                throw new RuntimeException('Gateway transaction ID is already linked to another payment intent.');
            }
            $intent->gateway_trx_id = $gatewayTrxId;
        }

        $intent->status = $status;
        $intent->updated_at = date('Y-m-d H:i:s');
        $intent->save();
        return $intent;
    }
}
