<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/data-structures/RecentActivityLinkedList.php';

startKhetSaathiSession();
sendAppSecurityHeaders();
$actionFlash = consumeActionFlash();
require_once __DIR__ . '/../app/database-compat.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pdo = null;
    if (!isValidAppCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'Your form session expired. Refresh and try again.'];
    } else {
        $fieldId = filter_var($_POST['field_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cropId = filter_var($_POST['crop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $yearInput = is_string($_POST['planting_year'] ?? null) ? trim($_POST['planting_year']) : '';
        $year = filter_var($yearInput, FILTER_VALIDATE_INT);
        $date = is_string($_POST['planting_date'] ?? null) ? trim($_POST['planting_date']) : '';
        $status = is_string($_POST['planting_status'] ?? null) ? $_POST['planting_status'] : '';
        $validDate = $date === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            && ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date)) !== false
            && $parsed->format('Y-m-d') === $date);
        if ($fieldId === false || $cropId === false || $seasonId === false || $year === false || $year < 1901 || $year > 2155
            || !$validDate || !in_array($status, ['Planned', 'Planted', 'Completed'], true)) {
            $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'Choose valid field, crop, season, year, date, and status values.'];
        } else {
            try {
                $pdo = createDatabaseConnection();
                $pdo->beginTransaction();
                $fieldCheck = $pdo->prepare('SELECT Field_ID FROM field WHERE Field_ID = :id FOR UPDATE');
                $fieldCheck->execute(['id' => $fieldId]);
                if ($fieldCheck->fetchColumn() === false) {
                    throw new DomainException('field');
                }
                foreach ([['crop', 'Crop_ID', $cropId], ['season', 'Season_ID', $seasonId]] as [$table, $column, $id]) {
                    $reference = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :id');
                    $reference->execute(['id' => $id]);
                    if ($reference->fetchColumn() === false) { throw new DomainException('reference'); }
                }
                $compatibilityOwnerId = databaseCompatibilityOwnerId($pdo);
                $insert = $pdo->prepare(
                    'INSERT INTO planting_record (Field_ID, Crop_ID, Season_ID, Planting_Year, Planting_Date, Planting_Status, owner_user_id)
                     VALUES (:field_id, :crop_id, :season_id, :year, :planting_date, :status, :owner_user_id)'
                );
                $insert->execute([
                    'field_id' => $fieldId, 'crop_id' => $cropId, 'season_id' => $seasonId,
                    'year' => $year, 'planting_date' => $date === '' ? null : $date,
                    'status' => $status, 'owner_user_id' => $compatibilityOwnerId,
                ]);
                $plantingId = (int) $pdo->lastInsertId();
                $pdo->commit();
                $activityList = RecentActivityLinkedList::fromNewestFirstArray(is_array($_SESSION['recent_activity'] ?? null) ? $_SESSION['recent_activity'] : []);
                $activityList->insertAtHead('Planting ID #' . $plantingId . ' added for Field ID #' . $fieldId . '.');
                $_SESSION['recent_activity'] = $activityList->toArray();
                $_SESSION['action_flash'] = ['type' => 'success', 'message' => 'Planting record saved.'];
            } catch (DomainException $error) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
                $_SESSION['action_flash'] = ['type' => 'error', 'message' => $error->getMessage() === 'field' ? 'Choose an existing field.' : 'The selected crop or season is no longer available.'];
            } catch (Throwable $error) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('KhetSaathi planting creation failed.');
                $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'The planting record could not be saved. No partial write was kept.'];
            }
        }
    }
    session_write_close();
    header('Location: plantings.php', true, 303);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$plantingCount = null;
$plantings = [];
$fields = $crops = $seasons = [];
$databaseError = false;

try {
    $pdo = createDatabaseConnection();

    $countStatement = $pdo->query('SELECT COUNT(*) FROM planting_record');
    $plantingCount = (int) $countStatement->fetchColumn();

    $plantingStatement = $pdo->prepare(
        'SELECT planting.Planting_ID, farmer.Farmer_Name, field.Field_Name,
                crop.Crop_Name, season.Season_Name, planting.Planting_Year,
                planting.Planting_Date, planting.Planting_Status
         FROM planting_record AS planting
         INNER JOIN field AS field ON field.Field_ID = planting.Field_ID
         INNER JOIN farmer AS farmer ON farmer.Farmer_ID = field.Farmer_ID
         INNER JOIN crop AS crop ON crop.Crop_ID = planting.Crop_ID
         INNER JOIN season AS season ON season.Season_ID = planting.Season_ID
         ORDER BY planting.Planting_ID ASC'
    );
    $plantingStatement->execute();
    $plantings = $plantingStatement->fetchAll();

    $fieldQuery = $pdo->query(
        'SELECT field.Field_ID, field.Field_Name, farmer.Farmer_Name
         FROM field AS field JOIN farmer AS farmer ON farmer.Farmer_ID = field.Farmer_ID
         ORDER BY farmer.Farmer_Name, field.Field_Name, field.Field_ID'
    );
    $fields = $fieldQuery->fetchAll();
    $crops = $pdo->query('SELECT Crop_ID, Crop_Name FROM crop ORDER BY Crop_Name')->fetchAll();
    $seasons = $pdo->query('SELECT Season_ID, Season_Name FROM season ORDER BY Season_Name')->fetchAll();
} catch (Throwable $error) {
    error_log('KhetSaathi planting records page error: ' . $error->getMessage());
    $databaseError = true;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — Planting records</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/polish.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="index.php" aria-label="KhetSaathi dashboard home">
            <span class="brand-mark"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21v-9"/><path d="M12 12C7 12 5 9 5 5c4 0 7 2 7 7Zm0 2c0-4 2-7 7-7 0 4-2 7-7 7Z"/></svg></span>
            <span class="brand-name">khet<span>saathi</span></span>
        </a>
        <button class="nav-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false"><span class="nav-toggle-icon" aria-hidden="true">☰</span><span class="nav-toggle-label">Menu</span></button>
        <div class="nav-label">WORKSPACE</div>
        <nav class="nav-list" id="primary-navigation">
            <a class="nav-link" href="index.php"><span class="nav-icon">⌂</span> Overview</a>
            <a class="nav-link" href="farmers.php"><span class="nav-icon">♙</span> Farmers</a>
            <a class="nav-link" href="fields.php"><span class="nav-icon">♧</span> Fields</a>
            <a class="nav-link" href="crops.php"><span class="nav-icon">♧</span> Crop catalogue</a>
            <a class="nav-link nav-link--active" href="plantings.php" aria-current="page"><span class="nav-icon">▦</span> Planting records</a>
            <a class="nav-link" href="support.php"><span class="nav-icon">▤</span> Support inbox</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="season-card"><span class="season-kicker"><span class="live-dot"></span> YOUR FARM, AT A GLANCE</span><p>Keep an eye on what’s planted across your fields.</p><span class="season-note">A simple workspace for your fields.</span></div>
        </div>
    </aside>

    <main class="main-content plantings-main">
        <header class="topbar"><div class="breadcrumb"><a href="index.php">Workspace</a> <span>/</span> <strong>Planting records</strong></div><div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span></div></header>

        <section class="plantings-intro">
            <div><p class="eyebrow">FIELD ACTIVITY <span>·</span> SEASONAL RECORDS</p><h1>Planting records<span>.</span></h1><p class="welcome-copy">A joined view of farmers, fields, crops, and seasons.</p></div>
            <div class="inbox-count"><span class="inbox-count-label">TOTAL RECORDS</span><strong><?= $databaseError || $plantingCount === null ? '—' : e(number_format($plantingCount)) ?></strong><span>from your database</span></div>
        </section>

        <?php if ($actionFlash !== null): ?>
            <div class="notice <?= $actionFlash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="<?= $actionFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($actionFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice--error" role="alert"><strong>Planting records are unavailable.</strong> Check that MySQL is running and local database environment variables are set.</div>
        <?php endif; ?>

        <section class="farmer-form-panel planting-create-panel" aria-labelledby="planting-create-title">
            <div class="panel-heading"><div><p class="eyebrow">NEW RECORD</p><h2 id="planting-create-title">Record a planting</h2></div></div>
            <?php if ($fields === []): ?><p class="empty-state">Register a field before recording planting activity.</p><?php else: ?>
            <form method="post" action="plantings.php" class="farmer-form planting-create-form">
                <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                <label for="planting-field">Field and farmer <span class="required-mark">*</span></label><select id="planting-field" name="field_id" required><option value="">Choose a field</option><?php foreach ($fields as $field): ?><option value="<?= e($field['Field_ID']) ?>"><?= e($field['Farmer_Name']) ?> · <?= e($field['Field_Name']) ?></option><?php endforeach; ?></select>
                <label for="planting-crop">Crop <span class="required-mark">*</span></label><select id="planting-crop" name="crop_id" required><option value="">Choose a crop</option><?php foreach ($crops as $crop): ?><option value="<?= e($crop['Crop_ID']) ?>"><?= e($crop['Crop_Name']) ?></option><?php endforeach; ?></select>
                <label for="planting-season">Season <span class="required-mark">*</span></label><select id="planting-season" name="season_id" required><option value="">Choose a season</option><?php foreach ($seasons as $season): ?><option value="<?= e($season['Season_ID']) ?>"><?= e($season['Season_Name']) ?></option><?php endforeach; ?></select>
                <label for="planting-year">Year <span class="required-mark">*</span></label><input id="planting-year" name="planting_year" type="number" min="1901" max="2155" required>
                <label for="planting-date">Planting date</label><input id="planting-date" name="planting_date" type="date">
                <label for="planting-status">Status <span class="required-mark">*</span></label><select id="planting-status" name="planting_status" required><option value="Planned">Planned</option><option value="Planted">Planted</option><option value="Completed">Completed</option></select>
                <button type="submit" class="farmer-submit">Save planting <span aria-hidden="true">→</span></button>
            </form><?php endif; ?>
        </section>

        <section class="plantings-panel panel" aria-labelledby="plantings-title">
            <div class="panel-heading">
                <div><p class="eyebrow">RELATIONAL DATABASE VIEW</p><h2 id="plantings-title">All planting activity</h2><p class="panel-subtitle">Farmer, field, crop, and season details are joined from their related tables.</p></div>
                <label class="list-filter"><span class="list-filter-icon" aria-hidden="true">⌕</span><input type="search" data-list-filter="#planting-rows > tr" aria-label="Search planting records" placeholder="Search records"><span class="filter-count" data-filter-count aria-live="polite"></span></label>
                <span class="panel-icon" aria-hidden="true">▦</span>
            </div>
            <?php if (!$databaseError && $plantings !== []): ?>
                <div class="table-wrap">
                    <table class="planting-table">
                        <thead><tr><th scope="col">RECORD</th><th scope="col">FARMER</th><th scope="col">FIELD</th><th scope="col">CROP</th><th scope="col">SEASON</th><th scope="col">YEAR</th><th scope="col">PLANTED ON</th><th scope="col">STATUS</th><th scope="col">ACTION</th></tr></thead>
                        <tbody id="planting-rows">
                        <?php foreach ($plantings as $rowNumber => $planting): ?>
                            <tr>
                                <td><span class="planting-id">Record #<?= e($rowNumber + 1) ?><br><small>Planting ID #<?= e($planting['Planting_ID']) ?></small></span></td>
                                <td class="planting-name"><?= e($planting['Farmer_Name']) ?></td>
                                <td><?= e($planting['Field_Name']) ?></td>
                                <td class="planting-name"><?= e($planting['Crop_Name']) ?></td>
                                <td><?= e($planting['Season_Name']) ?></td>
                                <td><?= e($planting['Planting_Year']) ?></td>
                                <td><?= $planting['Planting_Date'] === null ? '<span class="not-provided">Not set</span>' : e($planting['Planting_Date']) ?></td>
                                <td><span class="<?= e(match ($planting['Planting_Status']) { 'Completed' => 'badge badge--low', 'Planted' => 'badge badge--medium', 'Planned' => 'badge badge--pending', default => 'badge' }) ?>"><i></i><?= e($planting['Planting_Status'] ?? 'Not set') ?></span></td>
                                <td><div class="row-actions"><a class="edit-button" href="edit.php?entity=planting&amp;id=<?= e($planting['Planting_ID']) ?>">Edit</a><form method="post" action="delete.php" data-confirm="Delete planting record (database ID #<?= e($planting['Planting_ID']) ?>) for <?= e($planting['Crop_Name']) ?> on <?= e($planting['Field_Name']) ?>?">
                                    <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                                    <input type="hidden" name="entity" value="planting">
                                    <input type="hidden" name="record_id" value="<?= e($planting['Planting_ID']) ?>">
                                    <button class="danger-button" type="submit">Delete</button>
                                </form></div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (!$databaseError): ?>
                <div class="empty-state planting-empty"><span class="empty-icon">▦</span><strong>No planting records yet</strong><span>Existing planting activity will appear here.</span></div>
            <?php else: ?>
                <div class="empty-state planting-empty"><span class="empty-icon">!</span><strong>Unable to load planting activity</strong><span>Restore the database connection and refresh this page.</span></div>
            <?php endif; ?>
            <div class="panel-footer"><span>Showing all planting records</span><span class="read-only-tag">DATABASE VIEW</span></div>
        </section>

        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> PLANTING RECORDS</span><span>Joined from existing farm records · Local demo</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
