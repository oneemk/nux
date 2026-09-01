<?php
/**
 * Ispluka Foundation Validator
 *
 * CLI-only, read-only validation. This script never writes to the database.
 * It uses the existing config.php credentials and validates the additive
 * Ispluka foundation before bootstrap/migration is attempted.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../system/orm.php';

$checks = [];

function check_result($name, $ok, $detail = '')
{
    global $checks;
    $checks[] = [$name, (bool) $ok, $detail];
}

try {
    $dsn = 'mysql:host=' . $db_host;
    if (!empty($db_port)) {
        $dsn .= ';port=' . $db_port;
    }
    $dsn .= ';dbname=' . $db_name . ';charset=utf8mb4';

    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    check_result('MySQL connection', true, 'Connected using existing config.php credentials.');

    $version = $pdo->query('SELECT VERSION() AS version')->fetch()['version'];
    check_result('MySQL version', true, $version);

    $required = [
        'tbl_ispluka_tenants',
        'tbl_ispluka_roles',
        'tbl_ispluka_permissions',
        'tbl_ispluka_role_permissions',
        'tbl_ispluka_tenant_users',
        'tbl_ispluka_subscriptions',
        'tbl_ispluka_legacy_mapping',
        'tbl_ispluka_audit_logs',
    ];

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $stmt = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')'
    );
    $stmt->execute($required);
    $found = array_column($stmt->fetchAll(), 'table_name');
    $missing = array_values(array_diff($required, $found));

    check_result(
        'Foundation tables',
        !$missing,
        $missing ? 'Missing: ' . implode(', ', $missing) : 'All 8 foundation tables exist.'
    );

    foreach (['tbl_users', 'tbl_customers', 'tbl_plans', 'tbl_transactions', 'tbl_routers'] as $legacy) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$legacy]);
        check_result('Legacy table: ' . $legacy, (int) $stmt->fetchColumn() === 1, 'Must remain available.');
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_ispluka_permissions");
    $permissionCount = (int) $stmt->fetchColumn();
    check_result('Ispluka permissions seeded', $permissionCount >= 13, $permissionCount . ' permission(s) found.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_ispluka_roles WHERE tenant_id IS NULL AND is_system = 1");
    $roleCount = (int) $stmt->fetchColumn();
    check_result('System roles seeded', $roleCount >= 5, $roleCount . ' system role(s) found.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_ispluka_role_permissions");
    $mappingCount = (int) $stmt->fetchColumn();
    check_result('Role permissions mapped', $mappingCount > 0, $mappingCount . ' role-permission mapping(s) found.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_users");
    $users = (int) $stmt->fetchColumn();
    check_result('Legacy users readable', true, $users . ' user(s) found.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_customers");
    $customers = (int) $stmt->fetchColumn();
    check_result('Legacy customers readable', true, $customers . ' customer(s) found.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_ispluka_tenant_users WHERE legacy_user_id IS NOT NULL");
    $mappedUsers = (int) $stmt->fetchColumn();
    check_result('Existing Ispluka user mappings', true, $mappedUsers . ' mapping(s) currently exist.');

    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_ispluka_tenants WHERE status IN ('active','trial','suspended','closed')");
    $tenants = (int) $stmt->fetchColumn();
    check_result('Tenant table readable', true, $tenants . ' tenant(s) currently exist.');

    // Verify the legacy tables have not acquired an Ispluka tenant_id column.
    // This is intentional at this stage: tenant isolation uses mapping tables.
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'tenant_id' AND table_name IN ('tbl_users','tbl_customers','tbl_plans','tbl_transactions','tbl_routers')"
    );
    $stmt->execute();
    $legacyTenantColumns = (int) $stmt->fetchColumn();
    check_result(
        'Legacy schema untouched',
        $legacyTenantColumns === 0,
        $legacyTenantColumns === 0 ? 'No tenant_id added to audited legacy tables.' : 'Unexpected tenant_id column found on audited legacy table(s).'
    );

} catch (Throwable $e) {
    check_result('MySQL validation', false, $e->getMessage());
}

$failed = 0;
echo "Ispluka Foundation — READ-ONLY VALIDATION\n";
echo str_repeat('=', 72) . "\n";
foreach ($checks as [$name, $ok, $detail]) {
    echo sprintf("[%s] %-32s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
    if (!$ok) {
        $failed++;
    }
}
echo str_repeat('-', 72) . "\n";
echo $failed === 0
    ? "RESULT: READY — validation passed. No database writes were performed.\n"
    : "RESULT: NOT READY — fix failed checks first. No database writes were performed.\n";
exit($failed === 0 ? 0 : 1);
