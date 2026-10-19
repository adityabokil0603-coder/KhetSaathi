<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/security.php';
startKhetSaathiSession();
sendAppSecurityHeaders();
$actionFlash = consumeActionFlash();

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/database-compat.php';
require_once __DIR__ . '/../app/data-structures/RecentActivityLinkedList.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fitsFieldLength(string $value, int $maximum): bool
{
    $length = preg_match_all('/./us', $value);
    return $length !== false && $length <= $maximum;
}

$fertilityLevels = ['High', 'Medium', 'Low'];
$_SESSION['field_csrf_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'error', 'message' => 'Please check the form and try again.'];
    $postedToken = $_POST['csrf_token'] ?? '';
    $fieldName = is_string($_POST['field_name'] ?? null) ? trim($_POST['field_name']) : '';
    $farmerId = filter_var($_POST['farmer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $fieldLocation = is_string($_POST['field_location'] ?? null) ? trim($_POST['field_location']) : '';
    $soilInput = is_string($_POST['soil_id'] ?? null) ? trim($_POST['soil_id']) : '';
    $soilId = $soilInput === '' ? null : filter_var($soilInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $fertility = is_string($_POST['fertility_level'] ?? null) ? $_POST['fertility_level'] : '';
    $formValues = [
        'field_name' => $fieldName,
        'farmer_id' => is_int($farmerId) ? (string) $farmerId : '',
        'field_location' => $fieldLocation,
        'soil_id' => is_int($soilId) ? (string) $soilId : '',
        'fertility_level' => $fertility,
    ];

    if (!is_string($postedToken) || !hash_equals($_SESSION['field_csrf_token'], $postedToken)) {
        $flash['message'] = 'Your form session expired. Refresh the page and try again.';
    } elseif ($fieldName === '' || $farmerId === false || $fieldLocation === '') {
        $flash['message'] = 'Field name, farmer, and location are required.';
    } elseif (!fitsFieldLength($fieldName, 100) || !fitsFieldLength($fieldLocation, 255)) {
        $flash['message'] = 'Field name must be at most 100 characters and location at most 255.';
    } elseif ($soilInput !== '' && $soilId === false) {
        $flash['message'] = 'Select a valid soil type or leave the soil selection blank.';
    } elseif ($fertility !== '' && !in_array($fertility, $fertilityLevels, true)) {
        $flash['message'] = 'Select a valid fertility level or leave it blank.';
    } else {
        try {
            $pdo = createDatabaseConnection();

            $farmerCheck = $pdo->prepare('SELECT 1 FROM farmer WHERE Farmer_ID = :farmer_id');
            $farmerCheck->execute(['farmer_id' => $farmerId]);
            if ($farmerCheck->fetchColumn() === false) {
                $flash['message'] = 'The selected farmer is no longer available. Refresh the page and choose again.';
            } else {
                $soilIsValid = true;
                if ($soilId !== null) {
                    $soilCheck = $pdo->prepare('SELECT 1 FROM soil_type WHERE Soil_ID = :soil_id');
                    $soilCheck->execute(['soil_id' => $soilId]);
                    $soilIsValid = $soilCheck->fetchColumn() !== false;
                }

                if (!$soilIsValid) {
                    $flash['message'] = 'The selected soil type is no longer available. Refresh the page and choose again.';
                } else {
                    $compatibilityOwnerId = databaseCompatibilityOwnerId($pdo);
                    $insert = $pdo->prepare(
                        'INSERT INTO field (Farmer_ID, Field_Name, Field_Location, Soil_ID, Fertility_Level, owner_user_id)
                         VALUES (:farmer_id, :field_name, :field_location, :soil_id, :fertility_level, :owner_user_id)'
                    );
                    $insert->bindValue(':farmer_id', $farmerId, PDO::PARAM_INT);
                    $insert->bindValue(':field_name', $fieldName, PDO::PARAM_STR);
                    $insert->bindValue(':field_location', $fieldLocation, PDO::PARAM_STR);
                    $insert->bindValue(':soil_id', $soilId, $soilId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                    $insert->bindValue(':fertility_level', $fertility === '' ? null : $fertility, $fertility === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $insert->bindValue(':owner_user_id', $compatibilityOwnerId, PDO::PARAM_INT);
                    $insert->execute();

                    $activityList = RecentActivityLinkedList::fromNewestFirstArray(
                        is_array($_SESSION['recent_activity'] ?? null) ? $_SESSION['recent_activity'] : []
                    );
                    $fieldId = (int) $pdo->lastInsertId();
                    $activityList->insertAtHead('Field ID #' . $fieldId . ' registered: ' . $fieldName);
                    $_SESSION['recent_activity'] = $activityList->toArray();

                    $flash = ['type' => 'success', 'message' => 'Field registered successfully.'];
                    $formValues = ['field_name' => '', 'farmer_id' => '', 'field_location' => '', 'soil_id' => '', 'fertility_level' => ''];
                }
            }
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                $flash['message'] = 'A selected farmer or soil type is no longer valid. Refresh the page and try again.';
            } else {
                error_log('KhetSaathi field registration error: ' . $error->getMessage());
                $flash['message'] = 'The field could not be saved. Check the database connection and try again.';
            }
        } catch (Throwable $error) {
            error_log('KhetSaathi field registration error: ' . $error->getMessage());
            $flash['message'] = 'The field could not be saved. Check the database connection and try again.';
        }
    }

    $_SESSION['fields_flash'] = $flash;
    $_SESSION['field_form_values'] = $formValues;
    session_write_close();
    header('Location: fields.php', true, 303);
    exit;
}

$flash = $_SESSION['fields_flash'] ?? null;
$formValues = $_SESSION['field_form_values'] ?? [
    'field_name' => '', 'farmer_id' => '', 'field_location' => '', 'soil_id' => '', 'fertility_level' => '',
];
unset($_SESSION['fields_flash'], $_SESSION['field_form_values']);

$farmers = [];
$soilTypes = [];
$fields = [];
$databaseError = false;

try {
    $pdo = createDatabaseConnection();

    $farmerQuery = $pdo->query('SELECT Farmer_ID, Farmer_Name FROM farmer ORDER BY Farmer_Name, Farmer_ID');
    $farmers = $farmerQuery->fetchAll();

    $soilQuery = $pdo->prepare('SELECT Soil_ID, Soil_Name FROM soil_type ORDER BY Soil_Name, Soil_ID');
    $soilQuery->execute();
    $soilTypes = $soilQuery->fetchAll();

    $fieldQuery = $pdo->prepare(
        'SELECT field.Field_ID, field.Field_Name, field.Field_Location, field.Fertility_Level,
                farmer.Farmer_Name, soil.Soil_Name
         FROM field AS field
         INNER JOIN farmer AS farmer ON farmer.Farmer_ID = field.Farmer_ID
          LEFT JOIN soil_type AS soil ON soil.Soil_ID = field.Soil_ID
         ORDER BY field.Field_ID ASC'
    );
    $fieldQuery->execute();
    $fields = $fieldQuery->fetchAll();
} catch (Throwable $error) {
    error_log('KhetSaathi field page database error: ' . $error->getMessage());
    $databaseError = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — Fields</title>
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
            <a class="nav-link nav-link--active" href="fields.php" aria-current="page"><span class="nav-icon">♧</span> Fields</a>
            <a class="nav-link" href="crops.php"><span class="nav-icon">♧</span> Crop catalogue</a>
            <a class="nav-link" href="plantings.php"><span class="nav-icon">▦</span> Planting records</a>
            <a class="nav-link" href="support.php"><span class="nav-icon">▤</span> Support inbox</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="season-card"><span class="season-kicker"><span class="live-dot"></span> YOUR FARM, AT A GLANCE</span><p>Keep every field connected to its farmer.</p><span class="season-note">A simple workspace for your fields.</span></div>
        </div>
    </aside>

    <main class="main-content farmers-main">
        <header class="topbar"><div class="breadcrumb"><a href="index.php">Workspace</a> <span>/</span> <strong>Fields</strong></div><div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span></div></header>
        <section class="farmers-intro">
            <div><p class="eyebrow">LAND &amp; SOIL <span>·</span> FIELD DIRECTORY</p><h1>Fields<span>.</span></h1><p class="welcome-copy">Register a field and connect it to its farmer and soil details.</p></div>
            <div class="field-intro-actions"><a class="directory-link" href="farmers.php">Farmer directory <span aria-hidden="true">↗</span></a><div class="inbox-count"><span class="inbox-count-label">REGISTERED FIELDS</span><strong><?= $databaseError ? '—' : e(count($fields)) ?></strong><span>from your database</span></div></div>
        </section>

        <?php if ($actionFlash !== null): ?>
            <div class="notice <?= $actionFlash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="<?= $actionFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($actionFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($flash !== null): ?>
            <div class="notice <?= $flash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice--error" role="alert"><strong>Field records are unavailable.</strong> Check that MySQL is running and local database environment variables are set.</div>
        <?php elseif ($farmers === []): ?>
            <div class="notice notice--error" role="status"><strong>No farmers are available.</strong> Register a farmer before adding a field.</div>
        <?php endif; ?>

        <section class="farmer-content-grid">
            <div class="farmer-form-panel">
                <div class="farmer-section-heading"><span class="form-mark"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20h18M5 17l4-5 3 3 6-8 3 3"/><path d="M5 20V8m5 12v-4m5 4v-3m5 3V7"/></svg></span><div><p class="eyebrow">NEW LAND RECORD</p><h2>Register a field</h2></div></div>
                <p class="form-intro">Connect the field to a farmer. Soil type and fertility can be added later.</p>
                <form method="post" action="fields.php" class="farmer-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['field_csrf_token']) ?>">
                    <label for="field-name">Field name <span class="required-mark">*</span></label>
                    <input id="field-name" name="field_name" type="text" maxlength="100" required value="<?= e($formValues['field_name'] ?? '') ?>" placeholder="e.g. North plot">

                    <label for="farmer-id">Farmer <span class="required-mark">*</span></label>
                    <select id="farmer-id" name="farmer_id" required>
                        <option value="">Choose a farmer</option>
                        <?php foreach ($farmers as $farmer): ?>
                            <option value="<?= e($farmer['Farmer_ID']) ?>" <?= (string) ($formValues['farmer_id'] ?? '') === (string) $farmer['Farmer_ID'] ? 'selected' : '' ?>><?= e($farmer['Farmer_Name']) ?> · Farmer ID #<?= e($farmer['Farmer_ID']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="field-location">Field location <span class="required-mark">*</span></label>
                    <input id="field-location" name="field_location" type="text" maxlength="255" required value="<?= e($formValues['field_location'] ?? '') ?>" placeholder="Village, district or region">

                    <label for="soil-id">Soil type <span class="optional-label">OPTIONAL</span></label>
                    <select id="soil-id" name="soil_id">
                        <option value="">No soil type selected</option>
                        <?php foreach ($soilTypes as $soil): ?>
                            <option value="<?= e($soil['Soil_ID']) ?>" <?= (string) ($formValues['soil_id'] ?? '') === (string) $soil['Soil_ID'] ? 'selected' : '' ?>><?= e($soil['Soil_Name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="fertility-level">Fertility level <span class="optional-label">OPTIONAL</span></label>
                    <select id="fertility-level" name="fertility_level">
                        <option value="">Not specified</option>
                        <?php foreach ($fertilityLevels as $level): ?>
                            <option value="<?= e($level) ?>" <?= ($formValues['fertility_level'] ?? '') === $level ? 'selected' : '' ?>><?= e($level) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="farmer-submit" <?= $databaseError || $farmers === [] ? 'disabled' : '' ?>>Save field <span aria-hidden="true">→</span></button>
                    <p class="privacy-note"><span aria-hidden="true">⌑</span> Field details are stored in your local KhetSaathi database.</p>
                </form>
            </div>

            <section class="farmer-directory" aria-labelledby="field-directory-title">
                <div class="directory-heading"><div><p class="eyebrow">YOUR LAND</p><h2 id="field-directory-title">Field directory</h2></div><div class="directory-tools"><label class="list-filter"><span class="list-filter-icon" aria-hidden="true">⌕</span><input type="search" data-list-filter="#field-rows > tr" aria-label="Search fields" placeholder="Search fields"><span class="filter-count" data-filter-count aria-live="polite"></span></label><span class="directory-total"><?= $databaseError ? '—' : e(count($fields)) ?> <small>records</small></span></div></div>
                <?php if (!$databaseError && $fields !== []): ?>
                    <div class="farmer-table-wrap">
                        <table class="farmer-table field-table">
                            <thead><tr><th scope="col">FIELD</th><th scope="col">FARMER</th><th scope="col">LOCATION</th><th scope="col">SOIL</th><th scope="col">FERTILITY</th><th scope="col">ACTION</th></tr></thead>
                            <tbody id="field-rows">
                            <?php foreach ($fields as $rowNumber => $field): ?>
                                <tr>
                                    <td><div class="directory-person"><span class="directory-avatar" aria-hidden="true">⌂</span><span><strong><?= e($field['Field_Name']) ?></strong><small>Record #<?= e($rowNumber + 1) ?> · Field ID #<?= e($field['Field_ID']) ?></small></span></div></td>
                                    <td><?= e($field['Farmer_Name']) ?></td>
                                    <td><?= e($field['Field_Location']) ?></td>
                                    <td><?= $field['Soil_Name'] === null ? '<span class="not-provided">Not set</span>' : e($field['Soil_Name']) ?></td>
                                    <td><?= $field['Fertility_Level'] === null ? '<span class="not-provided">Not set</span>' : e($field['Fertility_Level']) ?></td>
                                    <td><div class="row-actions"><a class="edit-button" href="edit.php?entity=field&amp;id=<?= e($field['Field_ID']) ?>">Edit</a><form method="post" action="delete.php" data-confirm="Delete field <?= e($field['Field_Name']) ?> (database ID #<?= e($field['Field_ID']) ?>)? Fields with planting records or support requests cannot be deleted.">
                                        <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                                        <input type="hidden" name="entity" value="field">
                                        <input type="hidden" name="record_id" value="<?= e($field['Field_ID']) ?>">
                                        <button class="danger-button" type="submit">Delete</button>
                                    </form></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!$databaseError): ?>
                    <div class="empty-state directory-empty"><span class="empty-icon">⌂</span><strong>No fields registered yet</strong><span>New fields will appear here.</span></div>
                <?php else: ?>
                    <div class="empty-state directory-empty"><span class="empty-icon">!</span><strong>Unable to load fields</strong><span>Restore the database connection and refresh this page.</span></div>
                <?php endif; ?>
                <div class="directory-footer"><span>Showing all registered fields</span><span class="read-only-tag">LIVE DATABASE</span></div>
            </section>
        </section>
        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> FIELD DIRECTORY</span><span>Local demo · Farmer and field records</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
