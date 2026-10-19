<?php
declare(strict_types=1);

/** Create a PDO connection using settings supplied by the local environment. */
function createDatabaseConnection(): PDO
{
    $host = getenv('KHETSAATHI_DB_HOST') ?: '';
    $port = getenv('KHETSAATHI_DB_PORT') ?: '3306';
    $database = getenv('KHETSAATHI_DB_NAME') ?: 'KhetSaathi';
    $username = getenv('KHETSAATHI_DB_USER') ?: '';
    $password = getenv('KHETSAATHI_DB_PASSWORD');

    if ($host === '' || $username === '' || $password === false) {
        throw new RuntimeException(
            'Set KHETSAATHI_DB_HOST, KHETSAATHI_DB_USER, and KHETSAATHI_DB_PASSWORD in the local environment.'
        );
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
