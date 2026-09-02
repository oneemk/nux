<?php
/**
 * Guarded compatibility adapter for legacy billing transactions.
 *
 * This adapter is intentionally opt-in. It validates an Ispluka payment
 * intent and prepares the legacy transaction payload, but does not write
 * anything until an explicit posting method is introduced in a later phase.
 *
 * Existing MySQL schema and credentials remain untouched.
 */
class IsplukaLegacyTransactionAdapter
{
    public static function prepareFromIntent($intentId, $legacyUserId = 0)
    {
        $tenantId = IsplukaTenantScope::tenantId($legacyUserId);
        if ($tenantId <= 0) {
            throw new RuntimeException('No active Ispluka tenant context.');
        }

        $intent = ORM::for_table('tbl_ispluka_payment_intents')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $intentId)
            ->find_one();

        if (!$intent) {
            throw new RuntimeException('Payment intent not found for active tenant.');
        }

        if ((string) $intent->status !== 'paid') {
            throw new RuntimeException('Only paid payment intents can be prepared for legacy posting.');
        }

        $customerId = (int) $intent->customer_legacy_id;
        if ($customerId <= 0) {
            throw new RuntimeException('Payment intent has no legacy customer ID.');
        }

        $customer = IsplukaCustomerService::find($customerId, $legacyUserId);
        if (!$customer) {
            throw new RuntimeException('Legacy customer is not mapped to the active tenant.');
        }

        return [
            'tenant_id' => $tenantId,
            'intent_id' => (int) $intent->id,
            'legacy_customer_id' => $customerId,
            'username' => (string) $customer->username,
            'amount' => (string) $intent->amount,
            'currency' => (string) $intent->currency,
            'method' => (string) $intent->provider,
            'gateway_trx_id' => (string) $intent->gateway_trx_id,
            'status' => 'paid',
            'posting' => 'NOT_PERFORMED',
        ];
    }
}
