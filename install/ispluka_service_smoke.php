<?php
/**
 * Read-only CLI smoke test for Ispluka domain services.
 *
 * Usage:
 *   php install/ispluka_service_smoke.php --tenant-key=isp001
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../init.php';

$tenantKey = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    }
}

if ($tenantKey === '') {
    fwrite(STDERR, "Usage: php install/ispluka_service_smoke.php --tenant-key=isp001\n");
    exit(1);
}

$tenant = ORM::for_table('tbl_ispluka_tenants')
    ->where('tenant_key', $tenantKey)
    ->find_one();

if (!$tenant) {
    fwrite(STDERR, "FAIL: tenant not found: {$tenantKey}\n");
    exit(1);
}

if ((string) $tenant->status === 'closed') {
    fwrite(STDERR, "FAIL: tenant is closed.\n");
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
$context = IsplukaRbac::context($legacyUserId);

if (!$context || (int) $context['tenant_id'] !== (int) $tenant->id) {
    fwrite(STDERR, "FAIL: tenant RBAC context mismatch.\n");
    exit(1);
}

$checks = [
    'customers' => static function () use ($legacyUserId) {
        return [
            IsplukaCustomerService::count($legacyUserId),
            IsplukaCustomerService::list($legacyUserId, 3),
        ];
    },
    'transactions' => static function () use ($legacyUserId) {
        return [
            IsplukaBillingService::transactionCount($legacyUserId),
            IsplukaBillingService::listTransactions($legacyUserId, 3),
        ];
    },
    'recharges' => static function () use ($legacyUserId) {
        return [
            IsplukaBillingService::rechargeCount($legacyUserId),
            IsplukaBillingService::listRecharges($legacyUserId, 3),
        ];
    },
    'payment_gateway' => static function () use ($legacyUserId) {
        return [
            IsplukaBillingService::paymentGatewayCount($legacyUserId),
            IsplukaBillingService::listPaymentGatewayRecords($legacyUserId, 3),
        ];
    },
];

$failed = false;
foreach ($checks as $name => $check) {
    try {
        [$count, $records] = $check();
        $recordCount = is_array($records) ? count($records) : 0;
        echo sprintf("PASS %-18s mapped=%d sample=%d\n", $name, (int) $count, $recordCount);
    } catch (Throwable $e) {
        $failed = true;
        echo sprintf("FAIL %-18s %s\n", $name, $e->getMessage());
    }
}

if ($failed) {
    echo "RESULT: NOT READY\n";
    exit(1);
}

echo "RESULT: READY\n";
echo "No database writes were performed.\n";
exit(0);
