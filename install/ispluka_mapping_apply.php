<?php
/**
 * Ispluka Legacy Mapping Apply
 *
 * Explicit, transactional mapping runner. It NEVER modifies legacy tables;
 * it only inserts rows into tbl_ispluka_legacy_mapping.
 *
 * Default mode is dry-run. A write requires --confirm=MAP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../init.php';

if (!class_exists('IsplukaRbac')) {
    exit("FAIL: IsplukaRbac is not loaded.\n");
}

$tenantKey = '';
$entity = '';
$idsArg = '';
$dryRun = true;
$confirm = '';

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--tenant-key=') === 0) {
        $tenantKey = trim(substr($arg, 13));
    } elseif (strpos($arg, '--entity=') === 0) {
        $entity = trim(substr($arg, 9));
    } elseif (strpos($arg, '--ids=') === 0) {
        $idsArg = trim(substr($arg, 6));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (strpos($arg, '--confirm=') === 0) {
        $confirm = trim(substr($arg, 10));
        $dryRun = false;
    }
}

$definitions = [
    'customer' => ['table' => 'tbl_customers', 'entity' => 'customer'],
    'plan' => ['table' => 'tbl_plans', 'entity' => 'plan'],
    'router' => ['table' => 'tbl_routers', 'entity' => 'router'],
    'transaction' => ['table' => 'tbl_transactions', 'entity' => 'transaction'],
    'user_recharge' => ['table' => 'tbl_user_recharges', 'entity' => 'user_recharge'],
    'payment_gateway' => ['table' => 'tbl_payment_gateway', 'entity' => 'payment_gateway'],
    'voucher' => ['table' => 'tbl_voucher', 'entity' => 'voucher'],
];

function usage()
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php install/ispluka_mapping_apply.php --tenant-key=isp001 --entity=customer --ids=1,2,3 --dry-run\n");
    fwrite(STDERR, "  php install/ispluka_mapping_apply.php --tenant-key=isp001 --entity=customer --ids=1,2,3 --confirm=MAP\n");
    fwrite(STDERR, "\nAllowed entities: customer, plan, router, transaction, user_recharge, payment_gateway, voucher\n");
}

function parse_ids($input)
{
    if ($input === '') {
        return [];
    }

    $parts = preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    $ids = [];
    foreach ($parts as $part) {
        if (!ctype_digit($part) || (int) $part < 1) {
            throw new InvalidArgumentException("Invalid legacy ID: {$part}");
        }
        $ids[(int) $part] = true;
    }
    return array_keys($ids);
}

if ($tenantKey === '' || $entity === '' || $idsArg === '' || !isset($definitions[$entity])) {
    usage();
    exit(2);
}

try {
    $ids = parse_ids($idsArg);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(2);
}

if (!$ids) {
    fwrite(STDERR, "FAIL: At least one legacy ID is required.\n");
    exit(2);
}

if (count($ids) > 500) {
    fwrite(STDERR, "FAIL: Maximum 500 IDs per batch. Use smaller explicit batches.\n");
    exit(2);
}

if (!$dryRun && $confirm !== 'MAP') {
    fwrite(STDERR, "FAIL: Writes require the exact explicit confirmation flag: --confirm=MAP\n");
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

$meta = $definitions[$entity];
$pdo = ORM::get_db();

$tableCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$tableCheck->execute([$meta['table']]);
if ((int) $tableCheck->fetchColumn() !== 1) {
    fwrite(STDERR, "FAIL: Legacy table does not exist: {$meta['table']}\n");
    exit(1);
}

$mappingTableCheck = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$mappingTableCheck->execute(['tbl_ispluka_legacy_mapping']);
if ((int) $mappingTableCheck->fetchColumn() !== 1) {
    fwrite(STDERR, "FAIL: tbl_ispluka_legacy_mapping does not exist. Run the foundation migration first.\n");
    exit(1);
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$legacyStmt = $pdo->prepare(
    "SELECT id FROM `{$meta['table']}` WHERE id IN ({$placeholders}) ORDER BY id ASC"
);
$legacyStmt->execute($ids);
$legacyIds = array_map('intval', $legacyStmt->fetchAll(PDO::FETCH_COLUMN));
$legacySet = array_fill_keys($legacyIds, true);

$missing = array_values(array_diff($ids, $legacyIds));

$existingStmt = $pdo->prepare(
    "SELECT legacy_id, tenant_id FROM tbl_ispluka_legacy_mapping WHERE legacy_table = ? AND entity_type = ? AND legacy_id IN ({$placeholders})"
);
$existingStmt->execute(array_merge([$meta['table'], $entity], $ids));
$existing = [];
foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[(int) $row['legacy_id']] = (int) $row['tenant_id'];
}

$insert = [];
$skipped = [];
$conflicts = [];
foreach ($ids as $id) {
    if (!isset($legacySet[$id])) {
        continue;
    }
    if (isset($existing[$id])) {
        if ($existing[$id] === (int) $tenant->id) {
            $skipped[] = $id;
        } else {
            $conflicts[] = ['id' => $id, 'tenant_id' => $existing[$id]];
        }
        continue;
    }
    $insert[] = $id;
}

echo "Ispluka Legacy Mapping Apply — EXPLICIT / TRANSACTIONAL\n";
echo str_repeat('=', 78) . "\n";
echo "Tenant: {$tenant->tenant_key} ({$tenant->name}) | ID: {$tenant->id}\n";
echo "Entity: {$entity} | Legacy table: {$meta['table']}\n";
echo "Requested IDs: " . count($ids) . "\n";
echo "Mode: " . ($dryRun ? 'DRY-RUN (no writes)' : 'WRITE (confirmed)') . "\n";
echo "\n";

echo "  Existing legacy IDs: " . count($legacyIds) . "\n";
echo "  Missing legacy IDs: " . count($missing) . "\n";
echo "  Already mapped to this tenant: " . count($skipped) . "\n";
echo "  Cross-tenant conflicts: " . count($conflicts) . "\n";
echo "  New mappings eligible: " . count($insert) . "\n";

if ($missing) {
    echo "\nMissing IDs: " . implode(',', $missing) . "\n";
}

if ($conflicts) {
    echo "\nCONFLICTS (not remapped):\n";
    foreach ($conflicts as $conflict) {
        echo "  - legacy_id={$conflict['id']} already belongs to tenant_id={$conflict['tenant_id']}\n";
    }
}

if ($conflicts) {
    echo "\nRESULT: BLOCKED — resolve cross-tenant conflicts before writing.\n";
    exit(1);
}

if ($dryRun) {
    echo "\nRESULT: DRY-RUN ONLY — no INSERT/UPDATE/DELETE performed.\n";
    echo "To apply exactly these explicit IDs, rerun with --confirm=MAP.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $insertStmt = $pdo->prepare(
        'INSERT INTO tbl_ispluka_legacy_mapping (tenant_id, legacy_table, legacy_id, entity_type, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())'
    );

    $inserted = 0;
    foreach ($insert as $id) {
        $insertStmt->execute([(int) $tenant->id, $meta['table'], $id, $entity]);
        $inserted++;
    }

    $pdo->commit();

    echo "\nRESULT: SUCCESS\n";
    echo "  Inserted mappings: {$inserted}\n";
    echo "  Skipped existing: " . count($skipped) . "\n";
    echo "  Legacy tables modified: NO\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\nRESULT: ROLLED BACK — no mapping changes committed.\n");
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
