<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/security.php';
startKhetSaathiSession();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/data-structures/RecentActivityLinkedList.php';
require_once __DIR__ . '/../app/data-structures/SupportStatusStack.php';
sendAppSecurityHeaders();

function editEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function editTextLength(string $value): int|false
{
    return preg_match_all('/./us', $value);
}

/** Bind null, integer, and text values with the correct PDO type. */
function runEditQuery(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    foreach ($values as $name => $value) {
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue(':' . $name, $value, $type);
    }
    $statement->execute();
    return $statement;
}

function editAddActivity(string $message): void
{
    $activities = is_array($_SESSION['recent_activity'] ?? null) ? $_SESSION['recent_activity'] : [];
    $list = RecentActivityLinkedList::fromNewestFirstArray($activities);
    $list->insertAtHead($message);
    $_SESSION['recent_activity'] = $list->toArray();
}

function editSupportStack(): SupportStatusStack
{
    $stack = new SupportStatusStack();
    $history = $_SESSION['support_undo_history'] ?? [];
    if (is_array($history)) {
        foreach ($history as $entry) {
            if (is_array($entry)) {
                $stack->push($entry);
            }
        }
    }
    return $stack;
}

function editRedirect(string $page, string $message): never
{
    $_SESSION['action_flash'] = ['type' => 'success', 'message' => $message];
    session_write_close();
    header('Location: ' . $page, true, 303);
    exit;
}

$entities = [
    'farmer' => ['table' => 'farmer', 'key' => 'Farmer_ID', 'page' => 'farmers.php', 'title' => 'Edit farmer'],
    'field' => ['table' => 'field', 'key' => 'Field_ID', 'page' => 'fields.php', 'title' => 'Edit field'],
    'crop' => ['table' => 'crop', 'key' => 'Crop_ID', 'page' => 'crops.php', 'title' => 'Edit crop'],
    'planting' => ['table' => 'planting_record', 'key' => 'Planting_ID', 'page' => 'plantings.php', 'title' => 'Edit planting record'],
    'support' => ['table' => 'support_request', 'key' => 'Request_ID', 'page' => 'support.php', 'title' => 'Edit support request'],
];
$entityName = is_string($_GET['entity'] ?? null) ? $_GET['entity'] : '';
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!isset($entities[$entityName]) || $recordId === false) {
    http_response_code(400);
    exit('Choose a valid record to edit.');
}
$entity = $entities[$entityName];
$statuses = ['Pending', 'Under Review', 'Resolved'];
$urgencies = ['Low', 'Medium', 'High'];
$fertilityLevels = ['High', 'Medium', 'Low'];
$plantingStatuses = ['Planned', 'Planted', 'Completed'];
$flash = null;
$record = null;
$farmers = $soilTypes = $fields = $crops = $seasons = [];
$databaseError = false;

try {
    $pdo = createDatabaseConnection();
    $recordStatement = runEditQuery(
        $pdo,
        'SELECT * FROM ' . $entity['table'] . ' WHERE ' . $entity['key'] . ' = :record_id',
        ['record_id' => $recordId]
    );
    $record = $recordStatement->fetch();
    if ($record === false) {
        http_response_code(404);
        exit('That record no longer exists. Return to its list and refresh.');
    }

    if ($entityName === 'field') {
        $farmerQuery = $pdo->query('SELECT Farmer_ID, Farmer_Name FROM farmer ORDER BY Farmer_Name, Farmer_ID');
        $farmers = $farmerQuery->fetchAll();
        $soilTypes = $pdo->query('SELECT Soil_ID, Soil_Name FROM soil_type ORDER BY Soil_Name, Soil_ID')->fetchAll();
    } elseif ($entityName === 'planting') {
        $fieldQuery = $pdo->query(
            'SELECT field.Field_ID, field.Field_Name, farmer.Farmer_Name
             FROM field AS field JOIN farmer AS farmer ON farmer.Farmer_ID = field.Farmer_ID
             ORDER BY farmer.Farmer_Name, field.Field_Name, field.Field_ID'
        );
        $fields = $fieldQuery->fetchAll();
        $crops = $pdo->query('SELECT Crop_ID, Crop_Name FROM crop ORDER BY Crop_Name, Crop_ID')->fetchAll();
        $seasons = $pdo->query('SELECT Season_ID, Season_Name FROM season ORDER BY Season_Name, Season_ID')->fetchAll();
    }
} catch (Throwable $error) {
    error_log('KhetSaathi edit page load error: ' . $error->getMessage());
    $databaseError = true;
}

$values = $record ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'error', 'message' => 'Please correct the highlighted record details and try again.'];
    if (!isValidAppCsrfToken($_POST['csrf_token'] ?? null)) {
        $flash['message'] = 'Your form session expired. Refresh the page and try again.';
    } elseif ($databaseError || !is_array($record)) {
        $flash['message'] = 'The record could not be loaded. Return to its list and try again.';
    } else {
        $allowedFields = match ($entityName) {
            'farmer' => ['Farmer_Name', 'Contact_Number', 'Location_Description'],
            'field' => ['Field_Name', 'Farmer_ID', 'Field_Location', 'Soil_ID', 'Fertility_Level'],
            'crop' => ['Crop_Name', 'Crop_Category', 'General_Information'],
            'planting' => ['Field_ID', 'Crop_ID', 'Season_ID', 'Planting_Year', 'Planting_Date', 'Planting_Status'],
            'support' => ['Simplified_Message', 'Urgency', 'Status'],
        };
        $invalidInputShape = false;
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $_POST) || !is_string($_POST[$field])) {
                $invalidInputShape = true;
                $values[$field] = '';
            } else {
                $values[$field] = trim($_POST[$field]);
            }
        }

        $errorMessage = null;
        $nullable = static fn (string $value): ?string => $value === '' ? null : $value;
        $lengthIsValid = static function (string $value, int $maximum): bool {
            $length = editTextLength($value);
            return $length !== false && $length <= $maximum;
        };
        $idValue = static function (string $value): int|false {
            return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        };

        switch ($entityName) {
            case 'farmer':
                if ($values['Farmer_Name'] === '') {
                    $errorMessage = 'Farmer name is required.';
                } elseif (!$lengthIsValid($values['Farmer_Name'], 100)
                    || !$lengthIsValid($values['Contact_Number'], 20)
                    || !$lengthIsValid($values['Location_Description'], 255)) {
                    $errorMessage = 'Name, contact, and location must fit their database column limits.';
                }
                break;
            case 'field':
                $farmerId = $idValue($values['Farmer_ID']);
                $soilId = $values['Soil_ID'] === '' ? null : $idValue($values['Soil_ID']);
                if ($values['Field_Name'] === '' || $values['Field_Location'] === '' || $farmerId === false) {
                    $errorMessage = 'Field name, location, and a valid farmer are required.';
                } elseif (!$lengthIsValid($values['Field_Name'], 100) || !$lengthIsValid($values['Field_Location'], 255)) {
                    $errorMessage = 'Field name must fit 100 characters and location 255 characters.';
                } elseif ($soilId === false) {
                    $errorMessage = 'Choose a valid soil type or leave it blank.';
                } elseif ($values['Fertility_Level'] !== '' && !in_array($values['Fertility_Level'], $fertilityLevels, true)) {
                    $errorMessage = 'Choose High, Medium, Low, or leave fertility blank.';
                } else {
                    $values['Farmer_ID'] = $farmerId;
                    $values['Soil_ID'] = $soilId;
                }
                break;
            case 'crop':
                if ($values['Crop_Name'] === '') {
                    $errorMessage = 'Crop name is required.';
                } elseif (!$lengthIsValid($values['Crop_Name'], 100) || !$lengthIsValid($values['Crop_Category'], 50)) {
                    $errorMessage = 'Crop name must fit 100 characters and category 50 characters.';
                } elseif (!$lengthIsValid($values['General_Information'], 10000)) {
                    $errorMessage = 'General information must be at most 10,000 characters.';
                }
                break;
            case 'planting':
                $fieldId = $idValue($values['Field_ID']);
                $cropId = $idValue($values['Crop_ID']);
                $seasonId = $idValue($values['Season_ID']);
                $year = filter_var($values['Planting_Year'], FILTER_VALIDATE_INT);
                $date = $values['Planting_Date'];
                $validDate = $date === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
                    && DateTimeImmutable::createFromFormat('!Y-m-d', $date) !== false
                    && DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') === $date);
                if ($fieldId === false || $cropId === false || $seasonId === false) {
                    $errorMessage = 'Choose a valid field, crop, and season.';
                } elseif ($year === false || $year < 1901 || $year > 2155) {
                    $errorMessage = 'Planting year must be between 1901 and 2155.';
                } elseif (!$validDate) {
                    $errorMessage = 'Enter a valid date in YYYY-MM-DD format or leave it blank.';
                } elseif (!in_array($values['Planting_Status'], $plantingStatuses, true)) {
                    $errorMessage = 'Choose Planned, Planted, or Completed.';
                } else {
                    $values['Field_ID'] = $fieldId;
                    $values['Crop_ID'] = $cropId;
                    $values['Season_ID'] = $seasonId;
                    $values['Planting_Year'] = $year;
                    $values['Planting_Date'] = $nullable($date);
                }
                break;
            case 'support':
                if (!$lengthIsValid($values['Simplified_Message'], 10000)) {
                    $errorMessage = 'Simplified display text must be at most 10,000 characters.';
                } elseif ($values['Urgency'] !== '' && !in_array($values['Urgency'], $urgencies, true)) {
                    $errorMessage = 'Choose Low, Medium, High, or leave urgency blank.';
                } elseif (!in_array($values['Status'], $statuses, true)) {
                    $errorMessage = 'Choose Pending, Under Review, or Resolved.';
                }
                break;
        }

        if ($invalidInputShape) {
            $errorMessage = 'Submit each form field as a single value, then try again.';
        }

        if ($errorMessage !== null) {
            $flash['message'] = $errorMessage;
        } else {
            $pdo = null;
            try {
                $pdo = createDatabaseConnection();
                $pdo->beginTransaction();
                $currentStatement = runEditQuery(
                    $pdo,
                    'SELECT ' . $entity['key'] . ($entityName === 'support' ? ', Status' : '')
                    . ' FROM ' . $entity['table'] . ' WHERE ' . $entity['key'] . ' = :record_id FOR UPDATE',
                    ['record_id' => $recordId]
                );
                $current = $currentStatement->fetch();
                if ($current === false) {
                    throw new DomainException('not_found');
                }

                if ($entityName === 'field') {
                    if (runEditQuery($pdo, 'SELECT 1 FROM farmer WHERE Farmer_ID = :id', ['id' => $values['Farmer_ID']])->fetchColumn() === false) {
                        throw new DomainException('farmer_missing');
                    }
                    if ($values['Soil_ID'] !== null
                        && runEditQuery($pdo, 'SELECT 1 FROM soil_type WHERE Soil_ID = :id', ['id' => $values['Soil_ID']])->fetchColumn() === false) {
                        throw new DomainException('soil_missing');
                    }
                } elseif ($entityName === 'planting') {
                    foreach ([['field', 'Field_ID', $values['Field_ID']], ['crop', 'Crop_ID', $values['Crop_ID']], ['season', 'Season_ID', $values['Season_ID']]] as [$table, $column, $id]) {
                        if (runEditQuery($pdo, 'SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = :id', ['id' => $id])->fetchColumn() === false) {
                            throw new DomainException('reference_missing');
                        }
                    }
                }

                $statusBefore = $entityName === 'support' ? (string) $current['Status'] : null;
                $updateSql = match ($entityName) {
                    'farmer' => 'UPDATE farmer SET Farmer_Name = :name, Contact_Number = :contact, Location_Description = :location WHERE Farmer_ID = :record_id',
                    'field' => 'UPDATE field SET Field_Name = :name, Farmer_ID = :farmer_id, Field_Location = :location, Soil_ID = :soil_id, Fertility_Level = :fertility WHERE Field_ID = :record_id',
                    'crop' => 'UPDATE crop SET Crop_Name = :name, Crop_Category = :category, General_Information = :information WHERE Crop_ID = :record_id',
                    'planting' => 'UPDATE planting_record SET Field_ID = :field_id, Crop_ID = :crop_id, Season_ID = :season_id, Planting_Year = :year, Planting_Date = :planting_date, Planting_Status = :status WHERE Planting_ID = :record_id',
                    'support' => 'UPDATE support_request SET Simplified_Message = :simplified, Urgency = :urgency, Status = :status WHERE Request_ID = :record_id',
                };
                $params = match ($entityName) {
                    'farmer' => ['name' => $values['Farmer_Name'], 'contact' => $nullable($values['Contact_Number']), 'location' => $nullable($values['Location_Description']), 'record_id' => $recordId],
                    'field' => ['name' => $values['Field_Name'], 'farmer_id' => $values['Farmer_ID'], 'location' => $values['Field_Location'], 'soil_id' => $values['Soil_ID'], 'fertility' => $nullable($values['Fertility_Level']), 'record_id' => $recordId],
                    'crop' => ['name' => $values['Crop_Name'], 'category' => $nullable($values['Crop_Category']), 'information' => $nullable($values['General_Information']), 'record_id' => $recordId],
                    'planting' => ['field_id' => $values['Field_ID'], 'crop_id' => $values['Crop_ID'], 'season_id' => $values['Season_ID'], 'year' => $values['Planting_Year'], 'planting_date' => $values['Planting_Date'], 'status' => $values['Planting_Status'], 'record_id' => $recordId],
                    'support' => ['simplified' => $nullable($values['Simplified_Message']), 'urgency' => $nullable($values['Urgency']), 'status' => $values['Status'], 'record_id' => $recordId],
                };
                runEditQuery($pdo, $updateSql, $params);
                $pdo->commit();

                if ($entityName === 'support' && $statusBefore !== $values['Status']) {
                    $stack = editSupportStack();
                    $stack->push(['request_id' => $recordId, 'previous_status' => $statusBefore, 'new_status' => $values['Status']]);
                    $_SESSION['support_undo_history'] = $stack->toArray();
                }
                $activityNames = ['farmer' => 'Farmer', 'field' => 'Field', 'crop' => 'Crop', 'planting' => 'Planting record', 'support' => 'Support request'];
                editAddActivity($activityNames[$entityName] . ' ID #' . $recordId . ' updated.');
                editRedirect($entity['page'], $entity['title'] . ' ID #' . $recordId . ' saved successfully.');
            } catch (DomainException $error) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash['message'] = match ($error->getMessage()) {
                    'not_found' => 'That record no longer exists. Return to its list and refresh.',
                    'farmer_missing' => 'The selected farmer no longer exists. Choose a current farmer.',
                    'soil_missing' => 'The selected soil type no longer exists. Choose a current soil type.',
                    'reference_missing' => 'One of the selected related records no longer exists. Refresh the page and try again.',
                    default => 'The record could not be updated.',
                };
            } catch (PDOException $error) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                    $flash['message'] = $entityName === 'farmer'
                        ? 'That contact number is already used by another farmer.'
                        : 'That crop name is already used by another crop.';
                } else {
                    error_log('KhetSaathi record edit database error (' . $entityName . ', SQLSTATE ' . $error->getCode() . ').');
                    $flash['message'] = 'The record could not be saved. Check the database connection and try again.';
                }
            } catch (Throwable $error) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('KhetSaathi record edit failed (' . $entityName . ', SQLSTATE ' . $error->getCode() . ').');
                $flash['message'] = 'The record could not be saved. No partial update was kept.';
            }
        }
    }
}

if ($databaseError && $flash === null) {
    $flash = ['type' => 'error', 'message' => 'The record could not be loaded. Check the database connection and try again.'];
}
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — <?= editEscape($entity['title']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/polish.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="index.php" aria-label="KhetSaathi dashboard home"><span class="brand-mark" aria-hidden="true">⌂</span><span class="brand-name">khet<span>saathi</span></span></a>
        <button class="nav-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false"><span class="nav-toggle-icon" aria-hidden="true">☰</span><span class="nav-toggle-label">Menu</span></button>
        <div class="nav-label">WORKSPACE</div>
        <nav class="nav-list" id="primary-navigation">
            <a class="nav-link" href="index.php">⌂ Overview</a><a class="nav-link" href="farmers.php">♙ Farmers</a><a class="nav-link" href="fields.php">♧ Fields</a><a class="nav-link" href="crops.php">♧ Crop catalogue</a><a class="nav-link" href="plantings.php">▦ Planting records</a><a class="nav-link" href="support.php">▤ Support inbox</a>
        </nav>
    </aside>
    <main class="main-content edit-main">
        <header class="topbar"><div class="breadcrumb"><a href="<?= editEscape($entity['page']) ?>">Back to list</a> <span>/</span> <strong><?= editEscape($entity['title']) ?></strong></div><div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span></div></header>
        <section class="farmers-intro edit-intro"><div><p class="eyebrow">RECORD MANAGEMENT <span>·</span> EDIT EXISTING DATA</p><h1><?= editEscape($entity['title']) ?><span>.</span></h1><p class="welcome-copy">Changes are saved to the existing record; its ID stays the same.</p></div></section>
        <?php if ($flash !== null): ?><div class="notice notice--error" role="alert"><?= editEscape($flash['message']) ?></div><?php endif; ?>
        <?php if (!$databaseError && is_array($record)): ?>
            <section class="farmer-form-panel edit-panel">
                <div class="farmer-section-heading"><span class="form-mark" aria-hidden="true">✎</span><div><p class="eyebrow">DATABASE ID #<?= editEscape($recordId) ?></p><h2><?= editEscape($entity['title']) ?></h2></div></div>
                <form method="post" action="edit.php?entity=<?= rawurlencode($entityName) ?>&amp;id=<?= editEscape($recordId) ?>" class="farmer-form edit-form">
                    <input type="hidden" name="csrf_token" value="<?= editEscape(appCsrfToken()) ?>">
                    <?php if ($entityName === 'farmer'): ?>
                        <label for="Farmer_Name">Farmer name <span class="required-mark">*</span></label><input id="Farmer_Name" name="Farmer_Name" maxlength="100" required value="<?= editEscape($values['Farmer_Name'] ?? '') ?>">
                        <label for="Contact_Number">Contact number</label><input id="Contact_Number" name="Contact_Number" maxlength="20" value="<?= editEscape($values['Contact_Number'] ?? '') ?>">
                        <label for="Location_Description">Location</label><input id="Location_Description" name="Location_Description" maxlength="255" value="<?= editEscape($values['Location_Description'] ?? '') ?>">
                    <?php elseif ($entityName === 'field'): ?>
                        <label for="Field_Name">Field name <span class="required-mark">*</span></label><input id="Field_Name" name="Field_Name" maxlength="100" required value="<?= editEscape($values['Field_Name'] ?? '') ?>">
                        <label for="Farmer_ID">Farmer <span class="required-mark">*</span></label><select id="Farmer_ID" name="Farmer_ID" required><option value="">Choose a farmer</option><?php foreach ($farmers as $farmer): ?><option value="<?= editEscape($farmer['Farmer_ID']) ?>"<?= $selected($values['Farmer_ID'] ?? '', $farmer['Farmer_ID']) ?>><?= editEscape($farmer['Farmer_Name']) ?> · Farmer ID #<?= editEscape($farmer['Farmer_ID']) ?></option><?php endforeach; ?></select>
                        <label for="Field_Location">Field location <span class="required-mark">*</span></label><input id="Field_Location" name="Field_Location" maxlength="255" required value="<?= editEscape($values['Field_Location'] ?? '') ?>">
                        <label for="Soil_ID">Soil type</label><select id="Soil_ID" name="Soil_ID"><option value="">Not specified</option><?php foreach ($soilTypes as $soil): ?><option value="<?= editEscape($soil['Soil_ID']) ?>"<?= $selected($values['Soil_ID'] ?? '', $soil['Soil_ID']) ?>><?= editEscape($soil['Soil_Name']) ?></option><?php endforeach; ?></select>
                        <label for="Fertility_Level">Fertility level</label><select id="Fertility_Level" name="Fertility_Level"><option value="">Not specified</option><?php foreach ($fertilityLevels as $level): ?><option value="<?= editEscape($level) ?>"<?= $selected($values['Fertility_Level'] ?? '', $level) ?>><?= editEscape($level) ?></option><?php endforeach; ?></select>
                    <?php elseif ($entityName === 'crop'): ?>
                        <label for="Crop_Name">Crop name <span class="required-mark">*</span></label><input id="Crop_Name" name="Crop_Name" maxlength="100" required value="<?= editEscape($values['Crop_Name'] ?? '') ?>">
                        <label for="Crop_Category">Category</label><input id="Crop_Category" name="Crop_Category" maxlength="50" value="<?= editEscape($values['Crop_Category'] ?? '') ?>">
                        <label for="General_Information">General information</label><textarea id="General_Information" name="General_Information" rows="6" maxlength="10000"><?= editEscape($values['General_Information'] ?? '') ?></textarea>
                        <p class="privacy-note">This updates the crop’s general catalogue details only. Guide and relationship records remain unchanged.</p>
                    <?php elseif ($entityName === 'planting'): ?>
                        <label for="Field_ID">Field and farmer <span class="required-mark">*</span></label><select id="Field_ID" name="Field_ID" required><option value="">Choose a field</option><?php foreach ($fields as $field): ?><option value="<?= editEscape($field['Field_ID']) ?>"<?= $selected($values['Field_ID'] ?? '', $field['Field_ID']) ?>><?= editEscape($field['Farmer_Name']) ?> · <?= editEscape($field['Field_Name']) ?> (Field ID #<?= editEscape($field['Field_ID']) ?>)</option><?php endforeach; ?></select>
                        <label for="Crop_ID">Crop <span class="required-mark">*</span></label><select id="Crop_ID" name="Crop_ID" required><option value="">Choose a crop</option><?php foreach ($crops as $crop): ?><option value="<?= editEscape($crop['Crop_ID']) ?>"<?= $selected($values['Crop_ID'] ?? '', $crop['Crop_ID']) ?>><?= editEscape($crop['Crop_Name']) ?></option><?php endforeach; ?></select>
                        <label for="Season_ID">Season <span class="required-mark">*</span></label><select id="Season_ID" name="Season_ID" required><option value="">Choose a season</option><?php foreach ($seasons as $season): ?><option value="<?= editEscape($season['Season_ID']) ?>"<?= $selected($values['Season_ID'] ?? '', $season['Season_ID']) ?>><?= editEscape($season['Season_Name']) ?></option><?php endforeach; ?></select>
                        <label for="Planting_Year">Planting year <span class="required-mark">*</span></label><input id="Planting_Year" name="Planting_Year" type="number" min="1901" max="2155" required value="<?= editEscape($values['Planting_Year'] ?? '') ?>">
                        <label for="Planting_Date">Planting date</label><input id="Planting_Date" name="Planting_Date" type="date" value="<?= editEscape($values['Planting_Date'] ?? '') ?>">
                        <label for="Planting_Status">Status <span class="required-mark">*</span></label><select id="Planting_Status" name="Planting_Status" required><?php foreach ($plantingStatuses as $status): ?><option value="<?= editEscape($status) ?>"<?= $selected($values['Planting_Status'] ?? '', $status) ?>><?= editEscape($status) ?></option><?php endforeach; ?></select>
                    <?php else: ?>
                        <label for="original-message">Original message <span class="optional-label">READ ONLY</span></label><div class="message-block" id="original-message"><p><?= nl2br(editEscape($record['Original_Message'] ?? '')) ?></p></div>
                        <label for="Simplified_Message">Simplified display text</label><textarea id="Simplified_Message" name="Simplified_Message" rows="5" maxlength="10000"><?= editEscape($values['Simplified_Message'] ?? '') ?></textarea>
                        <label for="Urgency">Urgency</label><select id="Urgency" name="Urgency"><option value="">Not specified</option><?php foreach ($urgencies as $urgency): ?><option value="<?= editEscape($urgency) ?>"<?= $selected($values['Urgency'] ?? '', $urgency) ?>><?= editEscape($urgency) ?></option><?php endforeach; ?></select>
                        <label for="Status">Status <span class="required-mark">*</span></label><select id="Status" name="Status" required><?php foreach ($statuses as $status): ?><option value="<?= editEscape($status) ?>"<?= $selected($values['Status'] ?? '', $status) ?>><?= editEscape($status) ?></option><?php endforeach; ?></select>
                        <p class="privacy-note">The original farmer message is preserved. A changed status is added to this session’s undo stack after the database transaction commits.</p>
                    <?php endif; ?>
                    <div class="edit-actions"><button type="submit" class="farmer-submit">Save changes <span aria-hidden="true">→</span></button><a class="edit-cancel" href="<?= editEscape($entity['page']) ?>">Cancel</a></div>
                </form>
            </section>
        <?php elseif ($databaseError): ?><div class="empty-state"><strong>Unable to load this record</strong><span>Check the local database connection, then try again.</span></div><?php endif; ?>
        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> RECORD EDITOR</span><span>Local demo · Existing record update</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
