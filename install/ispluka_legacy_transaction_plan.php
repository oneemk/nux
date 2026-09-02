<?php
/**
 * Ispluka Legacy Transaction Settlement Plan.
 *
 * CLI-only, read-only safety gate for the future legacy transaction writer.
 * It verifies the fields required by tbl_transactions, builds a deterministic
 * invoice candidate, and checks invoice/gateway transaction collisions.
 *
 * No INSERT, UPDATE or DELETE is performed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../init.php';

$tenantKey = '';
$intentId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    } elseif (strpos($arg, '--intent-id=') === 0) {
        $intentId = (int) substr($arg, 12);
    }
}

if ($tenantKey === '' || $intentId <= 0) {
    fwrite(STDERR, "Usage: php install/ispluka_legacy_transaction_plan.php --tenant-key=isp001 --intent-id=1\n");
    exit(2);
}

$tenant = ORM::for_table('tbl_ispluka_tenants')
    ->where('tenant_key', $tenantKey)
    ->find_one();

if (!$tenant || (string) $tenant->status === 'closed') {
    fwrite(STDERR, "FAIL: active tenant not found.\n");
    exit(1);
}

$membership = ORM::for_table('tbl_ispluka_tenant_users')
    ->where('tenant_id', (int) $tenant->id)
    ->where('status', 'active')
    ->order_by_asc('id')
    ->find_one();

if (!$membership) {
    fwrite(STDERR, "FAIL: no active tenant membership found.\n");
    exit(1);
}

$legacyUserId = (int) $membership->legacy_user_id;

try {
    $payload = IsplukaLegacyTransactionAdapter::prepareFromIntent($intentId, $legacyUserId);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}

$intent = ORM::for_table('tbl_ispluka_payment_intents')
    ->where('tenant_id', (int) $tenant->id)
    ->where('id', $intentId)
    ->find_one();

$metadata = [];
if ($intent && $intent->metadata !== null && trim((string) $intent->metadata) !== '') {
    $decoded = json_decode((string) $intent->metadata, true);
    if (is_array($decoded)) {
        $metadata = $decoded;
    }
}

$required = [
    'plan_name' => isset($metadata['plan_name']) ? trim((string) $metadata['plan_name']) : '',
    'routers' => isset($metadata['routers']) ? trim((string) $metadata['routers']) : '',
    'type' => isset($metadata['type']) ? strtoupper(trim((string) $metadata['type'])) : '',
    'expiration' => isset($metadata['expiration']) ? trim((string) $metadata['expiration']) : '',
];

$missing = [];
foreach ($required as $key => $value) {
    if ($value === '') {
        $missing[] = $key;
    }
}

if ($required['type'] === 'PPPOE') {
    $required['type'] = 'PPPOE';
}

$allowedTypes = ['HOTSPOT', 'PPPOE', 'BALANCE'];
if ($required['type'] !== '' && !in_array($required['type'], $allowedTypes, true)) {
    $missing[] = 'type(valid: Hotspot|PPPOE|Balance)';
}

$invoice = 'ISPLUKA-' . (int) $payload['intent_id'];
$invoiceExists = ORM::for_table('tbl_transactions')
    ->where('invoice', $invoice)
    ->find_one();

$gatewayTrxId = trim((string) $payload['gateway_trx_id']);
$gatewayExists = null;
if ($gatewayTrxId !== '') {
    $gatewayExists = ORM::for_table('tbl_payment_gateway')
        ->where('gateway_trx_id', $gatewayTrxId)
        ->find_one();
}

$expiration = $required['expiration'];
$expirationValid = false;
if ($expiration !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $expiration);
    $expirationValid = $dt && $dt->format('Y-m-d') === $expiration;
    if (!$expirationValid) {
        $missing[] = 'expiration(valid: YYYY-MM-DD)';
    }
}

$ready = !$missing && !$invoiceExists && !$gatewayExists;

echo "Ispluka Legacy Transaction Settlement Plan — READ-ONLY\n";
echo str_repeat('=', 78) . "\n";
echo "Tenant: {$tenant->tenant_key} ({$tenant->name}) | ID: {$tenant->id}\n";
echo "Intent: {$payload['intent_id']} | Status: {$payload['status']}\n";
echo "Customer: {$payload['legacy_customer_id']} | Username: {$payload['username']}\n";
echo "Amount: {$payload['amount']} {$payload['currency']} | Method: {$payload['method']}\n";
echo "Gateway transaction ID: " . ($gatewayTrxId === '' ? '(none)' : $gatewayTrxId) . "\n\n";

echo "Legacy invoice candidate: {$invoice}\n";
echo "Invoice collision: " . ($invoiceExists ? 'YES — BLOCKED' : 'NO') . "\n";
echo "Gateway transaction collision: " . ($gatewayExists ? 'YES — BLOCKED' : 'NO') . "\n\n";

echo "Required legacy transaction fields from intent metadata:\n";
foreach ($required as $key => $value) {
    echo "  - {$key}: " . ($value === '' ? '(missing)' : $value) . "\n";
}

echo "\nLegacy write operation: NOT_PERFORMED\n";
echo "Legacy payment-gateway update: NOT_PERFORMED\n";

echo "\n" . str_repeat('-', 78) . "\n";
if ($ready) {
    echo "RESULT: READY — duplicate checks passed and required settlement metadata exists.\n";
    echo "NOTE: This command still performs no legacy write.\n";
    exit(0);
}

echo "RESULT: BLOCKED — legacy transaction posting is not safe yet.\n";
if ($missing) {
    echo "Missing/invalid requirements: " . implode(', ', $missing) . "\n";
}
exit(1);
