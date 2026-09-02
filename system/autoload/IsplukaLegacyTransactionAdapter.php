<?php
/**
 * Compatibility adapter for legacy billing transactions.
 *
 * The read/prepare path is always safe. The legacy writer is explicitly
 * opt-in and requires the exact POST_LEGACY confirmation token supplied by
 * the caller. It uses a dedicated Ispluka settlement ledger to make a paid
 * intent idempotent and performs the new ledger row + legacy transaction in
 * one MySQL transaction.
 *
 * Existing MySQL credentials and legacy schema are untouched.
 */
class IsplukaLegacyTransactionAdapter
{
    private static function loadIntent($intentId, $legacyUserId = 0)
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

        return [$tenantId, $intent];
    }

    private static function metadata($intent)
    {
        $metadata = [];
        if ($intent->metadata !== null && trim((string) $intent->metadata) !== '') {
            $decoded = json_decode((string) $intent->metadata, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Payment intent metadata is invalid JSON.');
            }
            $metadata = $decoded;
        }

        $required = ['plan_name', 'routers', 'type', 'expiration'];
        foreach ($required as $key) {
            if (!isset($metadata[$key]) || trim((string) $metadata[$key]) === '') {
                throw new RuntimeException('Payment intent metadata is missing: ' . $key . '.');
            }
        }

        $type = strtoupper(trim((string) $metadata['type']));
        if (!in_array($type, ['HOTSPOT', 'PPPOE', 'BALANCE'], true)) {
            throw new RuntimeException('Payment intent metadata has an invalid service type.');
        }

        $expiration = trim((string) $metadata['expiration']);
        $date = DateTime::createFromFormat('Y-m-d', $expiration);
        if (!$date || $date->format('Y-m-d') !== $expiration) {
            throw new RuntimeException('Payment intent metadata has an invalid expiration date.');
        }

        $metadata['type'] = $type;
        $metadata['plan_name'] = trim((string) $metadata['plan_name']);
        $metadata['routers'] = trim((string) $metadata['routers']);
        $metadata['expiration'] = $expiration;
        $metadata['expiration_time'] = isset($metadata['expiration_time'])
            ? trim((string) $metadata['expiration_time'])
            : '23:59:59';

        $time = DateTime::createFromFormat('H:i:s', $metadata['expiration_time']);
        if (!$time || $time->format('H:i:s') !== $metadata['expiration_time']) {
            throw new RuntimeException('Payment intent metadata has an invalid expiration time.');
        }

        return $metadata;
    }

    /** Find and validate a paid intent without performing any write. */
    public static function prepareFromIntent($intentId, $legacyUserId = 0)
    {
        [$tenantId, $intent] = self::loadIntent($intentId, $legacyUserId);

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

        $metadata = self::metadata($intent);

        return [
            'tenant_id' => $tenantId,
            'intent_id' => (int) $intent->id,
            'legacy_customer_id' => $customerId,
            'username' => (string) $customer->username,
            'amount' => (string) $intent->amount,
            'currency' => (string) $intent->currency,
            'method' => (string) $intent->provider,
            'gateway_trx_id' => trim((string) $intent->gateway_trx_id),
            'plan_name' => $metadata['plan_name'],
            'routers' => $metadata['routers'],
            'type' => $metadata['type'],
            'expiration' => $metadata['expiration'],
            'expiration_time' => $metadata['expiration_time'],
            'status' => 'paid',
            'posting' => 'NOT_PERFORMED',
        ];
    }

    /**
     * Post one paid intent to tbl_transactions exactly once.
     *
     * This method is intentionally explicit: callers must pass POST_LEGACY.
     * A settlement ledger row is created before the legacy write, and both
     * writes commit or roll back together.
     */
    public static function postFromIntent($intentId, $legacyUserId = 0, $confirmation = '')
    {
        if ((string) $confirmation !== 'POST_LEGACY') {
            throw new RuntimeException('Legacy posting requires explicit POST_LEGACY confirmation.');
        }

        [$tenantId, $intent] = self::loadIntent($intentId, $legacyUserId);
        if ((string) $intent->status !== 'paid') {
            throw new RuntimeException('Only paid payment intents can be posted to legacy billing.');
        }

        $payload = self::prepareFromIntent($intentId, $legacyUserId);
        $invoice = 'ISPLUKA-' . (int) $payload['intent_id'];

        $existingSettlement = ORM::for_table('tbl_ispluka_payment_settlements')
            ->where('tenant_id', $tenantId)
            ->where('payment_intent_id', (int) $intentId)
            ->find_one();

        if ($existingSettlement) {
            if ((string) $existingSettlement->status === 'posted') {
                return $existingSettlement;
            }
            throw new RuntimeException('A settlement for this payment intent already exists and is not posted.');
        }

        $invoiceExists = ORM::for_table('tbl_transactions')
            ->where('invoice', $invoice)
            ->find_one();
        if ($invoiceExists) {
            throw new RuntimeException('Legacy invoice already exists: ' . $invoice . '.');
        }

        $gatewayTrxId = trim((string) $payload['gateway_trx_id']);
        if ($gatewayTrxId !== '') {
            $gatewayExists = ORM::for_table('tbl_payment_gateway')
                ->where('gateway_trx_id', $gatewayTrxId)
                ->find_one();
            if ($gatewayExists) {
                throw new RuntimeException('Gateway transaction ID already exists in legacy payment records.');
            }
        }

        $db = ORM::get_db();
        $db->beginTransaction();

        try {
            $settlement = ORM::for_table('tbl_ispluka_payment_settlements')->create();
            $settlement->tenant_id = $tenantId;
            $settlement->payment_intent_id = (int) $intentId;
            $settlement->invoice = $invoice;
            $settlement->gateway_trx_id = $gatewayTrxId !== '' ? $gatewayTrxId : null;
            $settlement->status = 'posting';
            $settlement->created_at = date('Y-m-d H:i:s');
            $settlement->updated_at = date('Y-m-d H:i:s');
            $settlement->save();

            $now = date('Y-m-d H:i:s');
            $transaction = ORM::for_table('tbl_transactions')->create();
            $transaction->invoice = $invoice;
            $transaction->username = $payload['username'];
            $transaction->user_id = (int) $payload['legacy_customer_id'];
            $transaction->plan_name = $payload['plan_name'];
            $transaction->price = $payload['amount'];
            $transaction->recharged_on = date('Y-m-d');
            $transaction->recharged_time = date('H:i:s');
            $transaction->expiration = $payload['expiration'];
            $transaction->time = $payload['expiration_time'];
            $transaction->method = $payload['method'];
            $transaction->routers = $payload['routers'];
            $transaction->type = $payload['type'];
            $note = 'Ispluka payment intent #' . (int) $intentId;
            if ($gatewayTrxId !== '') {
                $note .= ' | Gateway: ' . $gatewayTrxId;
            }
            $transaction->note = substr($note, 0, 256);
            $transaction->admin_id = (int) $legacyUserId;
            $transaction->save();

            $settlement->legacy_transaction_id = (int) $transaction->id;
            $settlement->status = 'posted';
            $settlement->error_message = null;
            $settlement->updated_at = $now;
            $settlement->save();

            $db->commit();
            return $settlement;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $existingSettlement = ORM::for_table('tbl_ispluka_payment_settlements')
                ->where('tenant_id', $tenantId)
                ->where('payment_intent_id', (int) $intentId)
                ->find_one();
            if ($existingSettlement && (string) $existingSettlement->status === 'posted') {
                return $existingSettlement;
            }

            throw $e;
        }
    }
}
