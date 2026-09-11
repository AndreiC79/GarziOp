<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
$db = Database::connect($config['database']);
$user = Auth::user($db);
if (!$user || !in_array('progop.view', Auth::permissions($db, (int) $user['id']), true)) {
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
    <title>Programator Operații Spital</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="assets/progop-legacy.css?v=<?= filemtime(__DIR__ . '/assets/progop-legacy.css') ?>">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div>
                <p class="eyebrow">Spital · Planificare internă</p>
                <h1>Programare operații</h1>
                <p class="subtitle">Calendar lunar, program pe săli, backup și export PDF pentru rețeaua internă.</p>
            </div>
            <div class="topbar-actions">
                <button id="patientSearchBtn" class="secondary-btn">Cauta pacienta</button>
                <button id="scheduleOwnBtn" class="primary-btn">Adaugă programare</button>
                <button id="residentStageBtn" class="secondary-btn" hidden>Stagiul meu</button>
                <button id="unassignedBtn" class="secondary-btn" hidden>De repartizat</button>
                <button id="atiScheduleBtn" class="secondary-btn" hidden>Repartizare ATI</button>
                <button id="reloadBtn" class="secondary-btn">Reîncarcă datele</button>
                <button id="backupBtn" class="secondary-btn">Creează backup</button>
                <button id="overviewBtn" class="secondary-btn">Deschide overview</button>
                <button id="manageTypesBtn" class="ghost-btn">Gestionează tipuri operații</button>
                <button id="importBtn" class="secondary-btn">Importa XLSX</button>
                <button id="exportBtn" class="primary-btn">Export</button>
            </div>
        </header>

        <main class="layout">
            <aside class="sidebar">
                <section class="panel calendar-panel">
                    <div class="panel-header">
                        <h2>Calendar</h2>
                        <div class="calendar-controls">
                            <label>
                                <span>An</span>
                                <select id="yearSelect"></select>
                            </label>
                            <label>
                                <span>Lună</span>
                                <select id="monthSelect"></select>
                            </label>
                        </div>
                    </div>
                    <div class="calendar-legend" aria-label="Explicatii calendar">
                        <span class="calendar-legend-item">
                            <span class="calendar-legend-example"><strong>12</strong><small>10/1</small></span>
                            <span><strong>Ziua</strong><small>operatii / operatiile tale</small></span>
                        </span>
                        <span class="calendar-legend-item">
                            <span class="calendar-legend-guard" aria-hidden="true"></span>
                            <span><strong>Mov</strong><small>esti de garda</small></span>
                        </span>
                        <span class="calendar-legend-item approval-legend">
                            <span class="calendar-legend-guard" aria-hidden="true"></span>
                            <span><strong>Roșu aprins</strong><small>operații de aprobat</small></span>
                        </span>
                    </div>
                    <div class="calendar-weekdays" id="calendarWeekdays"></div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                </section>

                <section class="panel stats-panel">
                    <div class="panel-header">
                        <h2>Statistici zi selectată</h2>
                        <p id="selectedDateLabel"></p>
                    </div>
                    <div class="stats-grid">
                        <article class="stat-card">
                            <span class="stat-label">Total intervenții</span>
                            <strong id="statTotal">0</strong>
                        </article>
                        <article class="stat-card">
                            <span class="stat-label">Pe sală</span>
                            <div id="statRooms" class="tag-list"></div>
                        </article>
                        <article class="stat-card">
                            <span class="stat-label">Pe tip operație</span>
                            <div id="statTypes" class="tag-list"></div>
                        </article>
                        <article class="stat-card">
                            <span class="stat-label">Pe medic</span>
                            <div id="statDoctors" class="tag-list"></div>
                        </article>
                        <article class="stat-card">
                            <span class="stat-label">De garda</span>
                            <div id="statGuards" class="guard-list"></div>
                        </article>
                        <article class="stat-card blocked-stat">
                            <span class="stat-label">Săli blocate</span>
                            <div id="statBlockedRooms" class="list-block"></div>
                        </article>
                    </div>
                </section>
            </aside>

            <section class="content">
                <div class="content-header">
                    <div>
                        <p class="eyebrow">Program zilnic</p>
                        <h2 id="scheduleTitle">Programarea pe săli</h2>
                    </div>
                    <div class="status-area">
                        <span id="saveStatus" class="save-status">Datele sunt sincronizate.</span>
                    </div>
                </div>
                <div id="schedulingQueues"></div>
                <div id="roomsContainer" class="rooms-grid"></div>
            </section>
        </main>
    </div>

    <div id="modalOverlay" class="modal-overlay hidden"></div>
    <div id="modalRoot"></div>

    <template id="roomTemplate">
        <article class="room-card">
            <div class="room-header">
                <div>
                    <h3 class="room-title"></h3>
                    <p class="room-subtitle"></p>
                    <div class="room-ati"></div>
                </div>
                <div class="room-actions">
                    <button class="ghost-btn room-block-btn"></button>
                    <button class="primary-btn add-intervention-btn">Adaugă intervenție</button>
                </div>
            </div>
            <div class="room-alert hidden"></div>
            <div class="interventions-list"></div>
        </article>
    </template>

    <template id="interventionTemplate">
        <article class="intervention-card">
            <div class="intervention-main">
                <div>
                    <h4 class="patient-name"></h4>
                    <p class="intervention-diagnostic"></p>
                </div>
                <span class="intervention-order"></span>
            </div>
            <div class="intervention-meta"></div>
            <p class="intervention-notes"></p>
            <div class="intervention-actions">
                <button class="ghost-btn move-up-btn">Sus</button>
                <button class="ghost-btn move-down-btn">Jos</button>
                <button class="secondary-btn move-btn">Mută / Reprogramează</button>
                <button class="secondary-btn edit-btn">Editează</button>
                <button class="danger-btn delete-btn">Șterge</button>
            </div>
        </article>
    </template>

    <script src="assets/progop-legacy.js?v=<?= filemtime(__DIR__ . '/assets/progop-legacy.js') ?>"></script>
</body>
</html>
