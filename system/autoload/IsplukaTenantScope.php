<?php
/**
 * Ispluka tenant scope compatibility layer.
 *
 * This class provides tenant-aware reads through the additive mapping table.
 * It never adds tenant_id to or mutates legacy tables.
 */
class IsplukaTenantScope
{
    private static $legacyTableMap = [
        'customers' => 'customer',
        'plans' => 'plan',
        'routers' => 'router',
        'transactions' => 'transaction',
        'user_recharges' => 'user_recharge',
        'payment_gateway' => 'payment_gateway',
        'vouchers' => 'voucher',
    ];

    public static function context($legacyUserId = 0)
    {
        return IsplukaRbac::context($legacyUserId);
    }

    public static function tenantId($legacyUserId = 0)
    {
        $context = self::context($legacyUserId);
        if (!$context || $context['tenant_status'] !== 'active') {
            return 0;
        }
        return (int) $context['tenant_id'];
    }

    /**
     * Return the legacy IDs mapped to the current tenant for an entity type.
     * This is read-only and safe to call before any legacy data migration.
     */
    public static function mappedLegacyIds($entityType, $legacyUserId = 0)
    {
        $tenantId = self::tenantId($legacyUserId);
        if ($tenantId <= 0) {
            return [];
        }

        $entityType = trim((string) $entityType);
        if ($entityType === '') {
            return [];
        }

        $rows = ORM::for_table('tbl_ispluka_legacy_mapping')
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->find_many();

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row->legacy_id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Return mapped legacy records for a known compatibility entity.
     * No record is returned unless it is explicitly mapped to the tenant.
     */
    public static function mappedRecords($entityType, $legacyTable, $legacyUserId = 0)
    {
        $allowedTable = array_search($entityType, self::$legacyTableMap, true);
        if ($allowedTable === false || $legacyTable !== 'tbl_' . $allowedTable) {
            throw new InvalidArgumentException('Unsupported legacy entity/table combination.');
        }

        $ids = self::mappedLegacyIds($entityType, $legacyUserId);
        if (!$ids) {
            return [];
        }

        return ORM::for_table($legacyTable)
            ->where_in('id', $ids)
            ->find_many();
    }

    /**
     * Check whether one legacy record is mapped to the current tenant.
     */
    public static function isMapped($entityType, $legacyId, $legacyUserId = 0)
    {
        $legacyId = (int) $legacyId;
        if ($legacyId <= 0) {
            return false;
        }

        return in_array($legacyId, self::mappedLegacyIds($entityType, $legacyUserId), true);
    }

    public static function supportedEntities()
    {
        return array_values(self::$legacyTableMap);
    }
}
