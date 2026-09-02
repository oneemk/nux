<?php
/**
 * Read-only validator for the Ispluka payment settlement ledger.
 *
 * No INSERT, UPDATE or DELETE is performed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../init.php';

$table = 'tbl_ispluka_payment_settlements';
$requiredColumns = [
    'id',
    'tenant_id',
    'payment_intent_id',
    'legacy_transaction_id',
    'invoice',
    'gateway_trx_id',
    'status',
    'error_message',
    'created_at',
    'updated_at',
];

try {
    $db = ORM::get_db();
    $db->query('SELECT 1');
    $stmt = $db->query('SHOW COLUMNS FROM `' . $table . '`');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string) $row['Field']] = $row;
    }

    $missing = [];
    foreach ($requiredColumns as $column) {
        if (!isset($columns[$column])) {
            $missing[] = $column;
        }
    }

    echo "ISPLUKA SETTLEMENT LEDGER VALIDATOR\n";
    echo str_repeat('=', 70) . "\n";
    echo "Database connection: PASS\n";
    echo "Table {$table}: " . ($columns ? 'FOUND' : 'MISSING') . "\n";

    if ($missing) {
        echo "Missing columns: " . implode(', ', $missing) . "\n";
        echo "RESULT: NOT READY\n";
        exit(1);
    }

    $count = (int) ORM::for_table($table)->count();
    echo "Required columns: PASS\n";
    echo "Existing settlement rows: {$count}\n";
    echo "Write activity: NONE\n";
    echo "RESULT: READY\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
