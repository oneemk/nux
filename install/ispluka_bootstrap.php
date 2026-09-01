<?php
/**
 * Ispluka tenant bootstrap CLI.
 *
 * Usage:
 *   php install/ispluka_bootstrap.php \
 *     --tenant-key=isp001 \
 *     --name="My ISP" \
 *     --slug=my-isp \
 *     --user-id=1
 *
 * Safety:
 * - Uses the existing config.php MySQL connection through init.php/ORM.
 * - Does not alter legacy tables or credentials.
 * - Does not run automatically from a web request.
 * - Safe to re-run for the same tenant/user mapping.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = realpath(__DIR__ . '/..');
require_once $root . '/init.php';

function ispluka_arg($name, $default = null)
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

$tenantKey = trim((string) ispluka_arg('tenant-key', ''));
$name = trim((string) ispluka_arg('name', ''));
$slug = trim((string) ispluka_arg('slug', ''));
$userId = (int) ispluka_arg('user-id', 0);

if ($tenantKey === '' || $name === '' || $slug === '' || $userId <= 0) {
    fwrite(STDERR, "Usage: php install/ispluka_bootstrap.php --tenant-key=isp001 --name=\"My ISP\" --slug=my-isp --user-id=1\n");
    exit(2);
}

if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $tenantKey)) {
    fwrite(STDERR, "Invalid --tenant-key. Use 3-64 letters, numbers, _ or -.\n");
    exit(2);
}

if (!preg_match('/^[a-z0-9][a-z0-9-]{2,119}$/', $slug)) {
    fwrite(STDERR, "Invalid --slug. Use lowercase letters, numbers and hyphens.\n");
    exit(2);
}

$user = ORM::for_table('tbl_users')->find_one($userId);
if (!$user) {
    fwrite(STDERR, "Legacy user #{$userId} was not found. No changes made.\n");
    exit(3);
}

try {
    ORM::get_db()->beginTransaction();

    $tenant = ORM::for_table('tbl_ispluka_tenants')
        ->where('tenant_key', $tenantKey)
        ->find_one();

    if (!$tenant) {
        $tenant = ORM::for_table('tbl_ispluka_tenants')->create();
        $tenant->tenant_key = $tenantKey;
        $tenant->name = $name;
        $tenant->slug = $slug;
        $tenant->status = 'active';
        $tenant->timezone = 'Asia/Dhaka';
        $tenant->currency = 'BDT';
        $tenant->save();
        $tenantId = (int) $tenant->id();
    } else {
        $tenantId = (int) $tenant->id();
        if ($tenant->status === 'closed') {
            throw new RuntimeException('The existing tenant is closed; refusing to reactivate it.');
        }
    }

    $role = ORM::for_table('tbl_ispluka_roles')
        ->where_null('tenant_id')
        ->where('name', 'admin')
        ->find_one();

    if (!$role) {
        throw new RuntimeException('System admin role is missing. Run install/ispluka_foundation.sql first.');
    }

    $membership = ORM::for_table('tbl_ispluka_tenant_users')
        ->where('tenant_id', $tenantId)
        ->where('legacy_user_id', $userId)
        ->find_one();

    if (!$membership) {
        $membership = ORM::for_table('tbl_ispluka_tenant_users')->create();
        $membership->tenant_id = $tenantId;
        $membership->legacy_user_id = $userId;
    }

    $membership->role_id = $role->id();
    $membership->status = 'active';
    $membership->is_owner = 1;
    $membership->save();

    $subscription = ORM::for_table('tbl_ispluka_subscriptions')
        ->where('tenant_id', $tenantId)
        ->where_in('status', ['trial', 'active'])
        ->find_one();

    if (!$subscription) {
        $subscription = ORM::for_table('tbl_ispluka_subscriptions')->create();
        $subscription->tenant_id = $tenantId;
        $subscription->plan_code = 'trial';
        $subscription->plan_name = 'Ispluka Trial';
        $subscription->price = 0;
        $subscription->billing_cycle = 'monthly';
        $subscription->starts_at = date('Y-m-d H:i:s');
        $subscription->ends_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $subscription->status = 'trial';
        $subscription->save();
    }

    ORM::get_db()->commit();

    echo "Ispluka bootstrap completed successfully.\n";
    echo "Tenant ID: {$tenantId}\n";
    echo "Tenant Key: {$tenantKey}\n";
    echo "Admin User ID: {$userId}\n";
    echo "Role: admin\n";
    echo "Subscription: trial\n";
} catch (Throwable $e) {
    if (ORM::get_db()->inTransaction()) {
        ORM::get_db()->rollBack();
    }
    fwrite(STDERR, "Bootstrap failed; transaction rolled back.\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
