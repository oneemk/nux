<?php
/**
 * Ispluka bKash API boundary.
 *
 * Actions:
 *   create_intent - create an idempotent Ispluka payment intent
 *   create        - create the bKash Checkout payment
 *   execute       - execute a payment server-side
 *   query         - query a payment server-side
 *   callback      - verify callback server-to-server and settle the intent state
 *
 * Callback processing is public by design, but it never trusts browser payment
 * status/amount fields. It queries bKash and uses the verified response.
 */

function ispluka_bkash_client()
{
    return new IsplukaBkashClient(IsplukaBkashConfig::load());
}

function ispluka_bkash_admin($permission = 'billing.manage')
{
    $legacyUserId = class_exists('Admin') ? (int) Admin::getID() : 0;
    if ($legacyUserId <= 0) {
        showResult(false, 'Authentication required.', [], ['login' => true]);
    }

    IsplukaRbac::requirePermission($permission, $legacyUserId);
    $context = IsplukaRbac::context($legacyUserId);
    if (!$context) {
        showResult(false, 'No active Ispluka tenant membership found.');
    }

    return $legacyUserId;
}

function ispluka_bkash_callback_payload()
{
    $payload = [];
    foreach (array_merge($_GET, $_POST) as $key => $value) {
        if (is_scalar($value)) {
            $payload[(string) $key] = trim((string) $value);
        }
    }
    return $payload;
}

$action = strtolower(trim((string) _req('action', '')));

try {
    if ($action === 'create_intent') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            showResult(false, 'create_intent requires HTTP POST.');
        }

        $legacyUserId = ispluka_bkash_admin('billing.manage');
        $key = _post('idempotency_key', '');
        $amount = _post('amount', '');
        $customerId = _post('customer_id', '');
        $metadataJson = _post('metadata', '{}');

        if ($key === '' || $amount === '') {
            showResult(false, 'idempotency_key and amount are required.');
        }

        $metadata = json_decode($metadataJson, true);
        if (!is_array($metadata)) {
            showResult(false, 'metadata must be valid JSON object data.');
        }

        $customerLegacyId = $customerId === '' ? null : (int) $customerId;
        if ($customerLegacyId !== null && $customerLegacyId > 0
            && !IsplukaTenantScope::isMapped('customer', $customerLegacyId, $legacyUserId)) {
            showResult(false, 'Customer is not mapped to the active Ispluka tenant.');
        }

        $intent = IsplukaPaymentService::createIntent(
            $key,
            'bkash',
            $amount,
            $customerLegacyId,
            $metadata,
            $legacyUserId
        );

        showResult(true, 'bKash payment intent ready.', $intent->as_array(), [
            'action' => 'create_intent',
            'tenant_id' => IsplukaTenantScope::tenantId($legacyUserId),
            'legacy_write' => false,
        ]);
    }

    if ($action === 'create') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            showResult(false, 'create requires HTTP POST.');
        }

        $legacyUserId = ispluka_bkash_admin('billing.manage');
        $intentId = (int) _post('intent_id', 0);
        if ($intentId <= 0) {
            showResult(false, 'A valid intent_id is required.');
        }

        $callbackUrl = rtrim((string) APP_URL, '/') . '/system/api.php?r=isplukaBkash%26action=callback';
        $payerReference = _post('payer_reference', '');
        $service = new IsplukaBkashCheckoutService(
            new IsplukaBkashPaymentService(ispluka_bkash_client())
        );
        $result = $service->create($intentId, $callbackUrl, $payerReference);

        showResult(true, 'bKash payment created.', $result, [
            'action' => 'create',
            'tenant_id' => IsplukaTenantScope::tenantId($legacyUserId),
        ]);
    }

    if ($action === 'execute' || $action === 'query') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            showResult(false, $action . ' requires HTTP POST.');
        }

        $legacyUserId = ispluka_bkash_admin('billing.manage');
        $paymentId = _post('paymentID', _post('payment_id', ''));
        if ($paymentId === '') {
            showResult(false, 'paymentID is required.');
        }

        $service = new IsplukaBkashCheckoutService(
            new IsplukaBkashPaymentService(ispluka_bkash_client())
        );
        $result = $action === 'execute'
            ? $service->execute($paymentId)
            : $service->query($paymentId);

        showResult(true, 'bKash ' . $action . ' completed.', $result, [
            'action' => $action,
            'tenant_id' => IsplukaTenantScope::tenantId($legacyUserId),
        ]);
    }

    if ($action === 'callback') {
        $payload = ispluka_bkash_callback_payload();
        $client = ispluka_bkash_client();
        $verified = null;

        // First verification obtains the authoritative provider response and
        // lets us resolve the owning tenant/user. The second adapter invocation
        // reuses that verified response, so bKash is queried only once.
        $firstAdapter = new IsplukaBkashAdapter(function (array $raw) use (&$verified, $client) {
            $verified = (new IsplukaBkashVerifiedResolver($client))($raw);
            return $verified;
        });
        $normalized = $firstAdapter->verifyAndNormalize($payload);
        $intentId = (int) $normalized['intent_id'];

        $intent = ORM::for_table('tbl_ispluka_payment_intents')
            ->where('id', $intentId)
            ->find_one();
        if (!$intent) {
            showResult(false, 'Payment intent not found.');
        }

        // Callback has no logged-in legacy user. Resolve an active tenant member
        // from the intent's tenant so the existing tenant-scoped payment service
        // can perform the state transition without accepting a tenant ID from
        // the browser.
        $member = ORM::for_table('tbl_ispluka_tenant_users')
            ->where('tenant_id', (int) $intent->tenant_id)
            ->where('status', 'active')
            ->find_one();
        if (!$member) {
            showResult(false, 'No active tenant membership is available for this payment intent.');
        }

        $cachedAdapter = new IsplukaBkashAdapter(function (array $raw) use ($verified) {
            return $verified;
        });
        $registry = new IsplukaGatewayAdapterRegistry();
        $registry->register($cachedAdapter);
        $processor = new IsplukaGatewayCallbackProcessor($registry);
        $result = $processor->process('bkash', $payload, (int) $member->legacy_user_id);

        showResult(true, 'bKash callback verified and payment intent reconciled.', $result, [
            'action' => 'callback',
            'tenant_id' => (int) $intent->tenant_id,
            'legacy_settlement' => 'NOT_PERFORMED',
        ]);
    }

    showResult(false, 'Unsupported bKash action. Use create_intent, create, execute, query or callback.');
} catch (Throwable $e) {
    showResult(false, $e->getMessage(), [], [
        'action' => $action,
    ]);
}
