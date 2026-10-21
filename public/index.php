<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PROJECT_ROOT . '/app/version.php';

if (!$config) {
    http_response_code(503);
    renderSetupPage();
    exit;
}

try {
    $db = Database::connect($config['database']);
} catch (Throwable $error) {
    http_response_code(503);
    renderSetupPage('Conexiunea la baza de date nu este disponibila.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    verify_csrf();
    $authenticated = Auth::attempt($db, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($authenticated) {
        header('Location: index.php');
        exit;
    }
    $_SESSION['login_error'] = 'Datele de autentificare nu sunt corecte sau contul este blocat temporar.';
    header('Location: index.php');
    exit;
}

if (isset($_GET['logout'])) {
    Auth::logout($db);
    header('Location: index.php');
    exit;
}

$user = Auth::user($db);
if (!$user) {
    renderLoginPage($config, $_SESSION['login_error'] ?? null);
    unset($_SESSION['login_error']);
    exit;
}

if ((int) $user['must_change_password'] === 1) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
        verify_csrf();
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (strlen($newPassword) < 12) {
            $_SESSION['password_error'] = 'Parola noua trebuie sa aiba minimum 12 caractere.';
        } elseif ($newPassword !== $confirmation) {
            $_SESSION['password_error'] = 'Confirmarea nu corespunde parolei noi.';
        } elseif ($newPassword === $currentPassword) {
            $_SESSION['password_error'] = 'Parola noua trebuie sa fie diferita de parola temporara.';
        } elseif (!Auth::changePassword($db, (int) $user['id'], $currentPassword, $newPassword)) {
            $_SESSION['password_error'] = 'Parola temporara introdusa nu este corecta.';
        } else {
            header('Location: index.php');
            exit;
        }

        header('Location: index.php');
        exit;
    }

    renderPasswordChangePage($config, $user, $_SESSION['password_error'] ?? null);
    unset($_SESSION['password_error']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password_self') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $returnModule = (string) ($_POST['return_module'] ?? 'dashboard');
    if (!in_array($returnModule, ['dashboard', 'guards', 'progop', 'admin'], true)) $returnModule = 'dashboard';

    if (strlen($newPassword) < 12) {
        $_SESSION['account_password_error'] = 'Parola noua trebuie sa aiba minimum 12 caractere.';
    } elseif ($newPassword !== $confirmation) {
        $_SESSION['account_password_error'] = 'Confirmarea nu corespunde parolei noi.';
    } elseif ($newPassword === $currentPassword) {
        $_SESSION['account_password_error'] = 'Parola noua trebuie sa fie diferita de parola actuala.';
    } elseif (!Auth::changePassword($db, (int) $user['id'], $currentPassword, $newPassword)) {
        $_SESSION['account_password_error'] = 'Parola actuala nu este corecta.';
    } else {
        $_SESSION['account_password_success'] = 'Parola a fost schimbata cu succes.';
    }
    header('Location: index.php?module=' . rawurlencode($returnModule));
    exit;
}

$permissions = Auth::permissions($db, (int) $user['id']);
$isOperatingRoomDashboard = Auth::hasRole($db, (int) $user['id'], 'operating_room_dashboard');
if ($isOperatingRoomDashboard) {
    $dashboardView = (string)($_COOKIE['intern_dashboard_view'] ?? 'modern');
    if (!in_array($dashboardView, ['classic', 'modern'], true)) $dashboardView = 'modern';
    header('Location: progop-overview.php?view=' . rawurlencode($dashboardView));
    exit;
}
$canAccessAdmin = userHasPlatformAdminRole($db, (int) $user['id']);
$module = (string) ($_GET['module'] ?? 'dashboard');
$allowedModules = ['dashboard', 'guards', 'progop', 'admin', 'help'];
if (!in_array($module, $allowedModules, true)) {
    $module = 'dashboard';
}
if ($module === 'admin' && !$canAccessAdmin) {
    header('Location: index.php?module=dashboard');
    exit;
}

$summary = loadSummary($db, $user, $permissions);
renderApplication($config, $user, $permissions, $module, $summary, $db, $canAccessAdmin);

function userHasPlatformAdminRole(PDO $db, int $userId): bool
{
    if (isset($_SESSION['local_lab_role'])) {
        return in_array((string)$_SESSION['local_lab_role'], ['platform_admin','super_admin'], true);
    }
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM user_roles ur
         JOIN roles r ON r.id=ur.role_id
         WHERE ur.user_id=:user_id AND r.code IN ("platform_admin","super_admin")'
    );
    $statement->execute(['user_id' => $userId]);
    return (int) $statement->fetchColumn() > 0;
}

function loadSummary(PDO $db, array $user, array $permissions): array
{
    $summary = ['unread' => 0, 'my_guards' => 0, 'future_guards' => [], 'remaining_month_guards' => [], 'next_month_guard' => null, 'today_guards' => ['OG'=>[], 'ATI'=>[], 'NN'=>[]], 'today_operations' => 0, 'today_completed' => 0, 'yesterday_operations' => 0, 'yesterday_completed' => 0, 'my_today_operations' => 0, 'is_resident' => false, 'notifications' => [], 'validation' => loadValidationCenter($db, $user, $permissions)];
    if (!empty($user['person_id'])) {
        $resident=$db->prepare('SELECT professional_category FROM people WHERE id=:id');$resident->execute(['id'=>$user['person_id']]);
        $summary['is_resident']=str_contains(mb_strtolower((string)$resident->fetchColumn()),'rezident');
    }

    $statement = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
    $statement->execute(['user_id' => $user['id']]);
    $summary['unread'] = (int) $statement->fetchColumn();

    $todayGuards = $db->query(
        'SELECT ga.specialty,ga.line_no,p.display_name
         FROM guard_assignments ga
         JOIN guard_plan_versions gv ON gv.id=ga.version_id
         JOIN guard_plans gp ON gp.current_version_id=gv.id
         JOIN people p ON p.id=ga.current_person_id
         WHERE ga.guard_date=CURRENT_DATE()
         ORDER BY FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no'
    )->fetchAll();
    foreach ($todayGuards as $guard) {
        $summary['today_guards'][(string)$guard['specialty']][] = (string)$guard['display_name'];
    }

    if (!empty($user['display_name'])) {
        $statement = $db->prepare(
            'SELECT COUNT(*)
             FROM guard_assignments ga
             INNER JOIN guard_plan_versions gv ON gv.id = ga.version_id
             INNER JOIN guard_plans gp ON gp.current_version_id = gv.id
             INNER JOIN users u ON u.person_id = ga.current_person_id
             WHERE u.id = :user_id AND ga.guard_date >= CURRENT_DATE()'
        );
        $statement->execute(['user_id' => $user['id']]);
        $summary['my_guards'] = (int) $statement->fetchColumn();

        $statement = $db->prepare(
            'SELECT ga.guard_date,ga.specialty,ga.line_no,ga.assignment_state
             FROM guard_assignments ga
             INNER JOIN guard_plan_versions gv ON gv.id=ga.version_id
             INNER JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.current_person_id=:person_id AND ga.guard_date>=CURRENT_DATE()
             ORDER BY ga.guard_date,FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no LIMIT 50'
        );
        $statement->execute(['person_id' => $user['person_id']]);
        $summary['future_guards'] = $statement->fetchAll();

        $statement = $db->prepare(
            'SELECT ga.guard_date,ga.specialty,ga.line_no FROM guard_assignments ga
             INNER JOIN guard_plan_versions gv ON gv.id=ga.version_id
             INNER JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.current_person_id=:person_id AND ga.guard_date BETWEEN CURRENT_DATE() AND LAST_DAY(CURRENT_DATE())
             ORDER BY ga.guard_date,FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no'
        );
        $statement->execute(['person_id'=>$user['person_id']]);
        $summary['remaining_month_guards'] = $statement->fetchAll();

        $statement = $db->prepare(
            'SELECT ga.guard_date,ga.specialty,ga.line_no FROM guard_assignments ga
             INNER JOIN guard_plan_versions gv ON gv.id=ga.version_id
             INNER JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.current_person_id=:person_id
               AND ga.guard_date>=DATE_FORMAT(CURRENT_DATE()+INTERVAL 1 MONTH,"%Y-%m-01")
               AND ga.guard_date<LAST_DAY(CURRENT_DATE()+INTERVAL 1 MONTH)+INTERVAL 1 DAY
             ORDER BY ga.guard_date,FIELD(ga.specialty,"OG","ATI","NN"),ga.line_no LIMIT 1'
        );
        $statement->execute(['person_id'=>$user['person_id']]);
        $summary['next_month_guard'] = $statement->fetch() ?: null;
    }

    $summary['today_operations'] = (int) $db->query(
        "SELECT COUNT(*) FROM operations WHERE scheduled_date = CURRENT_DATE() AND status IN ('scheduled', 'completed')"
    )->fetchColumn();
    $operationTotals=$db->query('SELECT SUM(scheduled_date=CURRENT_DATE() AND status IN ("scheduled","completed")) today_total,SUM(scheduled_date=CURRENT_DATE() AND status="completed") today_completed,SUM(scheduled_date=CURRENT_DATE()-INTERVAL 1 DAY AND status IN ("scheduled","completed")) yesterday_total,SUM(scheduled_date=CURRENT_DATE()-INTERVAL 1 DAY AND status="completed") yesterday_completed FROM operations WHERE scheduled_date BETWEEN CURRENT_DATE()-INTERVAL 1 DAY AND CURRENT_DATE()')->fetch();
    $summary['today_operations']=(int)($operationTotals['today_total']??0);$summary['today_completed']=(int)($operationTotals['today_completed']??0);$summary['yesterday_operations']=(int)($operationTotals['yesterday_total']??0);$summary['yesterday_completed']=(int)($operationTotals['yesterday_completed']??0);

    if (!empty($user['person_id'])) {
        $statement = $db->prepare(
            "SELECT COUNT(*) FROM operations
             WHERE scheduled_date=CURRENT_DATE() AND scheduled_doctor_id=:person_id
               AND status IN ('scheduled', 'completed')"
        );
        $statement->execute(['person_id' => $user['person_id']]);
        $summary['my_today_operations'] = (int) $statement->fetchColumn();
    }

    $statement = $db->prepare(
        'SELECT n.id,n.notification_type,n.title,n.message,n.entity_type,n.entity_id,n.read_at,n.created_at,
                o.scheduled_date,o.patient_name,o.diagnostic,ot.name operation_type,p.display_name doctor_name,
                CASE WHEN n.notification_type="extra_approval_request" AND LEFT(COALESCE(o.extra_reason,""),17)="[[EXTRA_PENDING]]" AND o.status<>"deleted" THEN 1 ELSE 0 END can_review
         FROM notifications n LEFT JOIN operations o ON n.entity_type="operation" AND o.id=n.entity_id
         LEFT JOIN operation_types ot ON ot.id=o.operation_type_id LEFT JOIN people p ON p.id=o.scheduled_doctor_id
         WHERE n.user_id=:user_id ORDER BY n.created_at DESC,n.id DESC LIMIT 30'
    );
    $statement->execute(['user_id' => $user['id']]);
    $summary['notifications'] = $statement->fetchAll();
    $dayContext = $db->prepare('SELECT COALESCE(ot.name,"Tip nespecificat") operation_type,COUNT(*) total FROM operations o LEFT JOIN operation_types ot ON ot.id=o.operation_type_id WHERE o.scheduled_date=:date AND o.status<>"deleted" GROUP BY COALESCE(ot.name,"Tip nespecificat") ORDER BY total DESC,operation_type');
    foreach ($summary['notifications'] as &$notification) {
        if (empty($notification['can_review']) || empty($notification['scheduled_date'])) continue;
        $dayContext->execute(['date'=>$notification['scheduled_date']]);
        $notification['day_breakdown'] = $dayContext->fetchAll();
        $notification['day_total'] = array_sum(array_map(static fn(array $row): int => (int)$row['total'], $notification['day_breakdown']));
    }
    unset($notification);

    return $summary;
}

function loadValidationCenter(PDO $db, array $user, array $permissions): array
{
    $rolesStatement = $db->prepare('SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=:user_id');
    $rolesStatement->execute(['user_id'=>(int)$user['id']]);
    $roles = $rolesStatement->fetchAll(PDO::FETCH_COLUMN);
    if (isset($_SESSION['local_lab_role'])) $roles = [(string)$_SESSION['local_lab_role']];
    $fullAccess = in_array('super_admin', $roles, true);
    if (!$fullAccess) return ['visible'=>false, 'full'=>false, 'issues'=>[], 'total'=>0];
    $canWorkGuards = (bool) array_filter($permissions, static fn(string $permission): bool => str_starts_with($permission, 'guards.') && $permission !== 'guards.view');
    $canWorkProgOp = (bool) array_intersect(['progop.create', 'progop.edit_own', 'progop.manage_all'], $permissions);
    $personId = empty($user['person_id']) ? 0 : (int)$user['person_id'];
    $canSeeOperationalOverview = $fullAccess || in_array('doctor_admin', $roles, true) || in_array('progop.manage_all', $permissions, true);
    $issues = [];

    if ($canWorkGuards && ($personId > 0 || $canSeeOperationalOverview)) {
        $personFilter = $canSeeOperationalOverview ? '' : ' AND a.current_person_id=:person_id';
        $statement = $db->prepare(
            'SELECT a.guard_date first_date,b.guard_date second_date,a.specialty first_specialty,b.specialty second_specialty,p.display_name
             FROM guard_assignments a
             JOIN guard_plan_versions av ON av.id=a.version_id JOIN guard_plans ap ON ap.current_version_id=av.id
             JOIN guard_assignments b ON b.current_person_id=a.current_person_id AND b.guard_date=DATE_ADD(a.guard_date,INTERVAL 1 DAY)
             JOIN guard_plan_versions bv ON bv.id=b.version_id JOIN guard_plans bp ON bp.current_version_id=bv.id
             JOIN people p ON p.id=a.current_person_id
             WHERE a.guard_date>=CURRENT_DATE()'.$personFilter.'
             ORDER BY a.guard_date LIMIT 8'
        );
        $statement->execute($canSeeOperationalOverview ? [] : ['person_id'=>$personId]);
        $rows = $statement->fetchAll();
        if ($rows) $issues[] = ['severity'=>'danger','title'=>'Garzi consecutive','count'=>count($rows),'detail'=>($canSeeOperationalOverview ? $rows[0]['display_name'].': ' : 'Prima situatie: ').date('d.m.Y', strtotime((string)$rows[0]['first_date'])).' si '.date('d.m.Y', strtotime((string)$rows[0]['second_date'])).'.','href'=>'?module=guards'];
    }

    if ($canWorkProgOp && $personId > 0 && !$canSeeOperationalOverview) {
        $statement = $db->prepare('SELECT COUNT(*) total,MIN(scheduled_date) first_date FROM operations WHERE scheduled_doctor_id=:person_id AND scheduled_date<CURRENT_DATE() AND status="scheduled"');
        $statement->execute(['person_id'=>$personId]);
        $row = $statement->fetch();
        if ((int)$row['total'] > 0) $issues[] = ['severity'=>'warning','title'=>'Operatii proprii nebifate','count'=>(int)$row['total'],'detail'=>'Cea mai veche este din '.date('d.m.Y', strtotime((string)$row['first_date'])).'.','href'=>'?module=progop'];
    }

    if ($canSeeOperationalOverview) {
        $settings = $db->query('SELECT specialty,line_count FROM guard_specialty_settings')->fetchAll();
        $assignedRows = $db->query(
            'SELECT ga.guard_date,ga.specialty,COUNT(DISTINCT ga.line_no) assigned
             FROM guard_assignments ga JOIN guard_plan_versions gv ON gv.id=ga.version_id JOIN guard_plans gp ON gp.current_version_id=gv.id
             WHERE ga.guard_date BETWEEN CURRENT_DATE() AND LAST_DAY(CURRENT_DATE()) GROUP BY ga.guard_date,ga.specialty'
        )->fetchAll();
        $assigned = [];
        foreach ($assignedRows as $row) $assigned[$row['guard_date'].':'.$row['specialty']] = (int)$row['assigned'];
        $missing = 0; $firstMissing = null;
        for ($date = new DateTimeImmutable('today'), $end = new DateTimeImmutable('last day of this month'); $date <= $end; $date = $date->modify('+1 day')) {
            foreach ($settings as $setting) {
                $needed = max(0, (int)$setting['line_count'] - ($assigned[$date->format('Y-m-d').':'.$setting['specialty']] ?? 0));
                if ($needed > 0 && $firstMissing === null) $firstMissing = $date->format('Y-m-d').' '.$setting['specialty'];
                $missing += $needed;
            }
        }
        if ($missing > 0) $issues[] = ['severity'=>'danger','title'=>'Linii de garda neacoperite','count'=>$missing,'detail'=>'Prima lipsa: '.date('d.m.Y', strtotime(substr((string)$firstMissing, 0, 10))).' · '.substr((string)$firstMissing, 11).'.','href'=>'?module=guards'];

        $pendingExtra = $db->query('SELECT COUNT(*) total,MIN(scheduled_date) first_date FROM operations WHERE status<>"deleted" AND is_extra=1 AND extra_reason LIKE "[[EXTRA_PENDING]]%"')->fetch();
        if ((int)$pendingExtra['total'] > 0) $issues[] = ['severity'=>'danger','title'=>'Operatii EXTRA in asteptare','count'=>(int)$pendingExtra['total'],'detail'=>'Prima este programata pentru '.date('d.m.Y', strtotime((string)$pendingExtra['first_date'])).'.','href'=>'?module=progop'];

        $pendingRooms = $db->query('SELECT COUNT(*) total,MIN(scheduled_date) first_date FROM operations WHERE status="scheduled" AND is_extra=0 AND display_order=0 AND scheduled_date>=CURRENT_DATE()')->fetch();
        if ((int)$pendingRooms['total'] > 0) $issues[] = ['severity'=>'warning','title'=>'Programari nerepartizate','count'=>(int)$pendingRooms['total'],'detail'=>'Prima data care necesita repartizare: '.date('d.m.Y', strtotime((string)$pendingRooms['first_date'])).'.','href'=>'?module=progop'];

        $pastOperations = $db->query('SELECT COUNT(*) total,MIN(scheduled_date) first_date FROM operations WHERE scheduled_date<CURRENT_DATE() AND status="scheduled"')->fetch();
        if ((int)$pastOperations['total'] > 0) $issues[] = ['severity'=>'warning','title'=>'Operatii trecute nebifate','count'=>(int)$pastOperations['total'],'detail'=>'Verifica operatiile incepand cu '.date('d.m.Y', strtotime((string)$pastOperations['first_date'])).'.','href'=>'?module=progop'];
    }

    return ['visible'=>true, 'full'=>$fullAccess, 'issues'=>$issues, 'total'=>array_sum(array_column($issues, 'count'))];
}

function renderSetupPage(?string $error = null): void
{
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#123f48">
        <link rel="icon" href="assets/platform-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="assets/platform-icon.svg">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="application-version" content="<?= e(appVersion()) ?>">
        <title>Configurare platforma</title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body class="setup-page">
        <main class="setup-card">
            <span class="eyebrow">Configurare initiala</span>
            <h1>Platforma este pregatita pentru conectare</h1>
            <p><?= e($error ?: 'Mai trebuie configurata baza de date inainte de prima autentificare.') ?></p>
            <ol>
                <li>Importa fisierul <code>database/schema.sql</code>.</li>
                <li>Copiaza <code>config/config.example.php</code> ca <code>config/config.php</code>.</li>
                <li>Completeaza datele de conectare primite de la administratorul serverului.</li>
                <li>Creeaza primul cont folosind scriptul din <code>database/create_admin.php</code>.</li>
            </ol>
            <small class="login-version">Versiunea <?= e(appVersion()) ?></small>
        </main>
    </body>
    </html>
    <?php
}

function renderLoginPage(array $config, ?string $error): void
{
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#123f48">
        <link rel="icon" href="assets/platform-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="assets/platform-icon.svg">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="application-version" content="<?= e(appVersion()) ?>">
        <title>Autentificare | <?= e($config['app']['name']) ?></title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body class="login-page">
        <main class="login-layout">
            <section class="login-story">
                <img class="login-brand-logo" src="assets/platform-logo.svg" alt="Maternitatea Giulesti">
                <div>
                    <span class="eyebrow">Uz intern</span>
                    <h1>Garda si blocul operator, in acelasi loc.</h1>
                    <p>O vedere generala pentru echipele OG, ATI si NN, cu istoric clar si acces adaptat fiecarui rol.</p>
                </div>
            </section>
            <section class="login-panel">
                <div class="login-panel-inner">
                    <span class="eyebrow">Acces securizat</span>
                    <h2>Autentificare</h2>
                    <p>Foloseste contul individual primit de la administrator.</p>
                    <?php if ($error): ?>
                        <div class="alert" role="alert"><?= e($error) ?></div>
                    <?php endif; ?>
                    <form method="post" class="login-form">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label>Utilizator<input name="username" autocomplete="username" required autofocus></label>
                        <label>Parola<input type="password" name="password" autocomplete="current-password" required></label>
                        <button type="submit">Intra in platforma</button>
                    </form>
                    <small class="login-version">Versiunea <?= e(appVersion()) ?> · <?= e((string) appRelease()['release']) ?></small>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
}

function renderPasswordChangePage(array $config, array $user, ?string $error): void
{
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#123f48">
        <link rel="icon" href="assets/platform-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="assets/platform-icon.svg">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="application-version" content="<?= e(appVersion()) ?>">
        <title>Schimbare parola | <?= e($config['app']['name']) ?></title>
        <link rel="stylesheet" href="assets/app.css">
    </head>
    <body class="login-page">
        <main class="login-layout">
            <section class="login-story">
                <img class="login-brand-logo" src="assets/platform-logo.svg" alt="Maternitatea Giulesti">
                <div>
                    <span class="eyebrow">Prima autentificare</span>
                    <h1>Alege o parola numai a ta.</h1>
                    <p>Parola temporara nu va mai putea fi folosita dupa confirmarea acestei schimbari.</p>
                </div>
            </section>
            <section class="login-panel">
                <div class="login-panel-inner">
                    <span class="eyebrow">Cont <?= e($user['username']) ?></span>
                    <h2>Schimba parola</h2>
                    <p>Foloseste minimum 12 caractere si evita parolele utilizate in alte servicii.</p>
                    <?php if ($error): ?>
                        <div class="alert" role="alert"><?= e($error) ?></div>
                    <?php endif; ?>
                    <form method="post" class="login-form">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <label>Parola temporara<input type="password" name="current_password" autocomplete="current-password" required autofocus></label>
                        <label>Parola noua<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
                        <label>Confirma parola<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                        <button type="submit">Salveaza parola noua</button>
                    </form>
                    <small class="login-version">Versiunea <?= e(appVersion()) ?> · <?= e((string) appRelease()['release']) ?></small>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
}

function renderApplication(array $config, array $user, array $permissions, string $module, array $summary, PDO $db, bool $canAccessAdmin): void
{
    $canSeeGuards = in_array('guards.view', $permissions, true);
    $canSeeProgOp = in_array('progop.view', $permissions, true);
    $canAdmin = $canAccessAdmin;
    $canReviewExtra = in_array(strtolower((string)$user['username']), ['cucu.andrei', 'traian.maier'], true);
    $initialGuardMonth = date('Y-m');
    $initialProgOpMonth = date('Y-m');
    $passwordError = $_SESSION['account_password_error'] ?? null;
    $passwordSuccess = $_SESSION['account_password_success'] ?? null;
    $quoteCollection = require PROJECT_ROOT . '/app/dashboard_quotes.php';
    $quotes = $quoteCollection['quotes'];
    $quoteIndex = (int) ((int) sprintf('%u', crc32(date('Y-m-d') . ':' . $user['id'])) % count($quotes));
    $dashboardQuote = $quotes[$quoteIndex];
    unset($_SESSION['account_password_error'], $_SESSION['account_password_success']);
    ?>
    <!doctype html>
    <html lang="ro">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#123f48">
        <link rel="icon" href="assets/platform-icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="assets/platform-icon.svg">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
        <meta name="application-version" content="<?= e(appVersion()) ?>">
        <title><?= e($config['app']['name']) ?></title>
        <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
        <?php if (in_array($module, ['guards', 'progop', 'admin', 'help'], true)): ?><link rel="stylesheet" href="assets/modules.css?v=<?= filemtime(__DIR__ . '/assets/modules.css') ?>"><?php endif; ?>
        <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
        <?php if ($module === 'guards'): ?><script src="assets/guards.js?v=<?= filemtime(__DIR__ . '/assets/guards.js') ?>" defer></script><?php endif; ?>
        <?php if ($module === 'progop'): ?><script src="assets/progop.js?v=<?= filemtime(__DIR__ . '/assets/progop.js') ?>" defer></script><?php endif; ?>
        <?php if ($module === 'admin'): ?><script src="assets/admin.js?v=<?= filemtime(__DIR__ . '/assets/admin.js') ?>" defer></script><?php endif; ?>
    </head>
    <body class="app-page">
        <div class="app-shell" id="appShell">
            <aside class="sidebar">
                <div class="sidebar-head">
                    <a class="brand" href="?module=dashboard"><img class="brand-mark-image" src="assets/platform-icon.svg" alt=""><span class="brand-copy">Intern<br><small>Maternitatea Giulesti</small></span></a>
                    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Restrange meniul" aria-expanded="true"><span>‹</span></button>
                </div>
                <nav class="main-nav" aria-label="Navigare principala">
                    <?= navLink('dashboard', 'Vedere generala', $module, 'grid') ?>
                    <?php if ($canSeeGuards): ?><?= navLink('guards', 'Garzi', $module, 'moon') ?><?php endif; ?>
                    <?php if ($canSeeProgOp): ?><?= navLink('progop', 'Program operator', $module, 'cross') ?><?php endif; ?>
                    <?= navLink('help', 'Ajutor', $module, 'help') ?>
                    <?php if ($canAdmin): ?><?= navLink('admin', 'Administrare', $module, 'settings') ?><?php endif; ?>
                </nav>
                <div class="sidebar-version" title="<?= e((string) appRelease()['summary']) ?>"><span>Versiune instalata</span><strong><?= e(appVersion()) ?></strong></div>
                <div class="sidebar-user">
                    <span class="avatar"><?= e(initials($user['display_name'] ?: $user['username'])) ?></span>
                    <div class="sidebar-user-copy"><strong><?= e($user['display_name'] ?: $user['username']) ?></strong><small><?= e($user['specialty'] ?: 'Administrator') ?></small></div>
                    <a class="sidebar-logout" href="?logout=1" aria-label="Iesire">Iesire</a>
                </div>
            </aside>

            <main class="workspace">
                <header class="topbar">
                    <div><span class="eyebrow">Platforma interna · v<?= e(appVersion()) ?></span><h1><?= e(moduleTitle($module)) ?></h1></div>
                    <div class="topbar-actions"><button class="account-password-button" data-open-password type="button">Schimba parola</button><button class="notification-button" id="notificationToggle" type="button" aria-label="Notificari" aria-expanded="false"><span>Notificari</span><b id="notificationBadge"><?= $summary['unread'] ?></b></button></div>
                </header>

                <?php if ($passwordSuccess): ?><div class="account-password-flash success" role="status"><?= e($passwordSuccess) ?></div><?php endif; ?>

                <aside class="account-password-drawer<?= $passwordError ? ' open' : '' ?>" id="accountPasswordDrawer" aria-hidden="<?= $passwordError ? 'false' : 'true' ?>">
                    <button type="button" class="account-password-backdrop" data-close-password aria-label="Inchide schimbarea parolei"></button>
                    <section class="account-password-panel" role="dialog" aria-modal="true" aria-labelledby="accountPasswordTitle">
                        <header><div><span class="eyebrow">Cont <?= e($user['username']) ?></span><h2 id="accountPasswordTitle">Schimba parola</h2></div><button type="button" class="drawer-close" data-close-password aria-label="Inchide">x</button></header>
                        <p>Foloseste minimum 12 caractere si o parola pe care nu o utilizezi in alte servicii.</p>
                        <?php if ($passwordError): ?><div class="alert" role="alert"><?= e($passwordError) ?></div><?php endif; ?>
                        <form method="post" class="account-password-form">
                            <input type="hidden" name="action" value="change_password_self">
                            <input type="hidden" name="return_module" value="<?= e($module) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <label>Parola actuala<input type="password" name="current_password" autocomplete="current-password" required></label>
                            <label>Parola noua<input type="password" name="new_password" autocomplete="new-password" minlength="12" required></label>
                            <label>Confirma parola noua<input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                            <button type="submit" class="primary-button">Salveaza parola noua</button>
                        </form>
                    </section>
                </aside>

                <aside class="notification-drawer" id="notificationDrawer" aria-hidden="true">
                    <button type="button" class="notification-backdrop" data-close-notifications aria-label="Inchide notificarile"></button>
                    <section class="notification-panel" role="dialog" aria-modal="true" aria-labelledby="notificationTitle">
                        <header><div><span class="eyebrow">Activitate personala</span><h2 id="notificationTitle">Notificari</h2></div><button type="button" class="drawer-close" data-close-notifications aria-label="Inchide">x</button></header>
                        <div class="notification-list" id="notificationList">
                            <?php if (!$summary['notifications']): ?>
                                <div class="notification-empty">Nu ai notificari.</div>
                            <?php else: foreach ($summary['notifications'] as $notification): ?>
                                <article class="notification-item<?= $notification['read_at'] ? '' : ' unread' ?>" data-notification-id="<?= (int) $notification['id'] ?>" data-actionable="<?= !empty($notification['can_review']) ? '1' : '0' ?>">
                                    <i></i><div><strong><?= e($notification['title']) ?></strong><p><?= e($notification['message']) ?></p><time><?= e(date('d.m.Y H:i', strtotime((string) $notification['created_at']))) ?></time>
                                    <?php if ($canReviewExtra && !empty($notification['can_review'])): ?>
                                        <div class="approval-context"><b><?= e($notification['operation_type'] ?: 'Tip nespecificat') ?> · <?= e($notification['doctor_name'] ?: 'Medic nespecificat') ?></b><span>Diagnostic: <?= e($notification['diagnostic'] ?: 'nespecificat') ?></span><span>În ziua respectivă: <?= (int)$notification['day_total'] ?> operații · <?= e(implode(', ', array_map(static fn(array $row): string => $row['operation_type'].' '.$row['total'], $notification['day_breakdown']))) ?></span><a href="?module=progop&date=<?= e($notification['scheduled_date']) ?>">Deschide programul din <?= e(date('d.m.Y', strtotime((string)$notification['scheduled_date']))) ?></a></div>
                                        <div class="notification-actions" data-extra-review="<?= (int)$notification['entity_id'] ?>"><button type="button" data-extra-approved="1">Aprobă</button><button type="button" class="reject" data-extra-approved="0">Nu aprobă</button></div>
                                    <?php endif; ?></div><button type="button" class="notification-delete" data-delete-notification aria-label="Șterge notificarea" title="Șterge notificarea"<?= (!$notification['read_at'] || !empty($notification['can_review'])) ? ' hidden' : '' ?>>×</button>
                                </article>
                            <?php endforeach; endif; ?>
                        </div>
                        <?php $hasDeletableReadNotifications = count(array_filter($summary['notifications'], static fn(array $item): bool => !empty($item['read_at']) && empty($item['can_review']))) > 0; ?>
                        <footer><button type="button" class="ghost-button" id="markNotificationsRead"<?= $summary['unread'] ? '' : ' disabled' ?>>Marchează toate ca citite</button><button type="button" class="notification-clear-button" id="deleteReadNotifications"<?= $hasDeletableReadNotifications ? '' : ' disabled' ?>>Șterge toate notificările citite</button></footer>
                    </section>
                </aside>

                <?php if ($module === 'dashboard'): ?>
                    <section class="welcome-card">
                        <div>
                            <span class="eyebrow">Astazi, <?= e(date('d.m.Y')) ?></span>
                            <h2>Buna, <?= e(familiarName($user)) ?></h2>
                            <p class="dashboard-quote-line"><span class="dashboard-quote">„<?= e($dashboardQuote) ?>”</span><span class="dashboard-quote-source">— proverb românesc</span></p>
                            <p class="today-guards"><strong>Azi sunt de garda:</strong><?php foreach (['OG','ATI','NN'] as $guardSpecialty): ?><span><b><?= e($guardSpecialty) ?></b> <?= e($summary['today_guards'][$guardSpecialty] ? implode(', ', $summary['today_guards'][$guardSpecialty]) : 'Lipsa') ?></span><?php endforeach; ?></p>
                        </div>
                        <div class="pulse-mark"><span></span></div>
                    </section>
                    <section class="metric-grid">
                        <button type="button" class="metric-card metric-action warm" id="futureGuardsToggle" aria-expanded="false" aria-controls="futureGuardsList"><span>Urmatoarea garda</span><strong><?= $summary['future_guards'] ? e(date('d.m.Y', strtotime((string)$summary['future_guards'][0]['guard_date']))) : '—' ?></strong><small><?= $summary['future_guards'] ? e(romanianWeekday((string)$summary['future_guards'][0]['guard_date'])) . ' · ' : '' ?>mai ai <?= count($summary['remaining_month_guards']) ?> <?= count($summary['remaining_month_guards']) === 1 ? 'garda' : 'garzi' ?> luna aceasta · apasa pentru detalii</small></button>
                        <a class="metric-card metric-action cool" href="?module=progop"><span>Operatii astazi</span><strong><?= $summary['today_operations'] ?>/<?= $summary['today_completed'] ?></strong><small>programate / efectuate · <?= $summary['my_today_operations'] ?> ale tale</small></a>
                        <a class="metric-card metric-action cool" href="?module=progop"><span>Operatii ieri</span><strong><?= $summary['yesterday_operations'] ?>/<?= $summary['yesterday_completed'] ?></strong><small>programate / efectuate</small></a>
                        <button type="button" class="metric-card metric-action light" data-open-notifications><span>Notificari noi</span><strong><?= $summary['unread'] ?></strong><small>Apasa pentru activitatea ta</small></button>
                        <a class="metric-card metric-action help" href="?module=help"><span>Ajutor</span><strong>?</strong><small>Instructiuni pas cu pas</small></a>
                        <?php if ($summary['is_resident']): ?><a class="metric-card metric-action warm" href="?module=progop&openStage=1"><span>Stagiul meu</span><strong>+</strong><small>Completeaza sau modifica perioada</small></a><?php endif; ?>
                    </section>
                    <?php if ($summary['validation']['visible']): ?>
                        <details class="validation-center<?= $summary['validation']['full'] ? ' full' : ' contextual' ?>">
                            <summary><div><span class="eyebrow">Control operational complet</span><h3>Centru de validare</h3><p>Probleme calculate in timp real din garzi si Program operator. Apasa pentru detalii.</p></div><b><?= (int)$summary['validation']['total'] ?></b></summary>
                            <?php if (!$summary['validation']['issues']): ?><div class="validation-clear"><strong>Totul este in regula.</strong><span>Nu exista situatii care necesita atentia ta acum.</span></div><?php else: ?><div class="validation-list"><?php foreach ($summary['validation']['issues'] as $issue): ?><a class="validation-item <?= e($issue['severity']) ?>" href="<?= e($issue['href']) ?>"><span><strong><?= e($issue['title']) ?></strong><small><?= e($issue['detail']) ?></small></span><b><?= (int)$issue['count'] ?></b></a><?php endforeach; ?></div><?php endif; ?>
                        </details>
                    <?php endif; ?>
                    <section class="future-guards-panel" id="futureGuardsList" hidden>
                        <header><div><span class="eyebrow">Program publicat</span><h3>Garzile tale viitoare</h3></div><b><?= $summary['my_guards'] ?></b></header>
                        <div class="guard-month-summary"><p>Mai ai <strong><?= count($summary['remaining_month_guards']) ?> <?= count($summary['remaining_month_guards']) === 1 ? 'garda' : 'garzi' ?></strong> de efectuat luna aceasta<?= $summary['remaining_month_guards'] ? ' in ' . e(implode(', ', array_map(static fn(array $guard): string => date('d.m', strtotime((string)$guard['guard_date'])) . ' (' . $guard['specialty'] . ' L' . $guard['line_no'] . ')', $summary['remaining_month_guards']))) : '' ?>.</p><p>Prima garda din luna urmatoare: <strong><?= $summary['next_month_guard'] ? e(date('d.m.Y', strtotime((string)$summary['next_month_guard']['guard_date'])) . ' · ' . $summary['next_month_guard']['specialty'] . ' L' . $summary['next_month_guard']['line_no']) : 'nu este programata' ?></strong>.</p></div>
                        <?php if (!$summary['future_guards']): ?>
                            <p class="future-guards-empty">Nu ai garzi viitoare programate.</p>
                        <?php else: ?>
                            <div class="future-guards-list">
                                <?php foreach ($summary['future_guards'] as $guard): ?>
                                    <a href="?module=guards" class="future-guard-row"><time><?= e(date('d.m.Y', strtotime((string)$guard['guard_date']))) ?></time><strong><?= e($guard['specialty']) ?> · L<?= (int)$guard['line_no'] ?></strong><span><?= e(match ((string)$guard['assignment_state']) { 'exchanged' => 'Schimbata', 'transferred' => 'Preluata', 'admin_corrected', 'manual_corrected' => 'Corectata', default => 'Initiala' }) ?></span></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                    <section class="module-grid">
                        <?php if ($canSeeGuards): ?><a class="module-card" href="?module=guards"><span class="module-index">01</span><div><h3>Garzi</h3><p>Calendar OG, ATI si NN, schimburi si istoricul propriu.</p></div><b>Deschide</b></a><?php endif; ?>
                        <?php if ($canSeeProgOp): ?><a class="module-card" href="?module=progop"><span class="module-index">02</span><div><h3>Program operator</h3><p>Interventii pe zile si sali, cu zilele tale de garda evidentiate.</p></div><b>Deschide</b></a><?php endif; ?>
                        <?php if ($canAdmin): ?><a class="module-card" href="?module=admin"><span class="module-index">03</span><div><h3>Administrare</h3><p>Utilizatori, drepturi, generare, rapoarte si audit.</p></div><b>Deschide</b></a><?php endif; ?>
                    </section>
                <?php elseif ($module === 'guards'): ?>
                    <?php require PROJECT_ROOT . '/app/views/guards.php'; ?>
                <?php elseif ($module === 'progop'): ?>
                    <?php require PROJECT_ROOT . '/app/views/progop.php'; ?>
                <?php elseif ($module === 'admin'): ?>
                    <?php require PROJECT_ROOT . '/app/views/admin.php'; ?>
                <?php elseif ($module === 'help'): ?>
                    <?php require PROJECT_ROOT . '/app/views/help.php'; ?>
                <?php else: ?>
                    <section class="module-placeholder">
                        <span class="eyebrow">Modul pregatit</span>
                        <h2><?= e(moduleTitle($module)) ?></h2>
                        <p>Fundația comuna este activa. Functionalitatile din proiectul existent vor fi migrate aici in etapa urmatoare.</p>
                        <a href="?module=dashboard">Inapoi la vederea generala</a>
                    </section>
                <?php endif; ?>
            </main>

            <nav class="mobile-nav" aria-label="Navigare mobila">
                <?= mobileNavLink('dashboard', 'Acasa', $module) ?>
                <?php if ($canSeeGuards): ?><?= mobileNavLink('guards', 'Garzi', $module) ?><?php endif; ?>
                <?php if ($canSeeProgOp): ?><?= mobileNavLink('progop', 'ProgOp', $module) ?><?php endif; ?>
                <?= mobileNavLink('help', 'Ajutor', $module) ?>
                <?php if ($canAdmin): ?><?= mobileNavLink('admin', 'Admin', $module) ?><?php endif; ?>
                <a class="mobile-nav-logout" href="?logout=1" aria-label="Iesire din cont"><span></span>Iesire</a>
            </nav>
        </div>
    </body>
    </html>
    <?php
}

function navLink(string $target, string $label, string $current, string $icon): string
{
    $active = $target === $current ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="?module=' . e($target) . '" title="' . e($label) . '"><span data-icon="' . e($icon) . '"></span><span class="nav-label">' . e($label) . '</span></a>';
}

function mobileNavLink(string $target, string $label, string $current): string
{
    $active = $target === $current ? ' active' : '';
    return '<a class="' . $active . '" href="?module=' . e($target) . '"><span></span>' . e($label) . '</a>';
}

function moduleTitle(string $module): string
{
    return ['dashboard' => 'Vedere generala', 'guards' => 'Garzi', 'progop' => 'Program operator', 'admin' => 'Administrare', 'help' => 'Ajutor si instructiuni'][$module] ?? 'Vedere generala';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    return strtoupper(substr($parts[0] ?? 'U', 0, 1) . substr($parts[1] ?? '', 0, 1));
}

function romanianWeekday(string $date): string
{
    $labels=[1=>'Luni',2=>'Marți',3=>'Miercuri',4=>'Joi',5=>'Vineri',6=>'Sâmbătă',7=>'Duminică'];
    return $labels[(int)(new DateTimeImmutable($date))->format('N')];
}

function familiarName(array $user): string
{
    $givenNames = trim((string) ($user['first_name'] ?? ''));
    if ($givenNames !== '') {
        return preg_split('/\s+/u', $givenNames)[0] ?? $givenNames;
    }

    $displayParts = preg_split('/\s+/u', trim((string) ($user['display_name'] ?? ''))) ?: [];
    return $displayParts[1] ?? $displayParts[0] ?? (string) $user['username'];
}
