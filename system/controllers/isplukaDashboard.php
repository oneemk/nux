<?php
/**
 * Ispluka Admin Dashboard foundation endpoint.
 *
 * This controller is additive and read-only. Legacy authentication remains
 * the source of identity; IsplukaRbac adds tenant/permission checks.
 */

if (!class_exists('IsplukaRbac')) {
    showResult(false, 'Ispluka RBAC is not available.');
}

$legacyUserId = class_exists('Admin') ? (int) Admin::getID() : 0;
if ($legacyUserId <= 0) {
    showResult(false, 'Authentication required.', [], ['login' => true]);
}

IsplukaRbac::requirePermission('billing.view', $legacyUserId);

$context = IsplukaRbac::context($legacyUserId);
if (!$context) {
    showResult(false, 'No active Ispluka tenant membership found.');
}

$tenantId = (int) $context['tenant_id'];

$tenant = ORM::for_table('tbl_ispluka_tenants')->find_one($tenantId);
if (!$tenant) {
    showResult(false, 'Ispluka tenant not found.');
}

$activeSubscription = ORM::for_table('tbl_ispluka_subscriptions')
    ->where('tenant_id', $tenantId)
    ->where_in('status', ['trial', 'active'])
    ->order_by_desc('id')
    ->find_one();

$mappedUsers = (int) ORM::for_table('tbl_ispluka_tenant_users')
    ->where('tenant_id', $tenantId)
    ->where('status', 'active')
    ->count();

$auditEvents = (int) ORM::for_table('tbl_ispluka_audit_logs')
    ->where('tenant_id', $tenantId)
    ->count();

// Legacy totals are deliberately marked as compatibility/global figures.
// At this foundation stage the legacy schema has no tenant_id, so these
// numbers must NOT be presented as tenant-isolated customer/billing totals.
$legacyCustomers = (int) ORM::for_table('tbl_customers')->count();
$legacyPlans = (int) ORM::for_table('tbl_plans')->count();
$legacyRouters = (int) ORM::for_table('tbl_routers')->count();

$subscription = null;
if ($activeSubscription) {
    $subscription = $activeSubscription->as_array();
}

$ui->assign('dashboard', [
    'tenant' => [
        'id' => $tenant->id,
        'tenant_key' => $tenant->tenant_key,
        'name' => $tenant->name,
        'slug' => $tenant->slug,
        'status' => $tenant->status,
        'timezone' => $tenant->timezone,
        'currency' => $tenant->currency,
    ],
    'auth' => [
        'legacy_user_id' => $legacyUserId,
        'membership_id' => (int) $context['membership_id'],
        'role' => $context['role_name'],
        'role_display_name' => $context['role_display_name'],
        'is_owner' => (int) $context['is_owner'],
    ],
    'subscription' => $subscription,
    'ispluka' => [
        'active_memberships' => $mappedUsers,
        'audit_events' => $auditEvents,
    ],
    'legacy_compatibility' => [
        'customer_count_global' => $legacyCustomers,
        'plan_count_global' => $legacyPlans,
        'router_count_global' => $legacyRouters,
        'tenant_isolation' => false,
        'note' => 'Legacy totals are global until tenant-aware compatibility mapping is implemented.',
    ],
]);
