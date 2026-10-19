<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

try {
    $pdo = createDatabaseConnection();

    // Read-only connection check and schema inspection; no DDL/DML is executed.
    $check = $pdo->query('SELECT 1 AS connection_ok')->fetch();
    echo 'PDO connection OK: ' . ($check['connection_ok'] ?? 'unknown') . PHP_EOL;

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    echo 'Tables found: ' . count($tables) . PHP_EOL;

    foreach ($tables as $table) {
        // Table names come from SHOW TABLES, then are quoted as identifiers.
        $quotedTable = '`' . str_replace('`', '``', (string) $table) . '`';
        echo PHP_EOL . 'DESCRIBE ' . $table . PHP_EOL;
        foreach ($pdo->query('DESCRIBE ' . $quotedTable)->fetchAll() as $column) {
            printf("  %-24s %-24s %s\n", $column['Field'], $column['Type'], $column['Null']);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Database inspection failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
