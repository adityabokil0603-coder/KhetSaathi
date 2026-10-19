<?php
declare(strict_types=1);

// This endpoint accepts POST only. All entity/table names below are fixed
// application choices; user input supplies only the validated integer ID.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('This action requires a POST request.');
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/data-structures/RecentActivityLinkedList.php';

startKhetSaathiSession();
sendAppSecurityHeaders();

$entities = [
    'farmer' => [
        'table' => 'farmer',
        'primary_key' => 'Farmer_ID',
        'page' => 'farmers.php',
        'id_label' => 'Farmer ID',
        'dependencies' => [
            ['table' => 'field', 'column' => 'Farmer_ID', 'label' => 'field'],
        ],
    ],
    'field' => [
        'table' => 'field',
        'primary_key' => 'Field_ID',
        'page' => 'fields.php',
        'id_label' => 'Field ID',
        'dependencies' => [
            ['table' => 'planting_record', 'column' => 'Field_ID', 'label' => 'planting record'],
            ['table' => 'support_request', 'column' => 'Field_ID', 'label' => 'support request'],
        ],
    ],
    'crop' => [
        'table' => 'crop',
        'primary_key' => 'Crop_ID',
        'page' => 'crops.php',
        'id_label' => 'Crop ID',
        'dependencies' => [
            ['table' => 'crop_guide', 'column' => 'Crop_ID', 'label' => 'crop guide record'],
            ['table' => 'crop_season', 'column' => 'Crop_ID', 'label' => 'season mapping'],
            ['table' => 'crop_soil_suitability', 'column' => 'Crop_ID', 'label' => 'soil suitability mapping'],
            ['table' => 'planting_record', 'column' => 'Crop_ID', 'label' => 'planting record'],
        ],
    ],
    'planting' => [
        'table' => 'planting_record',
        'primary_key' => 'Planting_ID',
        'page' => 'plantings.php',
        'id_label' => 'Planting ID',
        'dependencies' => [],
    ],
    'support' => [
        'table' => 'support_request',
        'primary_key' => 'Request_ID',
        'page' => 'support.php',
        'id_label' => 'Support request ID',
        'dependencies' => [],
    ],
];

function finishDeleteRequest(string $page, string $type, string $message): never
{
    $_SESSION['action_flash'] = ['type' => $type, 'message' => $message];
    session_write_close();
    header('Location: ' . $page, true, 303);
    exit;
}

function setRecentActivity(string $message): void
{
    $activities = is_array($_SESSION['recent_activity'] ?? null)
        ? $_SESSION['recent_activity']
        : [];
    $list = RecentActivityLinkedList::fromNewestFirstArray($activities);
    $list->insertAtHead($message);
    $_SESSION['recent_activity'] = $list->toArray();
}

function removeSupportUndoEntries(int $requestId): void
{
    $history = $_SESSION['support_undo_history'] ?? [];
    if (!is_array($history)) {
        $_SESSION['support_undo_history'] = [];
        return;
    }

    $remaining = [];
    foreach ($history as $change) {
        $entryId = is_array($change)
            ? filter_var($change['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($entryId === false || $entryId !== $requestId) {
            $remaining[] = $change;
        }
    }

    // The session representation is bottom-to-top, matching stack push order.
    $_SESSION['support_undo_history'] = $remaining;
}

function deleteOneRecord(PDO $pdo, array $entity, int $recordId): void
{
    $pdo->beginTransaction();

    $lock = $pdo->prepare('SELECT ' . $entity['primary_key'] . ' FROM ' . $entity['table']
        . ' WHERE ' . $entity['primary_key'] . ' = :record_id FOR UPDATE');
    $lock->execute(['record_id' => $recordId]);
    $record = $lock->fetch();
    if (!is_array($record)) {
        throw new DomainException('not_found');
    }
    foreach ($entity['dependencies'] as $dependency) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . $dependency['table']
            . ' WHERE ' . $dependency['column'] . ' = :record_id'
        );
        $check->execute(['record_id' => $recordId]);
        $count = (int) $check->fetchColumn();

        if ($count > 0) {
            throw new DomainException('dependency:' . $dependency['label'] . ':' . $count);
        }
    }

    $delete = $pdo->prepare('DELETE FROM ' . $entity['table'] . ' WHERE ' . $entity['primary_key'] . ' = :record_id');
    $delete->execute(['record_id' => $recordId]);
    if ($delete->rowCount() !== 1) {
        throw new RuntimeException('The selected record was not deleted.');
    }

    $pdo->commit();
}

function resetOperationalData(PDO $pdo): void
{
    $pdo->beginTransaction();

    // Child rows are removed before parents. This uses DELETE (not TRUNCATE),
    // so the whole reset can roll back and auto-increment counters are retained.
    foreach (['support_request', 'planting_record', 'field', 'farmer'] as $table) {
        $statement = $pdo->prepare('DELETE FROM ' . $table);
        $statement->execute();
    }

    $pdo->commit();
}

if (!isValidAppCsrfToken($_POST['csrf_token'] ?? null)) {
    finishDeleteRequest('index.php', 'error', 'Your form session expired. Refresh the page and try again.');
}

$action = $_POST['action'] ?? null;
if ($action === 'reset_operational') {
    if (($_POST['confirmation'] ?? null) !== 'RESET') {
        finishDeleteRequest('index.php', 'error', 'Type RESET exactly to confirm the operational-data reset.');
    }

    $pdo = null;
    try {
        $pdo = createDatabaseConnection();
        resetOperationalData($pdo);
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('KhetSaathi operational reset failed (SQLSTATE ' . $error->getCode() . ').');
        finishDeleteRequest('index.php', 'error', 'The operational-data reset did not complete. Any active transaction was rolled back; refresh the dashboard before retrying.');
    }

    // Queue and crop hash are rebuilt from MySQL on each page request.
    // Clear this session's undo history and old activity, then record the reset.
    $_SESSION['support_undo_history'] = [];
    $_SESSION['recent_activity'] = [];
    setRecentActivity('Operational data reset completed.');
    finishDeleteRequest('index.php', 'success', 'Operational data was reset. Reference catalogue data was preserved.');
}

$entityName = $_POST['entity'] ?? null;
$recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_string($entityName) || !isset($entities[$entityName]) || $recordId === false) {
    finishDeleteRequest('index.php', 'error', 'Select one valid record to delete.');
}
$entity = $entities[$entityName];
$pdo = null;
try {
    $pdo = createDatabaseConnection();
    deleteOneRecord($pdo, $entity, $recordId);
} catch (DomainException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($error->getMessage() === 'not_found') {
        finishDeleteRequest($entity['page'], 'error', $entity['id_label'] . ' #' . $recordId . ' no longer exists.');
    }

    if (str_starts_with($error->getMessage(), 'dependency:')) {
        [, $label, $count] = explode(':', $error->getMessage(), 3);
        finishDeleteRequest(
            $entity['page'],
            'error',
            'Cannot delete ' . $entity['id_label'] . ' #' . $recordId . ': ' . $count . ' dependent ' . $label
            . ($count === '1' ? '' : 's') . ' must be resolved first.'
        );
    }

    error_log('KhetSaathi record deletion rejected (entity ' . $entityName . ').');
    finishDeleteRequest($entity['page'], 'error', 'The record could not be deleted because of a database relationship. Resolve related records first.');
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('KhetSaathi record deletion failed (entity ' . $entityName . ', SQLSTATE ' . $error->getCode() . ').');
    finishDeleteRequest($entity['page'], 'error', 'The record could not be deleted. It may have related records or the database may be unavailable.');
}

// These session-only structures change after the database transaction commits.
// A support queue and crop hash are rebuilt from MySQL on the next page request.
if ($entityName === 'support') {
    removeSupportUndoEntries($recordId);
}
setRecentActivity($entity['id_label'] . ' #' . $recordId . ' deleted.');
finishDeleteRequest($entity['page'], 'success', $entity['id_label'] . ' #' . $recordId . ' was deleted.');
