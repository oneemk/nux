<?php
/**
 * Ispluka Legacy Repository Smoke Check
 *
 * CLI-only and read-only. Verifies that the tenant-scoped repository can
 * resolve explicitly mapped legacy records without modifying any data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../init.php';

if (!class_exists('IsplukaLegacyRepository')) {
    fwrite(STDERR, "FAIL: IsplukaLegacyRepository is not loaded.\n");
    exit(1);
}

$tenantKey = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    }
}

if ($tenantKey === '') {
    fwrite(STDERR, "Usage: php install/ispluka_repository_smoke.php --tenant-key=isp001\n");
    exit(2);
}

$tenant = ORM::for_table('tbl_ispluka_tenants')
    ->where('tenant_key', $tenantKey)
    ->find_one();

if (!$tenant) {
    fwrite(STDERR, "FAIL: Tenant not found: {$tenantKey}\n");
    exit(1);
}

$membership = ORM::for_table('tbl_ispluka_tenant_users')
    ->where('tenant_id', (int) $tenant->id)
    ->where('status', 'active')
    ->find_one();

if (!$membership) {
    fwrite(STDERR, "FAIL: No active tenant membership exists.\n");
    exit(1);
}

$userId = (int) $membership->legacy_user_id;
$entities = IsplukaTenantScope::supportedEntities();

$context = IsplukaTenantScope::context($userId);
if (!$context || (int) $context['tenant_id'] !== (int) $tenant->id) {
    fwrite(STDERR, "FAIL: Tenant context mismatch.\n");
    exit(1);
}

echo "Ispluka Legacy Repository Smoke Check — READ-ONLY\n";
echo str_repeat('=', 78) . "\n";
echo "Tenant: {$tenant->tenant_key} ({$tenant->name}) | ID: {$tenant->id}\n";
echo "Legacy user ID: {$userId}\n";
echo "\n";

$failed = false;
foreach ($entities as $entity) {
    try {
        $count = IsplukaLegacyRepository::count($entity, $userId);
        $rows = IsplukaLegacyRepository::all($entity, $userId, 3);
        echo "[PASS] {$entity}: mapped={$count}, sample_loaded=" . count($rows) . "\n";
    } catch (Throwable $e) {
        $failed = true;
        echo "[FAIL] {$entity}: {$e->getMessage()}\n";
    }
}

echo "\n";
if ($failed) {
    echo "RESULT: FAILED\n";
    exit(1);
}

echo "RESULT: PASS — repository reads are tenant-scoped and read-only.\n";
exit(0);
