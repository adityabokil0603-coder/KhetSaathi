<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/security.php';
startKhetSaathiSession();
sendAppSecurityHeaders();
$actionFlash = consumeActionFlash();

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/data-structures/RecentActivityLinkedList.php';

$activityList = RecentActivityLinkedList::fromNewestFirstArray(
    is_array($_SESSION['recent_activity'] ?? null) ? $_SESSION['recent_activity'] : []
);
$recentActivities = $activityList->getActivities();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$counts = [
    'farmers' => null,
    'fields' => null,
    'crops' => null,
    'plantings' => null,
    'requests' => null,
];
$supportRequests = [];
$supportDisplayNumbers = [];
$databaseError = false;

try {
    $pdo = createDatabaseConnection();

    $countQueries = [
        'farmers' => 'SELECT COUNT(*) FROM farmer',
        'fields' => 'SELECT COUNT(*) FROM field',
        'crops' => 'SELECT COUNT(*) FROM crop',
        'plantings' => 'SELECT COUNT(*) FROM planting_record',
        'requests' => 'SELECT COUNT(*) FROM support_request',
    ];

    foreach ($countQueries as $key => $sql) {
        $statement = $pdo->query($sql);
        $counts[$key] = (int) $statement->fetchColumn();
    }

    // Keep dashboard positions consistent with the FIFO order used by the Support page.
    $supportIdStatement = $pdo->query(
        'SELECT Request_ID FROM support_request ORDER BY Submitted_At ASC, Request_ID ASC'
    );
    foreach ($supportIdStatement->fetchAll(PDO::FETCH_COLUMN) as $rowNumber => $requestId) {
        $supportDisplayNumbers[(string) $requestId] = $rowNumber + 1;
    }

    $requestStatement = $pdo->query(
        'SELECT Request_ID, Original_Message, Urgency, Status, Submitted_At
         FROM support_request
         ORDER BY Submitted_At DESC, Request_ID DESC
         LIMIT 6'
    );
    $supportRequests = $requestStatement->fetchAll();
} catch (Throwable $error) {
    // Keep details in the server log; never send connection or query details to the browser.
    error_log('KhetSaathi dashboard database error: ' . $error->getMessage());
    $databaseError = true;
}

$stats = [
    ['label' => 'Farmers', 'value' => $counts['farmers'], 'icon' => 'people', 'tone' => 'sage'],
    ['label' => 'Fields', 'value' => $counts['fields'], 'icon' => 'field', 'tone' => 'lime'],
    ['label' => 'Crop varieties', 'value' => $counts['crops'], 'icon' => 'sprout', 'tone' => 'cream'],
    ['label' => 'Planting records', 'value' => $counts['plantings'], 'icon' => 'calendar', 'tone' => 'blue'],
    ['label' => 'Support requests', 'value' => $counts['requests'], 'icon' => 'chat', 'tone' => 'peach'],
];

/** Older session messages may contain primary keys without labeling them as IDs. */
function dashboardActivityLabel(string $activity): string
{
    $activity = preg_replace(
        '/\b(Farmer|Field|Crop|Planting record|Support request|Request) #(\d+)\b/',
        '$1 ID #$2',
        $activity
    ) ?? $activity;
    $activity = preg_replace_callback(
        '/\b(Farmer|Field|Crop|Planting record|Support request)(?: record)? deleted: #(\d+)/',
        static fn (array $match): string => $match[1] . ' ID #' . $match[2] . ' deleted',
        $activity
    ) ?? $activity;

    return e($activity);
}

function icon(string $name): string
{
    $paths = [
        'people' => '<circle cx="9" cy="8" r="3"/><path d="M3 19c.4-3.2 2.5-5 6-5s5.6 1.8 6 5"/><path d="M16 5.2a3 3 0 0 1 0 5.7M17 14c2.3.5 3.5 2.1 3.8 4.5"/>',
        'field' => '<path d="M3 20h18M5 17l4-5 3 3 6-8 3 3"/><path d="M5 20V8m5 12v-4m5 4v-3m5 3V7"/>',
        'sprout' => '<path d="M12 21v-9"/><path d="M12 12C7 12 5 9 5 5c4 0 7 2 7 7Zm0 2c0-4 2-7 7-7 0 4-2 7-7 7Z"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'chat' => '<path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8 8 0 0 1-3.5-.8L4 20l1.3-3.8A7.1 7.1 0 0 1 4 12c0-4.1 3.6-7.5 8-7.5s8 3.1 8 7Z"/>',
    ];

    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}

function badgeClass(string $value): string
{
    return match ($value) {
        'High' => 'badge badge--high',
        'Medium', 'Under Review' => 'badge badge--medium',
        'Low', 'Resolved' => 'badge badge--low',
        'Pending' => 'badge badge--pending',
        default => 'badge',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173d32">
    <title>KhetSaathi — Field dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/polish.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="#overview" aria-label="KhetSaathi dashboard home">
            <span class="brand-mark"><?= icon('sprout') ?></span>
            <span class="brand-name">khet<span>saathi</span></span>
        </a>
        <button class="nav-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false"><span class="nav-toggle-icon" aria-hidden="true">☰</span><span class="nav-toggle-label">Menu</span></button>
        <div class="nav-label">WORKSPACE</div>
        <nav class="nav-list" id="primary-navigation">
            <a class="nav-link nav-link--active" href="#overview"><span class="nav-icon">⌂</span> Overview</a>
            <a class="nav-link" href="farmers.php"><span class="nav-icon">♙</span> Farmers</a>
            <a class="nav-link" href="fields.php"><span class="nav-icon">♧</span> Fields</a>
            <a class="nav-link" href="crops.php"><span class="nav-icon">♧</span> Crop catalogue</a>
            <a class="nav-link" href="plantings.php"><span class="nav-icon">▦</span> Planting records</a>
            <a class="nav-link" href="support.php"><span class="nav-icon">▤</span> Support inbox <span class="nav-count"><?= $databaseError || $counts['requests'] === null ? '—' : e($counts['requests']) ?></span></a>
        </nav>
        <div class="sidebar-bottom">
            <div class="season-card">
                <span class="season-kicker"><span class="live-dot"></span> YOUR FARM, AT A GLANCE</span>
                <p>Good planning starts with knowing what’s growing.</p>
                <span class="season-note">A simple workspace for your fields.</span>
            </div>
        </div>
    </aside>

    <main class="main-content" id="overview">
        <header class="topbar">
            <div class="breadcrumb">Workspace <span>/</span> <strong>Overview</strong></div>
            <div class="topbar-right"><span class="prototype-pill"><span></span> PROTOTYPE</span><span class="today-date"><?= e(date('D, d M Y')) ?></span></div>
        </header>

        <section class="welcome-row">
            <div>
                <p class="eyebrow">FIELD NOTES <span>·</span> YOUR AGRICULTURE OVERVIEW</p>
                <h1>A clearer view<br class="mobile-break"> of your farm<span>.</span></h1>
                <p class="welcome-copy">Your people, plots, and planting activity — together in one place.</p>
            </div>
            <div class="welcome-art" aria-hidden="true">
                <div class="sun-disc"></div><div class="hill hill--back"></div><div class="hill hill--front"></div>
                <div class="art-stem stem-one"><i></i><b></b></div><div class="art-stem stem-two"><i></i><b></b></div><div class="art-stem stem-three"><i></i><b></b></div>
            </div>
        </section>

        <?php if ($actionFlash !== null): ?>
            <div class="notice <?= $actionFlash['type'] === 'success' ? 'notice--success' : 'notice--error' ?>" role="<?= $actionFlash['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($actionFlash['message']) ?></div>
        <?php endif; ?>
        <?php if ($databaseError): ?>
            <div class="notice notice--error" role="alert"><strong>Dashboard data is unavailable.</strong> Check that MySQL is running and your local database environment variables are set. No database details are shown here.</div>
        <?php endif; ?>

        <section class="stats-grid" aria-label="Database totals">
            <?php foreach ($stats as $stat): ?>
                <article class="stat-card stat-card--<?= e($stat['tone']) ?>">
                    <div class="stat-top"><span class="stat-label"><?= e($stat['label']) ?></span><span class="stat-icon"><?= icon($stat['icon']) ?></span></div>
                    <div class="stat-value"><?= $stat['value'] === null ? '—' : e(number_format($stat['value'])) ?></div>
                    <div class="stat-foot"><span class="stat-dot"></span> <?= $databaseError ? 'Connection unavailable' : 'In your database' ?></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="quick-actions" aria-label="Common tasks">
            <div class="quick-actions-heading"><div><p class="eyebrow">QUICK ACCESS</p><h2>Keep the work moving</h2></div><span class="read-only-tag">SHORTCUTS</span></div>
            <div class="quick-action-grid">
                <a class="quick-action" href="farmers.php"><span class="quick-action-icon">♙</span><span><strong>Register a farmer</strong><small>Add a person to the directory</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
                <a class="quick-action" href="fields.php"><span class="quick-action-icon">⌁</span><span><strong>Add a field</strong><small>Connect land to a farmer</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
                <a class="quick-action" href="plantings.php"><span class="quick-action-icon">▦</span><span><strong>View planting records</strong><small>Review seasonal activity</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
                <a class="quick-action quick-action--accent" href="support.php"><span class="quick-action-icon">✳</span><span><strong>Review support requests</strong><small>Open the FIFO support inbox</small></span><span class="quick-action-arrow" aria-hidden="true">↗</span></a>
            </div>
        </section>

        <section class="content-grid" id="requests">
            <div class="panel requests-panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">NEEDS A LOOK</p><h2>Recent support requests</h2><p class="panel-subtitle">Latest messages submitted by your farming community.</p></div>
                    <span class="panel-icon"><?= icon('chat') ?></span>
                </div>
                <?php if (!$databaseError && $supportRequests !== []): ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th scope="col">REQUEST</th><th scope="col">URGENCY</th><th scope="col">STATUS</th><th scope="col">SUBMITTED</th></tr></thead>
                            <tbody>
                            <?php foreach ($supportRequests as $request): ?>
                                <tr>
                                    <td><div class="request-cell"><span class="request-id">Record #<?= e($supportDisplayNumbers[(string) $request['Request_ID']] ?? '—') ?><br><small>ID #<?= e($request['Request_ID']) ?></small></span><span class="request-message" title="<?= e($request['Original_Message']) ?>"><?= e($request['Original_Message']) ?></span></div></td>
                                    <td><span class="<?= e(badgeClass((string) $request['Urgency'])) ?>"><i></i><?= e($request['Urgency'] ?? 'Not set') ?></span></td>
                                    <td><span class="<?= e(badgeClass((string) $request['Status'])) ?>"><i></i><?= e($request['Status']) ?></span></td>
                                    <td class="date-cell"><?= e($request['Submitted_At']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!$databaseError): ?>
                    <div class="empty-state"><span class="empty-icon"><?= icon('chat') ?></span><strong>No support requests yet</strong><span>Submitted requests will appear here.</span></div>
                <?php else: ?>
                    <div class="empty-state"><span class="empty-icon">!</span><strong>Unable to load requests</strong><span>Restore the database connection and refresh this page.</span></div>
                <?php endif; ?>
                <div class="panel-footer"><span>Showing up to 6 most recent requests</span><span class="read-only-tag">READ ONLY</span></div>
            </div>

            <aside class="side-note">
                <div class="note-orbit note-orbit--one"></div><div class="note-orbit note-orbit--two"></div>
                <span class="note-spark">✳</span>
                <p class="eyebrow">BUILT FOR THE FIELD</p>
                <h2>Small steps.<br>Stronger seasons.</h2>
                <p class="note-copy">KhetSaathi brings your farm records into a simple, easy-to-follow workspace.</p>
                <div class="note-divider"></div>
                <p class="note-caption">A college project prototype<br><strong>Designed with farmers in mind</strong></p>
                <span class="note-leaf" aria-hidden="true"><?= icon('sprout') ?></span>
            </aside>
        </section>

        <section class="activity-panel" aria-labelledby="recent-activity-title">
            <div class="activity-heading">
                <div><p class="eyebrow">THIS SESSION</p><h2 id="recent-activity-title">Recent activity</h2></div>
                <span class="read-only-tag">IN-MEMORY LIST</span>
            </div>
            <?php if ($recentActivities !== []): ?>
                <ol class="activity-list">
                    <?php foreach ($recentActivities as $activity): ?>
                        <li><span class="activity-marker" aria-hidden="true"></span><span><?= dashboardActivityLabel($activity) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="activity-empty">Successful farmer, field, and support status actions in this session will appear here.</p>
            <?php endif; ?>
            <p class="activity-note">This recent-activity list is stored in the current PHP session and is not permanent history.</p>
        </section>

        <section class="reset-panel" aria-labelledby="reset-operational-title">
            <div class="reset-panel-copy">
                <p class="eyebrow">DESTRUCTIVE ACTION</p>
                <h2 id="reset-operational-title">Reset All Operational Data</h2>
                <p>This permanently removes support requests, planting records, fields, and farmers. Crops, guides, seasons, and soil reference data are preserved.</p>
            </div>
            <form method="post" action="delete.php" class="reset-form" data-confirm="Reset all operational data? This cannot be undone.">
                <input type="hidden" name="csrf_token" value="<?= e(appCsrfToken()) ?>">
                <input type="hidden" name="action" value="reset_operational">
                <label for="reset-confirmation">Type <strong>RESET</strong> to confirm</label>
                <div class="reset-controls">
                    <input id="reset-confirmation" name="confirmation" type="text" required pattern="RESET" autocomplete="off" aria-describedby="reset-warning">
                    <button class="danger-button danger-button--reset" type="submit">Reset All Operational Data</button>
                </div>
                <small id="reset-warning">This action cannot be undone. Auto-increment counters are not reset.</small>
            </form>
        </section>

        <footer class="page-footer"><span>KHETSAATHI <span class="footer-sep">/</span> FIELD MANAGEMENT PROTOTYPE</span><span>Indicative information only · Local demo</span></footer>
    </main>
</div>
<script src="assets/js/app.js" defer></script>
</body>
</html>
