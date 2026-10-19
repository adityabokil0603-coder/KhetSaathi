<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/data-structures/CropHashTable.php';

startKhetSaathiSession();
sendAppSecurityHeaders();
$actionFlash = consumeActionFlash();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!isValidAppCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'Your form session expired. Refresh and try again.'];
    } else {
        $name = is_string($_POST['crop_name'] ?? null) ? trim($_POST['crop_name']) : '';
        $category = is_string($_POST['crop_category'] ?? null) ? trim($_POST['crop_category']) : '';
        $information = is_string($_POST['general_information'] ?? null) ? trim($_POST['general_information']) : '';
        $length = static fn (string $value): int|false => preg_match_all('/./us', $value);
        if ($name === '' || $length($name) === false || $length($name) > 100
            || $length($category) === false || $length($category) > 50
            || $length($information) === false || $length($information) > 10000) {
            $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'Enter a crop name (100 characters maximum), category (50 maximum), and information (10,000 maximum).'];
        } else {
            try {
                $pdo = createDatabaseConnection();
                $insert = $pdo->prepare('INSERT INTO crop (Crop_Name, Crop_Category, General_Information) VALUES (:name, :category, :information)');
                $insert->execute(['name' => $name, 'category' => $category === '' ? null : $category, 'information' => $information === '' ? null : $information]);
                $_SESSION['action_flash'] = ['type' => 'success', 'message' => 'Crop added to the shared catalogue.'];
            } catch (PDOException $error) {
                $_SESSION['action_flash'] = ['type' => 'error', 'message' => $error->getCode() === '23000' ? 'A crop with that name already exists.' : 'The crop could not be saved. Please try again.'];
                if ($error->getCode() !== '23000') { error_log('KhetSaathi crop creation failed (' . $error->getCode() . ').'); }
            } catch (Throwable $error) {
                error_log('KhetSaathi crop creation failed.');
                $_SESSION['action_flash'] = ['type' => 'error', 'message' => 'The crop could not be saved. Please try again.'];
            }
        }
    }
    session_write_close();
    header('Location: crops.php', true, 303);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cropRange(mixed $minimum, mixed $maximum, string $unit): string
{
    $hasMinimum = $minimum !== null && $minimum !== '';
    $hasMaximum = $maximum !== null && $maximum !== '';

    if (!$hasMinimum && !$hasMaximum) {
        return 'Not recorded';
    }
    if (!$hasMinimum) {
        return 'Up to ' . $maximum . $unit;
    }
    if (!$hasMaximum) {
        return 'At least ' . $minimum . $unit;
    }

    return $minimum . '–' . $maximum . $unit;
}

function safeSourceUrl(mixed $url): ?string
{
    if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

$crops = [];
$matchingCrop = null;
$searchAttempted = array_key_exists('q', $_GET);
$searchInput = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$searchMessage = null;
$databaseError = false;
$cropHashTable = new CropHashTable();

try {
    $pdo = createDatabaseConnection();
    $cropStatement = $pdo->prepare(
        'SELECT crop.Crop_ID, crop.Crop_Name, crop.Crop_Category, crop.General_Information,
                guide.Temperature_Min_C, guide.Temperature_Max_C,
                guide.Rainfall_Min_mm, guide.Rainfall_Max_mm, guide.Water_Requirement,
                guide.Growing_Duration_Min_Days, guide.Growing_Duration_Max_Days,
                guide.Source_Name, guide.Source_URL
         FROM crop AS crop
         LEFT JOIN crop_guide AS guide ON guide.Crop_ID = crop.Crop_ID
         ORDER BY crop.Crop_Name, crop.Crop_ID'
    );
    $cropStatement->execute();
    $crops = $cropStatement->fetchAll();

    foreach ($crops as $crop) {
        $cropHashTable->insert($crop);
    }

    if ($searchAttempted) {
        if ($searchInput === '') {
            $searchMessage = 'Enter a crop ID or crop name to search.';
        } elseif (preg_match('/^[0-9]+$/D', $searchInput) === 1) {
            $cropId = filter_var($searchInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($cropId === false) {
                $searchMessage = 'Enter a valid positive crop ID.';
            } else {
                $matchingCrop = $cropHashTable->searchById($cropId);
                // A numeric crop name is still searchable if no crop has that ID.
                if ($matchingCrop === null) {
                    $matchingCrop = $cropHashTable->searchByName($searchInput);
                }
            }
        } else {
            $nameLength = preg_match_all('/./us', $searchInput);
            if ($nameLength === false || $nameLength > 100) {
                $searchMessage = 'Crop name must be 100 characters or fewer.';
            } else {
                $matchingCrop = $cropHashTable->searchByName($searchInput);
            }
        }
    }
} catch (Throwable $error) {
    error_log('KhetSaathi crop lookup page error: ' . $error->getMessage());
    $databaseError = true;
    $crops = [];
    $matchingCrop = null;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — Crop lookup</title>
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
            <a class="nav-link nav-link--active" href="crops.php" aria-current="page"><span class="nav-icon">♧</span> Crop catalogue</a>
            <a class="nav-link" href="plantings.php"><span class="nav-icon">▦</span> Planting records</a>
            <a class="nav-link" href="support.php"><span class="nav-icon">▤</span> Support inbox</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="season-card"><span class="season-kicker"><span class="live-dot"></span> CROP LOOKUP</span><p>Find catalogue records by crop ID or name.</p><span class="season-note">Searches the in-memory hash table.</span></div>
        </div>
    </aside>

    <main class="main-content crops-main">
        <header class="topbar"><div class="breadcrumb"><a href="index.php">Workspace</a> <span>/</span> <strong>Crop catalogue</strong></div><div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span></div></header>
        <section class="crops-intro">
            <div><p class="eyebrow">LOOK UP A VARIETY <span>·</span> CROP CATALOGUE</p><h1>Crop lookup<span>.</span></h1><p class="welcome-copy">Search crop records by ID or name using a PHP hash table.</p></div>
            <div class="inbox-count"><span class="inbox-count-label">LOADED CROPS</span><strong><?= $databaseError ? '—' : e(count($crops)) ?></strong><span>indexed for this page</span></div>
        </section>

            <section class="farmer-form-panel crop-create-panel" aria-labelledby="crop-create-title">
                <div class="panel-heading"><div><p class="eyebrow">CATALOGUE</p><h2 id="crop-create-title">Add a shared crop</h2></div></div>
                <form method="post" action="crops.php" class="farmer-form">
                    <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                    <label for="crop-name">Crop name <span class="required-mark">*</span></label><input id="crop-name" name="crop_name" maxlength="100" required>
                    <label for="crop-category">Category</label><input id="crop-category" name="crop_category" maxlength="50">
                    <label for="crop-information">General information</label><textarea id="crop-information" name="general_information" rows="4" maxlength="10000"></textarea>
                    <button type="submit" class="farmer-submit">Add crop <span aria-hidden="true">→</span></button>
                </form>
            </section>

        <?php if ($actionFlash !== null): ?>
            <div class="notice <?= $actionFlash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="<?= $actionFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($actionFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice--error" role="alert"><strong>Crop catalogue is unavailable.</strong> Check that MySQL is running and local database environment variables are set.</div>
        <?php endif; ?>

        <section class="crop-lookup-grid">
            <div class="farmer-form-panel crop-search-panel">
                <div class="farmer-section-heading"><span class="form-mark"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg></span><div><p class="eyebrow">HASH TABLE SEARCH</p><h2>Find a crop</h2></div></div>
                <p class="form-intro">Enter a numeric ID or a crop name. Name searches ignore surrounding spaces and letter case.</p>
                <form method="get" action="crops.php" class="farmer-form crop-search-form">
                    <label for="crop-query">Crop ID or name</label>
                    <input id="crop-query" name="q" type="search" maxlength="100" value="<?= e($searchInput) ?>" placeholder="Try an ID or crop name" <?= $databaseError ? 'disabled' : '' ?> required>
                    <button type="submit" class="farmer-submit" <?= $databaseError ? 'disabled' : '' ?>>Search catalogue <span aria-hidden="true">→</span></button>
                </form>
                <div class="hash-note"><span class="hash-symbol" aria-hidden="true">#</span><div><strong>Fast key-based lookup</strong><span>Each crop is indexed by ID and name. Colliding keys share a bucket chain.</span></div></div>
            </div>

            <section class="crop-results" aria-live="polite" aria-label="Crop search result">
                <?php if ($searchAttempted && !$databaseError && $searchMessage !== null): ?>
                    <div class="crop-result-message"><span class="empty-icon">!</span><strong><?= e($searchMessage) ?></strong></div>
                <?php elseif ($searchAttempted && !$databaseError && $matchingCrop !== null): ?>
                    <article class="crop-result-card">
                        <div class="crop-result-heading"><div><p class="eyebrow">MATCH FOUND</p><h2><?= e($matchingCrop['Crop_Name']) ?></h2></div><span class="crop-result-id">CROP ID #<?= e($matchingCrop['Crop_ID']) ?></span></div>
                        <div class="crop-result-meta"><span class="crop-category"><?= $matchingCrop['Crop_Category'] === null || $matchingCrop['Crop_Category'] === '' ? 'Category not specified' : e($matchingCrop['Crop_Category']) ?></span></div>
                        <div class="crop-info"><span>GENERAL INFORMATION</span><p><?= $matchingCrop['General_Information'] === null || trim((string) $matchingCrop['General_Information']) === '' ? 'No general information is recorded for this crop.' : nl2br(e($matchingCrop['General_Information'])) ?></p></div>
                        <?php
                        $guideColumns = [
                            'Temperature_Min_C', 'Temperature_Max_C', 'Rainfall_Min_mm', 'Rainfall_Max_mm',
                            'Water_Requirement', 'Growing_Duration_Min_Days', 'Growing_Duration_Max_Days',
                            'Source_Name', 'Source_URL',
                        ];
                        $hasGuideDetails = false;
                        foreach ($guideColumns as $guideColumn) {
                            if (($matchingCrop[$guideColumn] ?? null) !== null && $matchingCrop[$guideColumn] !== '') {
                                $hasGuideDetails = true;
                                break;
                            }
                        }
                        ?>
                        <?php if ($hasGuideDetails): ?>
                            <section class="crop-guide" aria-label="Indicative crop guide details">
                                <div class="crop-guide-heading"><span>DATABASE GUIDE DETAILS</span><span class="guide-tag">INDICATIVE</span></div>
                                <dl class="crop-guide-grid">
                                    <div><dt>Temperature range</dt><dd><?= e(cropRange($matchingCrop['Temperature_Min_C'], $matchingCrop['Temperature_Max_C'], ' °C')) ?></dd></div>
                                    <div><dt>Rainfall range</dt><dd><?= e(cropRange($matchingCrop['Rainfall_Min_mm'], $matchingCrop['Rainfall_Max_mm'], ' mm')) ?></dd></div>
                                    <div><dt>Water requirement</dt><dd><?= e($matchingCrop['Water_Requirement'] ?? 'Not recorded') ?></dd></div>
                                    <div><dt>Growing duration</dt><dd><?= e(cropRange($matchingCrop['Growing_Duration_Min_Days'], $matchingCrop['Growing_Duration_Max_Days'], ' days')) ?></dd></div>
                                </dl>
                                <p class="crop-guide-caveat">These stored values are illustrative demo information, not verified agricultural recommendations. Actual conditions vary by region, crop variety, and local practice.</p>
                                <?php $sourceUrl = safeSourceUrl($matchingCrop['Source_URL'] ?? null); ?>
                                <?php if (!empty($matchingCrop['Source_Name'])): ?>
                                    <p class="crop-guide-source">Source on record: <?= e($matchingCrop['Source_Name']) ?><?php if ($sourceUrl !== null): ?> · <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Open source</a><?php endif; ?></p>
                                <?php elseif ($sourceUrl !== null): ?>
                                    <p class="crop-guide-source"><a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Open recorded source</a></p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </article>
                <?php elseif ($searchAttempted && !$databaseError): ?>
                    <div class="crop-result-message crop-result-message--not-found"><span class="empty-icon">?</span><strong>No crop matched “<?= e($searchInput) ?>”.</strong><span>Check the ID or choose a name from the catalogue.</span></div>
                <?php else: ?>
                    <div class="crop-result-message crop-result-message--hint"><span class="empty-icon">⌕</span><strong>Your crop details will appear here.</strong><span>Search by a crop ID or name to get started.</span></div>
                <?php endif; ?>
            </section>
        </section>

        <section class="farmer-directory crop-catalogue" aria-labelledby="catalogue-title">
            <div class="directory-heading"><div><p class="eyebrow">AVAILABLE RECORDS</p><h2 id="catalogue-title">Crop catalogue</h2></div><div class="directory-tools"><label class="list-filter"><span class="list-filter-icon" aria-hidden="true">⌕</span><input type="search" data-list-filter="#crop-rows > tr" aria-label="Filter crop catalogue" placeholder="Filter catalogue"><span class="filter-count" data-filter-count aria-live="polite"></span></label><span class="directory-total"><?= $databaseError ? '—' : e(count($crops)) ?> <small>crops</small></span></div></div>
            <?php if (!$databaseError && $crops !== []): ?>
                <div class="farmer-table-wrap"><table class="farmer-table crop-table"><thead><tr><th scope="col">CROP</th><th scope="col">CATEGORY</th><th scope="col">LOOKUP KEY</th><th scope="col">ACTION</th></tr></thead><tbody id="crop-rows">
                <?php foreach ($crops as $crop): ?>
                    <tr><td><strong class="catalogue-name"><?= e($crop['Crop_Name']) ?></strong></td><td><?= $crop['Crop_Category'] === null || $crop['Crop_Category'] === '' ? '<span class="not-provided">Not specified</span>' : e($crop['Crop_Category']) ?></td><td><a class="crop-id-link" href="crops.php?q=<?= rawurlencode((string) $crop['Crop_ID']) ?>">Crop ID #<?= e($crop['Crop_ID']) ?> <span aria-hidden="true">↗</span></a></td><td><div class="row-actions"><a class="edit-button" href="edit.php?entity=crop&amp;id=<?= e($crop['Crop_ID']) ?>">Edit</a><form method="post" action="delete.php" data-confirm="Delete crop <?= e($crop['Crop_Name']) ?> (database ID #<?= e($crop['Crop_ID']) ?>)? It can be deleted only when it has no guide, mapping, or planting records.">
                        <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                        <input type="hidden" name="entity" value="crop">
                        <input type="hidden" name="record_id" value="<?= e($crop['Crop_ID']) ?>">
                        <button class="danger-button" type="submit">Delete</button>
                        </form></div></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <?php elseif (!$databaseError): ?>
                <div class="empty-state directory-empty"><span class="empty-icon">♧</span><strong>No crops in the catalogue</strong><span>Crop records will appear here when available in the database.</span></div>
            <?php else: ?>
                <div class="empty-state directory-empty"><span class="empty-icon">!</span><strong>Unable to load crops</strong><span>Restore the database connection and refresh this page.</span></div>
            <?php endif; ?>
            <div class="directory-footer"><span>Catalogue loaded from the existing Crop table</span><span class="read-only-tag">SHARED CATALOGUE</span></div>
        </section>

        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> CROP LOOKUP</span><span>Indicative information only · Local demo</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
