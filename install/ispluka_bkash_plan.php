<?php
/**
 * Read-only bKash integration planner.
 *
 * Usage:
 *   php install/ispluka_bkash_plan.php --intent-id=123 --callback-url=https://example.com/callback
 *
 * This script never calls bKash and never writes the database.
 */
require_once __DIR__ . '/../init.php';

$options = getopt('', ['intent-id:', 'callback-url:', 'payer-reference::']);
$intentId = isset($options['intent-id']) ? (int) $options['intent-id'] : 0;
$callbackUrl = isset($options['callback-url']) ? trim((string) $options['callback-url']) : '';
$payerReference = isset($options['payer-reference']) ? trim((string) $options['payer-reference']) : '';

if ($intentId < 1 || $callbackUrl === '') {
    fwrite(STDERR, "Usage: php install/ispluka_bkash_plan.php --intent-id=123 --callback-url=https://example.com/callback [--payer-reference=... ]\n");
    exit(2);
}

try {
    $placeholderClient = new IsplukaBkashClient([
        'base_url' => 'https://invalid.local',
        'app_key' => 'runtime-only',
        'app_secret' => 'runtime-only',
        'username' => 'runtime-only',
        'password' => 'runtime-only',
    ]);

    $service = new IsplukaBkashPaymentService($placeholderClient);
    $payload = $service->buildCreatePayload($intentId, $callbackUrl, $payerReference);

    echo json_encode([
        'ready' => true,
        'network_call' => 'NOT_PERFORMED',
        'database_write' => 'NOT_PERFORMED',
        'payload' => $payload,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'NOT READY: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
