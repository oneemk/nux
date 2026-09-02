<?php
/**
 * Ispluka tenant-scoped legacy repository compatibility layer.
 *
 * This repository is intentionally read-only for the first migration phase.
 * It reads existing MySQL legacy tables only through explicit tenant mappings.
 * It never changes legacy schema, credentials, or records.
 */
class IsplukaLegacyRepository
{
    private static $definitions = [
        'customer' => 'tbl_customers',
        'plan' => 'tbl_plans',
        'router' => 'tbl_routers',
        'transaction' => 'tbl_transactions',
        'user_recharge' => 'tbl_user_recharges',
        'payment_gateway' => 'tbl_payment_gateway',
        'voucher' => 'tbl_voucher',
    ];

    private static function tableFor($entityType)
    {
        $entityType = trim((string) $entityType);
        if (!isset(self::$definitions[$entityType])) {
            throw new InvalidArgumentException('Unsupported Ispluka legacy entity.');
        }
        return self::$definitions[$entityType];
    }

    private static function requireTenant($legacyUserId = 0)
    {
        $tenantId = IsplukaTenantScope::tenantId($legacyUserId);
        if ($tenantId <= 0) {
            throw new RuntimeException('No active Ispluka tenant context.');
        }
        return $tenantId;
    }

    /**
     * Find one explicitly mapped legacy record.
     */
    public static function find($entityType, $legacyId, $legacyUserId = 0)
    {
        $legacyId = (int) $legacyId;
        if ($legacyId <= 0) {
            return null;
        }

        $table = self::tableFor($entityType);
        self::requireTenant($legacyUserId);

        $records = IsplukaTenantScope::mappedRecords($entityType, $table, $legacyUserId);
        foreach ($records as $record) {
            if ((int) $record->id === $legacyId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Return explicitly mapped records for the active tenant.
     * Optional limit protects callers from accidentally loading large datasets.
     */
    public static function all($entityType, $legacyUserId = 0, $limit = 100)
    {
        $table = self::tableFor($entityType);
        self::requireTenant($legacyUserId);

        $limit = max(1, min(1000, (int) $limit));
        $ids = IsplukaTenantScope::mappedLegacyIds($entityType, $legacyUserId);
        if (!$ids) {
            return [];
        }

        return ORM::for_table($table)
            ->where_in('id', $ids)
            ->order_by_asc('id')
            ->limit($limit)
            ->find_many();
    }

    /**
     * Count explicitly mapped records for the active tenant.
     */
    public static function count($entityType, $legacyUserId = 0)
    {
        self::tableFor($entityType);
        self::requireTenant($legacyUserId);

        return count(IsplukaTenantScope::mappedLegacyIds($entityType, $legacyUserId));
    }

    /**
     * Return only the IDs explicitly mapped to the active tenant.
     */
    public static function ids($entityType, $legacyUserId = 0)
    {
        self::tableFor($entityType);
        self::requireTenant($legacyUserId);
        return IsplukaTenantScope::mappedLegacyIds($entityType, $legacyUserId);
    }

    /**
     * Search a small, known set of mapped records by ID.
     * This deliberately does not accept arbitrary SQL or column names.
     */
    public static function findManyByIds($entityType, array $legacyIds, $legacyUserId = 0)
    {
        $table = self::tableFor($entityType);
        self::requireTenant($legacyUserId);

        $allowed = array_fill_keys(IsplukaTenantScope::mappedLegacyIds($entityType, $legacyUserId), true);
        $ids = [];
        foreach ($legacyIds as $id) {
            $id = (int) $id;
            if ($id > 0 && isset($allowed[$id])) {
                $ids[$id] = true;
            }
        }

        if (!$ids) {
            return [];
        }

        return ORM::for_table($table)
            ->where_in('id', array_keys($ids))
            ->order_by_asc('id')
            ->find_many();
    }
}
