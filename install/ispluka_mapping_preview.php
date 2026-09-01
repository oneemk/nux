<?php
/**
 * Ispluka Legacy Mapping Preview
 *
 * CLI-only, read-only preview. It intentionally does not create mappings or
 * modify any legacy/new database table.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../init.php';

if (!class_exists('IsplukaRbac')) {
    exit("FAIL: IsplukaRbac is not loaded.\n");
}

$tenantKey = null;
$limit = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(1, min(100, (int) substr($arg, 8)));
    }
}

if ($tenantKey === null || $tenantKey === '') {
    fwrite(STDERR, "Usage: php install/ispluka_mapping_preview.php --tenant-key=isp001 [--limit=20]\n");
    exit(2);
}

$tenant = ORM::for_table('tbl_ispluka_tenants')
    ->where('tenant_key', $tenantKey)
    ->find_one();

if (!$tenant) {
    fwrite(STDERR, "FAIL: Tenant not found: {$tenantKey}\n");
    exit(1);
}

if ($tenant->status === 'closed') {
    fwrite(STDERR, "FAIL: Tenant is closed.\n");
    exit(1);
}

$entities = [
    'customer' => ['table' => 'tbl_customers', 'label' => 'username', 'name' => 'fullname'],
    'plan' => ['table' => 'tbl_plans', 'label' => 'name'],
    'router' => ['table' => 'tbl_routers', 'label' => 'name'],
    'transaction' => ['table' => 'tbl_transactions', 'label' => 'invoice', 'name' => 'username'],
    'user_recharge' => ['table' => 'tbl_user_recharges', 'label' => 'username', 'name' => 'namebp'],
    'payment_gateway' => ['table' => 'tbl_payment_gateway', 'label' => 'trx_invoice', 'name' => 'username'],
    'voucher' => ['table' => 'tbl_voucher', 'label' => 'code'],
];

$pdo = ORM::get_db();

function table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
}

function mapped_count($entityType, $tenantId)
{
    return (int) ORM::for_table('tbl_ispluka_legacy_mapping')
        ->where('tenant_id', $tenantId)
        ->where('entity_type', $entityType)
        ->count();
}

function preview_records($entityType, $meta, $limit)
{
    $table = $meta['table'];
    $query = ORM::for_table($table)->order_by_asc('id')->limit($limit)->find_many();
    $rows = [];
    foreach ($query as $row) {
        $data = $row->as_array();
        $rows[] = [
            'id' => (int) $row->id,
            'label' => isset($meta['label'], $data[$meta['label']]) ? (string) $data[$meta['label']] : '',
            'name' => isset($meta['name'], $data[$meta['name']]) ? (string) $data[$meta['name']] : '',
        ];
    }
    return $rows;
}

echo "Ispluka Legacy Mapping Preview — READ-ONLY\n";
echo str_repeat('=', 78) . "\n";
echo "Tenant: {$tenant->tenant_key} ({$tenant->name}) | ID: {$tenant->id} | Status: {$tenant->status}\n";
echo "Preview limit per entity: {$limit}\n";
echo "IMPORTANT: This command performs NO INSERT/UPDATE/DELETE operations.\n\n";

$totalMapped = 0;
foreach ($entities as $entityType => $meta) {
    echo "[{$entityType}] {$meta['table']}\n";

    if (!table_exists($pdo, $meta['table'])) {
        echo "  STATUS: WARN — legacy table does not exist.\n\n";
        continue;
    }

    $mapped = mapped_count($entityType, (int) $tenant->id);
    $totalMapped += $mapped;
    $rows = preview_records($entityType, $meta, $limit);

    echo "  Existing records sampled: " . count($rows) . "\n";
    echo "  Already mapped to tenant: {$mapped}\n";

    if (!$rows) {
        echo "  Sample: (none)\n\n";
        continue;
    }

    echo "  Sample candidates:\n";
    foreach ($rows as $row) {
        $display = $row['label'];
        if ($row['name'] !== '') {
            $display .= ' | ' . $row['name'];
        }
        echo "    - legacy_id={$row['id']} | {$display}\n";
    }
    echo "\n";
}

echo str_repeat('-', 78) . "\n";
echo "Currently mapped records for tenant: {$totalMapped}\n";
echo "RESULT: PREVIEW ONLY — no mappings were created or changed.\n";
echo "Next migration step requires an explicit mapping policy; this script does not guess ownership.\n";
exit(0);
