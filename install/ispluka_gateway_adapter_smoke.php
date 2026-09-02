<?php
/**
 * Read-only smoke test for Ispluka gateway adapter registration/normalization.
 * No database writes and no live gateway calls are performed.
 */

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../system/autoload/IsplukaGatewayAdapterInterface.php';
require_once __DIR__ . '/../system/autoload/IsplukaGatewayAdapterRegistry.php';
require_once __DIR__ . '/../system/autoload/IsplukaBkashAdapter.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$verified = [
    'statusCode' => '0000',
    'transactionStatus' => 'Completed',
    'paymentID' => 'PAYMENT-DEMO-001',
    'trxID' => 'TRX-DEMO-001',
    'amount' => '250.00',
    'merchantInvoiceNumber' => 'ISPLUKA-123',
];

$adapter = new IsplukaBkashAdapter(function (array $payload) use ($verified) {
    return $verified;
});

$registry = new IsplukaGatewayAdapterRegistry();
$registry->register($adapter);

$canonical = $registry->get('bkash')->verifyAndNormalize([
    'paymentID' => 'PAYMENT-DEMO-001',
    'intent_id' => 123,
]);

if ($canonical['provider'] !== 'bkash'
    || $canonical['intent_id'] !== 123
    || $canonical['gateway_trx_id'] !== 'TRX-DEMO-001'
    || $canonical['amount'] !== '250.00'
    || $canonical['status'] !== 'paid'
) {
    fwrite(STDERR, "FAIL: canonical bKash payload mismatch.\n");
    exit(1);
}

if (!$registry->has('bkash') || $registry->providers() !== ['bkash']) {
    fwrite(STDERR, "FAIL: bKash adapter registry mismatch.\n");
    exit(1);
}

echo "READY: bKash adapter registry/normalization smoke test passed (read-only).\n";
