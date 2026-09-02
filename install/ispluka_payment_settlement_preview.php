<?php
/**
 * Read-only preview for future payment settlement compatibility.
 *
 * Usage:
 *   php install/ispluka_payment_settlement_preview.php --tenant-key=isp001 --intent-id=1
 *
 * This script never writes payment or legacy billing records.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../init.php';

$tenantKey = '';
$intentId = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    } elseif (strpos($arg, '--intent-id=') === 0) {
        $intentId = (int) substr($arg, 12);
    }
}

if ($tenantKey === '' || $intentId <= 0) {
    fwrite(STDERR, "Usage: php install/ispluka_payment_settlement_preview.php --tenant-key=isp001 --intent-id=1\n");
    exit(1);
}

$tenant = ORM::for_table('tbl_ispluka_tenants')->where('tenant_key', $tenantKey)->find_one();
if (!$tenant || (string) $tenant->status === 'closed') {
    fwrite(STDERR, "FAIL: active tenant not found.\n");
    exit(1);
}

$intent = ORM::for_table('tbl_ispluka_payment_intents')
    ->where('tenant_id', (int) $tenant->id)
    ->where('id', $intentId)
    ->find_one();

if (!$intent) {
    fwrite(STDERR, "FAIL: payment intent not found for tenant.\n");
    exit(1);
}

echo "PAYMENT SETTLEMENT PREVIEW\n";
echo "tenant_id=" . (int) $tenant->id . "\n";
echo "intent_id=" . (int) $intent->id . "\n";
echo "status=" . (string) $intent->status . "\n";
echo "provider=" . (string) $intent->provider . "\n";
echo "amount=" . (string) $intent->amount . " " . (string) $intent->currency . "\n";
echo "customer_legacy_id=" . (int) $intent->customer_legacy_id . "\n";
echo "gateway_trx_id=" . (string) $intent->gateway_trx_id . "\n";

echo "legacy_transaction_posting=NOT_PERFORMED\n";
echo "legacy_payment_gateway_update=NOT_PERFORMED\n";
echo "RESULT: PREVIEW ONLY\n";
exit(0);
