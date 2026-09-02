<?php
/**
 * Ispluka payment settlement API boundary.
 *
 * Actions:
 *   preview - read-only validation of a paid intent
 *   post    - explicit POST_LEGACY settlement into legacy billing
 *
 * Legacy authentication remains the source of identity. This endpoint adds
 * tenant RBAC and never changes database credentials or legacy schema.
 */

if (!class_exists('IsplukaRbac') || !class_exists('IsplukaLegacyTransactionAdapter')) {
    showResult(false, 'Ispluka settlement components are not available.');
}

$legacyUserId = class_exists('Admin') ? (int) Admin::getID() : 0;
if ($legacyUserId <= 0) {
    showResult(false, 'Authentication required.', [], ['login' => true]);
}

IsplukaRbac::requirePermission('billing.manage', $legacyUserId);

$context = IsplukaRbac::context($legacyUserId);
if (!$context) {
    showResult(false, 'No active Ispluka tenant membership found.');
}

$action = strtolower(trim((string) _req('action', 'preview')));
$intentId = (int) _req('intent_id', 0);

if ($intentId <= 0) {
    showResult(false, 'A valid intent_id is required.');
}

try {
    if ($action === 'preview') {
        $payload = IsplukaLegacyTransactionAdapter::prepareFromIntent(
            $intentId,
            $legacyUserId
        );

        showResult(true, 'Settlement preview ready. No legacy billing record was written.', $payload, [
            'action' => 'preview',
            'legacy_write' => false,
            'tenant_id' => (int) $context['tenant_id'],
        ]);
    }

    if ($action === 'post') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            showResult(false, 'Legacy settlement posting requires HTTP POST.');
        }

        $confirmation = _post('confirm', '');
        if ($confirmation !== 'POST_LEGACY') {
            showResult(false, 'Explicit POST_LEGACY confirmation is required.');
        }

        $settlement = IsplukaLegacyTransactionAdapter::postFromIntent(
            $intentId,
            $legacyUserId,
            $confirmation
        );

        $result = $settlement ? $settlement->as_array() : [];
        showResult(true, 'Payment intent settled to legacy billing.', $result, [
            'action' => 'post',
            'legacy_write' => true,
            'tenant_id' => (int) $context['tenant_id'],
            'idempotent' => true,
        ]);
    }

    showResult(false, 'Unsupported settlement action. Use preview or post.');
} catch (Throwable $e) {
    showResult(false, $e->getMessage(), [], [
        'action' => $action,
        'intent_id' => $intentId,
        'tenant_id' => (int) $context['tenant_id'],
    ]);
}
