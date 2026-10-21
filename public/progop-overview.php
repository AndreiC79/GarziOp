<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$db = Database::connect($config['database']);
$user = Auth::user($db);
if (!$user || !in_array('progop.view', Auth::permissions($db, (int) $user['id']), true)) {
    header('Location: index.php');
    exit;
}
$isOperatingRoomDashboard = Auth::hasRole($db, (int)$user['id'], 'operating_room_dashboard');
$dashboardView = $isOperatingRoomDashboard && (string)($_GET['view'] ?? '') === 'modern' ? 'modern' : 'classic';
if ((int)$user['must_change_password'] === 1) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#123f48">
    <link rel="icon" href="assets/platform-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/platform-icon.svg">
    <link rel="manifest" href="manifest.webmanifest">
    <title>Overview Bloc Operator</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="assets/progop-overview.css?v=<?= filemtime(__DIR__ . '/assets/progop-overview.css') ?>">
    <?php if ($dashboardView === 'modern'): ?>
    <link rel="stylesheet" href="assets/progop-display.css?v=<?= filemtime(__DIR__ . '/assets/progop-display.css') ?>">
    <?php endif; ?>
</head>
<body class="<?= $isOperatingRoomDashboard ? 'operating-room-dashboard' : '' ?><?= $dashboardView === 'modern' ? ' modern-operating-display' : '' ?>" data-operating-room-dashboard="<?= $isOperatingRoomDashboard ? '1' : '0' ?>" data-dashboard-view="<?= e($dashboardView) ?>">
    <nav class="overview-rail" aria-label="Navigare platforma">
        <a class="rail-brand" href="<?= $isOperatingRoomDashboard ? 'progop-overview.php' : 'index.php' ?>" aria-label="Pagina principala"><img src="assets/platform-icon.svg" alt=""></a>
        <?php if (!$isOperatingRoomDashboard): ?>
        <a href="index.php" title="Acasa"><span>A</span><b>Acasa</b></a>
        <a href="index.php?module=guards" title="Garzi"><span>G</span><b>Garzi</b></a>
        <?php endif; ?>
        <a class="active" href="progop-overview.php" title="Overview"><span>OP</span><b>Overview</b></a>
        <?php if (!$isOperatingRoomDashboard): ?>
        <a href="progop-legacy.php" title="Programator"><span>+</span><b>Programator</b></a>
        <?php endif; ?>
        <a class="rail-logout" href="index.php?logout=1" title="Iesire"><span>↪</span><b>Iesire</b></a>
    </nav>
    <div class="overview-shell">
        <header class="overview-hero">
            <div>
                <p class="eyebrow">Uz intern · Bloc operator<?= $isOperatingRoomDashboard ? ' · Panou tactil' : '' ?></p>
                <h1><?= $isOperatingRoomDashboard ? 'Programul sălilor' : 'Overview program operații' ?></h1>
                <p class="subtitle"><?= $isOperatingRoomDashboard ? 'Situația operativă pentru ieri, astăzi și mâine.' : 'Monitorizare pe zi și pe săptămână, cu progresul intervențiilor și salvare imediată.' ?></p>
            </div>
            <div class="hero-actions">
                <div id="heroOperationStats" class="hero-operation-stats" aria-live="polite">Se calculează programul...</div>
                <?php if ($isOperatingRoomDashboard): ?>
                <div class="dashboard-view-controls" aria-label="Varianta panoului">
                    <label><span>Aspect</span><select id="dashboardViewSelect"><option value="classic" <?= $dashboardView === 'classic' ? 'selected' : '' ?>>Clasic</option><option value="modern" <?= $dashboardView === 'modern' ? 'selected' : '' ?>>Panou nou</option></select></label>
                    <button id="dashboardFullscreenBtn" class="ghost-btn" type="button">Ecran complet</button>
                </div>
                <?php endif; ?>
                <button id="refreshOverviewBtn" class="secondary-btn">Reîncarcă</button>
                <?php if (!$isOperatingRoomDashboard): ?>
                <button id="openSchedulerBtn" class="ghost-btn">Deschide programatorul</button>
                <?php endif; ?>
            </div>
        </header>

        <section class="toolbar <?= $isOperatingRoomDashboard ? 'kiosk-toolbar' : '' ?>">
            <?php if ($isOperatingRoomDashboard): ?>
            <div class="kiosk-day-navigation" aria-label="Alege ziua afișată">
                <button id="kioskPrevDay" class="segmented-btn">← Ieri</button>
                <button id="kioskToday" class="segmented-btn active">Astăzi</button>
                <button id="kioskNextDay" class="segmented-btn">Mâine →</button>
            </div>
            <button id="modeDayBtn" hidden></button><button id="modeWeekBtn" hidden></button>
            <button id="focusSelectedBtn" hidden></button><button id="focusCurrentBtn" hidden></button>
            <?php else: ?>
            <div class="toolbar-group">
                <span class="toolbar-label">Vizualizare</span>
                <div class="segmented">
                    <button id="modeDayBtn" class="segmented-btn active">Zi</button>
                    <button id="modeWeekBtn" class="segmented-btn">Săptămână</button>
                </div>
            </div>
            <div class="toolbar-group">
                <span class="toolbar-label">Context</span>
                <div class="segmented">
                    <button id="focusSelectedBtn" class="segmented-btn">Data selectată</button>
                    <button id="focusCurrentBtn" class="segmented-btn active">Astăzi</button>
                </div>
            </div>
            <?php endif; ?>
            <div class="toolbar-group toolbar-date">
                <label>
                    <span>Data de referință</span>
                    <input id="referenceDateInput" type="date" <?= $isOperatingRoomDashboard ? 'readonly' : '' ?>>
                    <small id="referenceDateHint" class="date-hint"></small>
                </label>
            </div>
        </section>

        <section class="daily-status-bar" aria-live="polite">
            <span class="daily-status-dot" aria-hidden="true"></span>
            <strong id="dailyStatusLabel">Starea blocului</strong>
            <p id="dailyStatusMessage">Se pregătește programul zilei.</p>
            <div id="dailyEmojiSlider" class="daily-emoji-slider" role="progressbar" aria-label="Progresul operațiilor zilei" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span aria-hidden="true">☕</span>
                <div class="daily-emoji-track">
                    <div id="dailyEmojiFill" class="daily-emoji-fill"></div>
                    <span id="dailyEmojiMarker" class="daily-emoji-marker" aria-hidden="true">🥱</span>
                </div>
                <span aria-hidden="true">🏆</span>
            </div>
        </section>

        <section class="overview-content">
            <div id="overviewDays" class="overview-days"></div>
        </section>
    </div>

    <div id="overviewModalHost"></div>
    <div id="overviewToast" class="overview-toast" role="status" aria-live="polite" hidden></div>

    <script src="assets/progop-overview.js?v=<?= filemtime(__DIR__ . '/assets/progop-overview.js') ?>"></script>
</body>
</html>
