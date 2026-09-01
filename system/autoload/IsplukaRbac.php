<?php

/**
 * Ispluka RBAC compatibility layer.
 *
 * This class is intentionally additive: it reads only the new Ispluka
 * foundation tables and does not replace the legacy User/Admin session flow.
 */
class IsplukaRbac
{
    private static $contextCache = null;
    private static $permissionCache = [];

    /**
     * Return the Ispluka authorization context for the current legacy user.
     *
     * The legacy login remains the source of identity. This method only maps
     * that identity to an Ispluka tenant/role when a mapping exists.
     */
    public static function context($legacyUserId = 0)
    {
        if ($legacyUserId <= 0) {
            $legacyUserId = class_exists('Admin') ? (int) Admin::getID() : 0;
        }

        if ($legacyUserId <= 0) {
            return null;
        }

        if (self::$contextCache !== null && self::$contextCache['legacy_user_id'] === $legacyUserId) {
            return self::$contextCache;
        }

        $row = ORM::for_table('tbl_ispluka_tenant_users', 'ispluka_rbac')
            ->table_alias('tu')
            ->select('tu.id', 'membership_id')
            ->select('tu.tenant_id', 'tenant_id')
            ->select('tu.legacy_user_id', 'legacy_user_id')
            ->select('tu.role_id', 'role_id')
            ->select('tu.status', 'membership_status')
            ->select('tu.is_owner', 'is_owner')
            ->select('r.name', 'role_name')
            ->select('r.display_name', 'role_display_name')
            ->select('t.status', 'tenant_status')
            ->join('tbl_ispluka_roles', ['r.id', '=', 'tu.role_id'], 'r')
            ->join('tbl_ispluka_tenants', ['t.id', '=', 'tu.tenant_id'], 't')
            ->where('tu.legacy_user_id', $legacyUserId)
            ->where('tu.status', 'active')
            ->find_one();

        if (!$row) {
            return null;
        }

        self::$contextCache = $row->as_array();
        return self::$contextCache;
    }

    /**
     * Check a permission for a legacy user without changing legacy auth.
     */
    public static function can($permission, $legacyUserId = 0)
    {
        $permission = trim((string) $permission);
        if ($permission === '') {
            return false;
        }

        $context = self::context($legacyUserId);
        if (!$context || $context['tenant_status'] !== 'active') {
            return false;
        }

        $cacheKey = $context['role_id'] . ':' . $permission;
        if (array_key_exists($cacheKey, self::$permissionCache)) {
            return self::$permissionCache[$cacheKey];
        }

        $allowed = ORM::for_table('tbl_ispluka_role_permissions', 'ispluka_rbac')
            ->table_alias('rp')
            ->join('tbl_ispluka_permissions', ['p.id', '=', 'rp.permission_id'], 'p')
            ->where('rp.role_id', $context['role_id'])
            ->where('p.permission_key', $permission)
            ->count() > 0;

        self::$permissionCache[$cacheKey] = $allowed;
        return $allowed;
    }

    /**
     * Enforce a permission in a new Ispluka endpoint/page.
     * Legacy pages are not modified by this helper.
     */
    public static function requirePermission($permission, $legacyUserId = 0)
    {
        if (!self::can($permission, $legacyUserId)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'forbidden',
                'message' => 'You do not have permission to perform this action.'
            ]);
            exit;
        }

        return true;
    }

    /**
     * Map an existing tbl_users ID to an existing tenant and role.
     * No legacy table is changed.
     */
    public static function mapUser($tenantId, $legacyUserId, $roleId, $isOwner = 0)
    {
        $tenantId = (int) $tenantId;
        $legacyUserId = (int) $legacyUserId;
        $roleId = (int) $roleId;

        if ($tenantId <= 0 || $legacyUserId <= 0 || $roleId <= 0) {
            throw new InvalidArgumentException('tenantId, legacyUserId and roleId are required.');
        }

        $tenant = ORM::for_table('tbl_ispluka_tenants')->find_one($tenantId);
        $role = ORM::for_table('tbl_ispluka_roles')->find_one($roleId);
        $user = ORM::for_table('tbl_users')->find_one($legacyUserId);

        if (!$tenant || !$role || !$user) {
            throw new RuntimeException('Tenant, role or legacy user was not found.');
        }

        $membership = ORM::for_table('tbl_ispluka_tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('legacy_user_id', $legacyUserId)
            ->find_one();

        if (!$membership) {
            $membership = ORM::for_table('tbl_ispluka_tenant_users')->create();
            $membership->tenant_id = $tenantId;
            $membership->legacy_user_id = $legacyUserId;
        }

        $membership->role_id = $roleId;
        $membership->status = 'active';
        $membership->is_owner = $isOwner ? 1 : 0;
        $membership->save();

        self::clearCache();
        return $membership;
    }

    public static function clearCache()
    {
        self::$contextCache = null;
        self::$permissionCache = [];
    }
}
