<?php
/**
 * Ispluka Legacy Mapping Verify
 *
 * CLI-only, read-only integrity check for tbl_ispluka_legacy_mapping.
 * It never changes legacy or Ispluka data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../init.php';

$tenantKey = '';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    }
}

if ($tenantKey === '') {
    fwrite(STDERR, "Usage: php install/ispluka_mapping_verify.php --tenant-key=isp001\n");
    exit(2);
}

$definitions = [
    'customer' => 'tbl_customers',
    'plan' => 'tbl_plans',
    'router' => 'tbl_routers',
    'transaction' => 'tbl_transactions',
    'user_recharge' => 'tbl_user_recharges',
    'payment_gateway' => 'tbl_payment_gateway',
    'voucher' => 'tbl_voucher',
];

$tenant = ORM::for_table('tbl_ispluka_tenants')
    ->where('tenant_key', $tenantKey)
    ->find_one();

if (!$tenant) {
    fwrite(STDERR, "FAIL: Tenant not found: {$tenantKey}\n");
    exit(1);
}

$pdo = ORM::get_db();

$mappingCheck = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tbl_ispluka_legacy_mapping'"
);
if ((int) $mappingCheck->fetchColumn() !== 1) {
    fwrite(STDERR, "FAIL: tbl_ispluka_legacy_mapping does not exist.\n");
    exit(1);
}

$tenantId = (int) $tenant->id;
$total = (int) ORM::for_table('tbl_ispluka_legacy_mapping')
    ->where('tenant_id', $tenantId)
    ->count();

$invalidEntity = (int) ORM::for_table('tbl_ispluka_legacy_mapping')
    ->where('tenant_id', $tenantId)
    ->where_not_in('entity_type', array_keys($definitions))
    ->count();

$invalidTable = 0;
$orphan = 0;
$rowsByEntity = [];

foreach ($definitions as $entity => $table) {
    $rowsByEntity[$entity] = (int) ORM::for_table('tbl_ispluka_legacy_mapping')
        ->where('tenant_id', $tenantId)
        ->where('entity_type', $entity)
        ->where('legacy_table', $table)
        ->count();

    $invalidTable += (int) ORM::for_table('tbl_ispluka_legacy_mapping')
        ->where('tenant_id', $tenantId)
        ->where('entity_type', $entity)
        ->where_not_equal('legacy_table', $table)
        ->count();

    $tableExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $tableExists->execute([$table]);
    if ((int) $tableExists->fetchColumn() !== 1) {
        $orphan += $rowsByEntity[$entity];
        continue;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tbl_ispluka_legacy_mapping m LEFT JOIN `{$table}` l ON l.id = m.legacy_id WHERE m.tenant_id = ? AND m.entity_type = ? AND m.legacy_table = ? AND l.id IS NULL"
    );
    $stmt->execute([$tenantId, $entity, $table]);
    $orphan += (int) $stmt->fetchColumn();
}

$duplicatePairsStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM (SELECT legacy_table, legacy_id, entity_type, COUNT(*) c FROM tbl_ispluka_legacy_mapping GROUP BY legacy_table, legacy_id, entity_type HAVING COUNT(*) > 1) d JOIN tbl_ispluka_legacy_mapping m ON m.legacy_table = d.legacy_table AND m.legacy_id = d.legacy_id AND m.entity_type = d.entity_type WHERE m.tenant_id = ?'
);
$duplicatePairsStmt->execute([$tenantId]);
$duplicateRows = (int) $duplicatePairsStmt->fetchColumn();

$crossTenantStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM (SELECT legacy_table, legacy_id, entity_type, COUNT(DISTINCT tenant_id) c FROM tbl_ispluka_legacy_mapping GROUP BY legacy_table, legacy_id, entity_type HAVING COUNT(DISTINCT tenant_id) > 1) x JOIN tbl_ispluka_legacy_mapping m ON m.legacy_table = x.legacy_table AND m.legacy_id = x.legacy_id AND m.entity_type = x.entity_type WHERE m.tenant_id = ?'
);
$crossTenantStmt->execute([$tenantId]);
$crossTenant = (int) $crossTenantStmt->fetchColumn();

echo "Ispluka Legacy Mapping Verify — READ-ONLY\n";
echo str_repeat('=', 78) . "\n";
echo "Tenant: {$tenant->tenant_key} ({$tenant->name}) | ID: {$tenantId}\n\n";
echo "Total mappings: {$total}\n";
echo "Invalid entity types: {$invalidEntity}\n";
echo "Invalid entity/table pairs: {$invalidTable}\n";
echo "Orphan mappings (legacy row missing): {$orphan}\n";
echo "Duplicate mapping rows: {$duplicateRows}\n";
echo "Cross-tenant same legacy record: {$crossTenant}\n\n";

echo "By entity:\n";
foreach ($rowsByEntity as $entity => $count) {
    echo "  - {$entity}: {$count}\n";
}

$issues = $invalidEntity + $invalidTable + $orphan + $duplicateRows + $crossTenant;
echo "\n" . str_repeat('-', 78) . "\n";
if ($issues === 0) {
    echo "RESULT: READY — no mapping integrity issues detected.\n";
    exit(0);
}

echo "RESULT: NOT READY — {$issues} integrity issue(s) detected.\n";
exit(1);
