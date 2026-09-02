<?php
/**
 * Read-only bKash configuration check.
 * Never prints credential values and never contacts bKash.
 */
require_once __DIR__ . '/../init.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

try {
    $cfg = IsplukaBkashConfig::load();
    echo "READY: bKash runtime configuration is complete.\n";
    echo "base_url: configured\n";
    echo "app_key: configured\n";
    echo "app_secret: configured\n";
    echo "username: configured\n";
    echo "password: configured\n";
    echo "timeout: " . (int) $cfg['timeout'] . " seconds\n";
    echo "verify_tls: " . ($cfg['verify_tls'] ? 'enabled' : 'disabled') . "\n";
    echo "LIVE_GATEWAY_CALL: not performed\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "NOT READY: " . $e->getMessage() . "\n");
    exit(1);
}
