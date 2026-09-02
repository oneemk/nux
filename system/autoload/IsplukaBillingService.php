<?php
/**
 * Ispluka billing domain service.
 *
 * Read-only compatibility boundary for the existing MySQL billing records.
 * No legacy schema or billing records are modified by this service.
 */
class IsplukaBillingService
{
    public static function findTransaction($legacyTransactionId, $legacyUserId = 0)
    {
        return IsplukaLegacyRepository::find('transaction', $legacyTransactionId, $legacyUserId);
    }

    public static function listTransactions($legacyUserId = 0, $limit = 100)
    {
        return IsplukaLegacyRepository::all('transaction', $legacyUserId, $limit);
    }

    public static function transactionCount($legacyUserId = 0)
    {
        return IsplukaLegacyRepository::count('transaction', $legacyUserId);
    }

    public static function findRecharge($legacyRechargeId, $legacyUserId = 0)
    {
        return IsplukaLegacyRepository::find('user_recharge', $legacyRechargeId, $legacyUserId);
    }

    public static function listRecharges($legacyUserId = 0, $limit = 100)
    {
        return IsplukaLegacyRepository::all('user_recharge', $legacyUserId, $limit);
    }

    public static function rechargeCount($legacyUserId = 0)
    {
        return IsplukaLegacyRepository::count('user_recharge', $legacyUserId);
    }

    public static function findPaymentGatewayRecord($legacyPaymentId, $legacyUserId = 0)
    {
        return IsplukaLegacyRepository::find('payment_gateway', $legacyPaymentId, $legacyUserId);
    }

    public static function listPaymentGatewayRecords($legacyUserId = 0, $limit = 100)
    {
        return IsplukaLegacyRepository::all('payment_gateway', $legacyUserId, $limit);
    }

    public static function paymentGatewayCount($legacyUserId = 0)
    {
        return IsplukaLegacyRepository::count('payment_gateway', $legacyUserId);
    }
}
