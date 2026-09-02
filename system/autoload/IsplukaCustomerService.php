<?php
/**
 * Ispluka customer domain service.
 *
 * This service is intentionally read-only for the first migration phase.
 * It keeps customer-facing code behind a tenant-scoped service boundary
 * while the existing MySQL legacy tables remain the source of truth.
 */
class IsplukaCustomerService
{
    public static function find($legacyCustomerId, $legacyUserId = 0)
    {
        return IsplukaLegacyRepository::find('customer', $legacyCustomerId, $legacyUserId);
    }

    public static function list($legacyUserId = 0, $limit = 100)
    {
        return IsplukaLegacyRepository::all('customer', $legacyUserId, $limit);
    }

    public static function count($legacyUserId = 0)
    {
        return IsplukaLegacyRepository::count('customer', $legacyUserId);
    }

    public static function findManyByIds(array $legacyCustomerIds, $legacyUserId = 0)
    {
        return IsplukaLegacyRepository::findManyByIds(
            'customer',
            $legacyCustomerIds,
            $legacyUserId
        );
    }
}
