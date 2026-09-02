<?php
/**
 * Ispluka payment intent service.
 *
 * This is the first write-capable billing boundary. It stores idempotency
 * state in a new Ispluka table and never writes legacy billing tables.
 * Gateway settlement/legacy transaction posting remains a later step.
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

    /**
     * Get an existing intent for the active tenant and idempotency key.
     */
    public static function findByIdempotencyKey($key, $legacyUserId = 0)
    {
        $tenantId = self::tenantId($legacyUserId);
        $key = self::normalizeKey($key);

        return ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->find_one();
    }

    /**
     * Create a pending intent, or return the existing one for the same key.
     * The unique tenant/key constraint is the final idempotency guard.
     */
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
            // A concurrent request may have won the unique tenant/key race.
            $existing = self::findByIdempotencyKey($key, $legacyUserId);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        return $intent;
    }

    /**
     * Update only the new intent record. No legacy transaction is posted here.
     */
    public static function setStatus($intentId, $status, $gatewayTrxId = null, $legacyUserId = 0)
    {
        $allowed = ['pending', 'paid', 'failed', 'cancelled'];
        $status = strtolower(trim((string) $status));
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid payment intent status.');
        }

        $tenantId = self::tenantId($legacyUserId);
        $intent = ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $intentId)
            ->find_one();

        if (!$intent) {
            throw new RuntimeException('Payment intent not found for the active tenant.');
        }

        $intent->status = $status;
        $intent->gateway_trx_id = $gatewayTrxId === null ? null : trim((string) $gatewayTrxId);
        $intent->updated_at = date('Y-m-d H:i:s');
        $intent->save();

        return $intent;
    }
}
