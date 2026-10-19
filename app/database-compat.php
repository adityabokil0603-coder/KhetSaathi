<?php
declare(strict_types=1);

/**
 * The earlier multi-user migration made owner_user_id mandatory on operational
 * rows. The restored single-workspace application does not use it for access
 * control; this helper supplies an existing account ID only to satisfy that
 * unchanged foreign key when creating new operational records.
 */
function databaseCompatibilityOwnerId(PDO $pdo): int
{
    $statement = $pdo->query("SELECT user_id FROM app_user WHERE role = 'admin' ORDER BY user_id LIMIT 1");
    $ownerId = $statement->fetchColumn();

    if ($ownerId === false) {
        throw new RuntimeException('The migrated database has no administrator row for its required ownership reference.');
    }

    return (int) $ownerId;
}
