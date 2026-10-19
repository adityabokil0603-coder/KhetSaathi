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

function fitsColumnLength(string $value, int $maximum): bool
{
    $characterCount = preg_match_all('/./us', $value);
    return $characterCount !== false && $characterCount <= $maximum;
}

$_SESSION['farmer_csrf_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'error', 'message' => 'Please check the form and try again.'];
    $postedToken = $_POST['csrf_token'] ?? '';
    $name = is_string($_POST['farmer_name'] ?? null) ? trim($_POST['farmer_name']) : '';
    $contact = is_string($_POST['contact_number'] ?? null) ? trim($_POST['contact_number']) : '';
    $location = is_string($_POST['location_description'] ?? null) ? trim($_POST['location_description']) : '';
    $formValues = ['name' => $name, 'contact' => $contact, 'location' => $location];

    if (!is_string($postedToken) || !hash_equals($_SESSION['farmer_csrf_token'], $postedToken)) {
        $flash['message'] = 'Your form session expired. Refresh the page and try again.';
    } elseif ($name === '') {
        $flash['message'] = 'Farmer name is required.';
    } elseif (!fitsColumnLength($name, 100) || !fitsColumnLength($contact, 20) || !fitsColumnLength($location, 255)) {
        $flash['message'] = 'Please keep the name to 100 characters, contact to 20, and location to 255.';
    } else {
        try {
            $pdo = createDatabaseConnection();
            $compatibilityOwnerId = databaseCompatibilityOwnerId($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO farmer (Farmer_Name, Contact_Number, Location_Description, owner_user_id)
                 VALUES (:name, :contact, :location, :owner_user_id)'
            );
            $insert->bindValue(':name', $name, PDO::PARAM_STR);
            $insert->bindValue(':contact', $contact === '' ? null : $contact, $contact === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':location', $location === '' ? null : $location, $location === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':owner_user_id', $compatibilityOwnerId, PDO::PARAM_INT);
            $insert->execute();

            $activityList = RecentActivityLinkedList::fromNewestFirstArray(
                is_array($_SESSION['recent_activity'] ?? null) ? $_SESSION['recent_activity'] : []
            );
            $farmerId = (int) $pdo->lastInsertId();
            $activityList->insertAtHead('Farmer ID #' . $farmerId . ' registered: ' . $name);
            $_SESSION['recent_activity'] = $activityList->toArray();

            $flash = ['type' => 'success', 'message' => 'Farmer registered successfully.'];
            $formValues = ['name' => '', 'contact' => '', 'location' => ''];
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                $flash['message'] = 'That contact number is already registered. Use a different number or leave it blank.';
            } else {
                error_log('KhetSaathi farmer registration error: ' . $error->getMessage());
                $flash['message'] = 'The farmer could not be saved. Check the database connection and try again.';
            }
        } catch (Throwable $error) {
            error_log('KhetSaathi farmer registration error: ' . $error->getMessage());
            $flash['message'] = 'The farmer could not be saved. Check the database connection and try again.';
        }
    }

    $_SESSION['farmers_flash'] = $flash;
    $_SESSION['farmer_form_values'] = $formValues;
    session_write_close();
    header('Location: farmers.php', true, 303);
    exit;
}

$flash = $_SESSION['farmers_flash'] ?? null;
$formValues = $_SESSION['farmer_form_values'] ?? ['name' => '', 'contact' => '', 'location' => ''];
unset($_SESSION['farmers_flash'], $_SESSION['farmer_form_values']);

$farmers = [];
$databaseError = false;
try {
    $pdo = createDatabaseConnection();
    $select = $pdo->query(
        'SELECT Farmer_ID, Farmer_Name, Contact_Number, Location_Description
         FROM farmer ORDER BY Farmer_ID ASC'
    );
    $farmers = $select->fetchAll();
} catch (Throwable $error) {
    error_log('KhetSaathi farmer list error: ' . $error->getMessage());
    $databaseError = true;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — Farmers</title>
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
            <a class="nav-link nav-link--active" href="farmers.php" aria-current="page"><span class="nav-icon">♙</span> Farmers</a>
            <a class="nav-link" href="fields.php"><span class="nav-icon">♧</span> Fields</a>
            <a class="nav-link" href="crops.php"><span class="nav-icon">♧</span> Crop catalogue</a>
            <a class="nav-link" href="plantings.php"><span class="nav-icon">▦</span> Planting records</a>
            <a class="nav-link" href="support.php"><span class="nav-icon">▤</span> Support inbox</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="season-card"><span class="season-kicker"><span class="live-dot"></span> YOUR FARM, AT A GLANCE</span><p>Good planning starts with knowing who’s growing.</p><span class="season-note">A simple workspace for your fields.</span></div>
        </div>
    </aside>

    <main class="main-content farmers-main">
        <header class="topbar"><div class="breadcrumb"><a href="index.php">Workspace</a> <span>/</span> <strong>Farmers</strong></div><div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span></div></header>
        <section class="farmers-intro">
            <div><p class="eyebrow">PEOPLE BEHIND THE HARVEST <span>·</span> FARM DIRECTORY</p><h1>Farmers<span>.</span></h1><p class="welcome-copy">Register a farmer and keep your local directory in one place.</p></div>
            <div class="inbox-count"><span class="inbox-count-label">REGISTERED FARMERS</span><strong><?= $databaseError ? '—' : e(count($farmers)) ?></strong><span>from your database</span></div>
        </section>

        <?php if ($actionFlash !== null): ?>
            <div class="notice <?= $actionFlash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="<?= $actionFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($actionFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($flash !== null): ?>
            <div class="notice <?= $flash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice--error" role="alert"><strong>Farmer records are unavailable.</strong> Check that MySQL is running and local database environment variables are set.</div>
        <?php endif; ?>

        <section class="farmer-content-grid">
            <div class="farmer-form-panel">
                <div class="farmer-section-heading"><span class="form-mark"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20c.4-3.5 2.4-5.3 6-5.3 2.1 0 3.7.6 4.7 1.8"/><path d="M18 13v6m-3-3h6"/></svg></span><div><p class="eyebrow">NEW DIRECTORY ENTRY</p><h2>Register a farmer</h2></div></div>
                <p class="form-intro">Add the basic contact details below. Only the farmer’s name is required.</p>
                <form method="post" action="farmers.php" class="farmer-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['farmer_csrf_token']) ?>">
                    <label for="farmer-name">Farmer name <span class="required-mark">*</span></label>
                    <input id="farmer-name" name="farmer_name" type="text" maxlength="100" autocomplete="name" required value="<?= e($formValues['name'] ?? '') ?>" placeholder="e.g. Ananya Sharma">
                    <label for="contact-number">Contact number <span class="optional-label">OPTIONAL</span></label>
                    <input id="contact-number" name="contact_number" type="tel" maxlength="20" autocomplete="tel" value="<?= e($formValues['contact'] ?? '') ?>" placeholder="e.g. +91 98765 43210">
                    <span class="field-hint">Each contact number can belong to one farmer. Leave blank if unavailable.</span>
                    <label for="farmer-location">Location <span class="optional-label">OPTIONAL</span></label>
                    <input id="farmer-location" name="location_description" type="text" maxlength="255" autocomplete="address-level2" value="<?= e($formValues['location'] ?? '') ?>" placeholder="Village, district or region">
                    <button type="submit" class="farmer-submit">Save farmer <span aria-hidden="true">→</span></button>
                    <p class="privacy-note"><span aria-hidden="true">⌑</span> Farmer details are stored in your local KhetSaathi database.</p>
                </form>
            </div>

            <section class="farmer-directory" aria-labelledby="farmer-directory-title">
                <div class="directory-heading"><div><p class="eyebrow">YOUR COMMUNITY</p><h2 id="farmer-directory-title">Farmer directory</h2></div><div class="directory-tools"><label class="list-filter"><span class="list-filter-icon" aria-hidden="true">⌕</span><input type="search" data-list-filter="#farmer-rows > tr" aria-label="Search farmers" placeholder="Search farmers"><span class="filter-count" data-filter-count aria-live="polite"></span></label><span class="directory-total"><?= $databaseError ? '—' : e(count($farmers)) ?> <small>records</small></span></div></div>
                <?php if (!$databaseError && $farmers !== []): ?>
                    <div class="farmer-table-wrap">
                        <table class="farmer-table">
                            <thead><tr><th scope="col">FARMER</th><th scope="col">CONTACT</th><th scope="col">LOCATION</th><th scope="col">ACTION</th></tr></thead>
                            <tbody id="farmer-rows">
                            <?php foreach ($farmers as $rowNumber => $farmer): ?>
                                <tr>
                                    <td><div class="directory-person"><span class="directory-avatar" aria-hidden="true">✳</span><span><strong><?= e($farmer['Farmer_Name']) ?></strong><small>Record #<?= e($rowNumber + 1) ?> · Farmer ID #<?= e($farmer['Farmer_ID']) ?></small></span></div></td>
                                    <td><?= $farmer['Contact_Number'] === null || $farmer['Contact_Number'] === '' ? '<span class="not-provided">Not provided</span>' : e($farmer['Contact_Number']) ?></td>
                                    <td><?= $farmer['Location_Description'] === null || $farmer['Location_Description'] === '' ? '<span class="not-provided">Not provided</span>' : e($farmer['Location_Description']) ?></td>
                                    <td><div class="row-actions"><a class="edit-button" href="edit.php?entity=farmer&amp;id=<?= e($farmer['Farmer_ID']) ?>">Edit</a><form method="post" action="delete.php" data-confirm="Delete farmer <?= e($farmer['Farmer_Name']) ?> (database ID #<?= e($farmer['Farmer_ID']) ?>)? This is allowed only when the farmer has no fields.">
                                        <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                                        <input type="hidden" name="entity" value="farmer">
                                        <input type="hidden" name="record_id" value="<?= e($farmer['Farmer_ID']) ?>">
                                        <button class="danger-button" type="submit">Delete</button>
                                    </form></div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!$databaseError): ?>
                    <div class="empty-state directory-empty"><span class="empty-icon">♧</span><strong>No farmers registered yet</strong><span>Your first directory entry will appear here.</span></div>
                <?php else: ?>
                    <div class="empty-state directory-empty"><span class="empty-icon">!</span><strong>Unable to load farmers</strong><span>Restore the database connection and refresh this page.</span></div>
                <?php endif; ?>
                <div class="directory-footer"><span>Showing all registered farmers</span><span class="read-only-tag">LIVE DATABASE</span></div>
            </section>
        </section>
        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> FARMER DIRECTORY</span><span>Local demo · Farmer registration</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
