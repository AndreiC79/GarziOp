<section class="admin-module" id="adminApp">
    <div class="admin-intro">
        <div><span class="eyebrow">Configurare garzi</span><h2>Linii pe specialitate</h2><p>Stabileste cate linii sunt deschise permanent in fiecare luna. O singura linie suplimentara se poate deschide automat.</p></div>
        <span class="admin-save-status" id="adminSaveStatus">Se incarca...</span>
    </div>

    <form class="admin-settings-card" id="guardLineConfigurationForm">
        <div class="admin-setting-row monthly-lines-month">
            <div><strong>Luna configurată</strong><span>Numărul liniilor deschise poate fi diferit în fiecare lună</span></div>
            <label>Lună<input type="month" id="adminGuardLinesMonth" required></label>
            <small>Se deschide o singură linie extra după completarea tuturor liniilor de bază în fiecare zi.</small>
        </div>
        <div class="admin-setting-row">
            <div><strong>OG</strong><span>Obstetrica-Ginecologie</span></div>
            <label>Linii deschise<select name="OG" id="adminLinesOG"></select></label>
            <label class="admin-check"><input type="checkbox" id="adminExtraLineOG"> Deschide o linie suplimentară când toate sunt completate</label>
        </div>
        <div class="admin-setting-row">
            <div><strong>ATI</strong><span>Anestezie si Terapie Intensiva</span></div>
            <label>Linii deschise<select name="ATI" id="adminLinesATI"></select></label>
            <label class="admin-check"><input type="checkbox" id="adminExtraLineATI"> Deschide o linie suplimentară când toate sunt completate</label>
        </div>
        <div class="admin-setting-row">
            <div><strong>NN</strong><span>Neonatologie</span></div>
            <label>Linii deschise<select name="NN" id="adminLinesNN"></select></label>
            <label class="admin-check"><input type="checkbox" id="adminExtraLineNN"> Deschide o linie suplimentară când toate sunt completate</label>
        </div>
        <label class="admin-check guard-protection-toggle"><input type="checkbox" id="adminOgLine1Protected"> Protejeaza OG Linia 1 prin schimb si preluare</label>
        <footer><span id="adminProtectionHelp">Liniile configurate sunt deschise direct; doar următoarea linie este condiționată.</span><button type="submit" class="primary-button">Salvează luna</button></footer>
    </form>

    <section class="admin-generator-card" id="adminOgGenerator">
        <header>
            <div><span class="eyebrow">Generare automata</span><h3>OG Linia 1</h3><p>Genereaza zilnic, in ordinea stabilita mai jos. Rezidentii nu intra in aceasta rotatie.</p></div>
            <span class="generation-status" id="adminGenerationStatus">Niciun draft</span>
        </header>
        <form class="generation-form" id="adminGenerationForm">
            <label>Incepand cu data<input type="date" id="adminGenerationStart" required></label>
            <label>Incepand cu medicul<select id="adminGenerationDoctor" required></select></label>
            <label>Pentru cate luni<input type="number" id="adminGenerationMonths" min="1" max="24" value="1" required></label>
            <button type="submit" class="primary-button">Genereaza draft</button>
            <button type="button" class="ghost-button" id="adminPublishGeneration" hidden>Publica draftul</button>
        </form>
        <div class="rotation-editor">
            <div class="rotation-heading"><div><strong>Ordinea de generare</strong><span>Medici OG fara rezidenti</span></div><div class="rotation-add"><select id="adminRotationCandidate"></select><button type="button" class="ghost-button" id="adminRotationAdd">Adauga</button></div></div>
            <div class="rotation-list" id="adminRotationList"></div>
        </div>
    </section>

    <section class="admin-users-card guard-participation-card" id="adminGuardParticipationCard">
        <header class="admin-users-header">
            <div><span class="eyebrow">Eligibilitate garzi</span><h3>Cine apare in listele de garda</h3><p>Sunt disponibili numai medicii care au cont. Dezactivarea nu afecteaza Programul operator sau istoricul.</p></div>
        </header>
        <div class="guard-participation-filters">
            <label>Specialitate<select id="adminGuardParticipationSpecialty"><option value="OG">OG</option><option value="ATI">ATI</option><option value="NN">NN</option></select></label>
            <label>Categorie<select id="adminGuardParticipationCategory"><option value="all">Toti medicii</option><option value="specialist">Specialisti</option><option value="resident">Rezidenti</option></select></label>
            <label>Cautare<input type="search" id="adminGuardParticipationSearch" placeholder="Nume sau username..."></label>
        </div>
        <div class="guard-participation-legend"><span><i></i> Participa la garzi</span><span class="inactive"><i></i> Nu face garzi</span></div>
        <div class="guard-participation-list" id="adminGuardParticipationList"></div>
        <p class="guard-participation-help">Bifeaza <strong>Nu face garzi</strong> pentru excludere. Medicul va cobori automat la finalul listei si nu va mai aparea in Personal, Statistici sau dropdownurile modulului Garzi.</p>
    </section>

    <section class="admin-users-card">
        <header class="admin-users-header">
            <div><span class="eyebrow">Personal si acces</span><h3>Medici, rezidenti si utilizatori</h3><p>Persoana ramane disponibila in Program Operator chiar daca nu participa la garzi sau nu are cont.</p></div>
            <div class="admin-users-header-actions">
                <button type="button" class="ghost-button" id="adminUserReport">Descarca raport PDF</button>
                <button type="button" class="ghost-button" id="adminDashboardAccount">Cont Dashboard</button>
                <button type="button" class="primary-button" id="adminAddPerson">Adauga persoana</button>
            </div>
        </header>
        <div class="admin-users-filters">
            <input type="search" id="adminPeopleSearch" placeholder="Cauta dupa nume sau username...">
            <select id="adminPeopleSpecialty"><option value="">Toate categoriile</option><option>OG</option><option>ATI</option><option>NN</option><option value="RESIDENTS">Rezidenti</option><option value="ASISTENTA">Asistente</option><option value="SISTEM">Sistem / Dashboard</option></select>
            <select id="adminPeopleSort" aria-label="Ordoneaza lista"><option value="account_type">Ordoneaza dupa tipul de cont</option><option value="name">Ordoneaza dupa nume</option><option value="specialty">Ordoneaza dupa specialitate</option></select>
        </div>
        <div class="admin-users-list" id="adminUsersList"></div>
    </section>

    <aside class="detail-drawer" id="adminPersonDrawer" aria-hidden="true">
        <div class="drawer-backdrop" data-close-admin-person></div>
        <section class="drawer-panel admin-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="adminPersonTitle">
            <button type="button" class="drawer-close" data-close-admin-person aria-label="Inchide">x</button>
            <span class="eyebrow">Date personal</span><h2 id="adminPersonTitle">Persoana noua</h2>
            <form id="adminPersonForm" class="admin-form-grid">
                <input type="hidden" id="adminPersonId">
                <label>Prenume<input id="adminFirstName" required maxlength="100"></label>
                <label>Nume de familie<input id="adminLastName" required maxlength="100"></label>
                <label class="full">Nume afisat automat<input id="adminDisplayName" required maxlength="220" readonly></label>
                <label>Titulatura<input id="adminTitle" placeholder="Dr., Prof. Dr."></label>
                <label>Categorie<select id="adminCategory"><option>Medic</option><option>Medic specialist</option><option>Medic primar</option><option>Rezident</option><option>Rezident an I</option><option>Rezident an II</option><option>Rezident an III</option><option>Rezident an IV</option><option>Rezident an V</option><option>Asistenta</option></select></label>
                <label>Specialitate / grupa<select id="adminSpecialty"><option>OG</option><option>ATI</option><option>NN</option><option value="ASISTENTA">Asistenta</option></select></label>
                <label>Ordine garzi<input type="number" min="1" id="adminRotation"></label>
                <label class="admin-check"><input type="checkbox" id="adminPersonActive" checked> Activ in aplicatie</label>
                <label class="admin-check"><input type="checkbox" id="adminGuardEligible" checked> Participa la garzi</label>
                <label class="full">Observatii<textarea id="adminPersonNotes" rows="3"></textarea></label>
                <div class="drawer-actions full"><button type="button" class="ghost-button" data-close-admin-person>Renunta</button><button type="submit" class="primary-button">Salveaza persoana</button></div>
            </form>
        </section>
    </aside>

    <aside class="detail-drawer" id="adminAccountDrawer" aria-hidden="true">
        <div class="drawer-backdrop" data-close-admin-account></div>
        <section class="drawer-panel admin-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="adminAccountTitle">
            <button type="button" class="drawer-close" data-close-admin-account aria-label="Inchide">x</button>
            <span class="eyebrow">Cont si drepturi</span><h2 id="adminAccountTitle">Configurare cont</h2>
            <form id="adminAccountForm" class="admin-form-grid">
                <input type="hidden" id="adminAccountPersonId"><input type="hidden" id="adminUserId">
                <label class="full">Preset cont<select id="adminAccountPreset"><option value="custom">Personalizat</option><option value="platform_admin">Administrator platforma</option><option value="super_admin">Superuser</option><option value="operating_room_dashboard">Dashboard sală de operații</option><option value="doctor_admin">Medic administrator</option><option value="doctor_standard">Medic standard</option><option value="doctor_resident">Medic rezident</option><option value="doctor_ati">Medic ATI</option><option value="doctor_nn">Medic NN</option><option value="guards_admin">Administrator garzi</option><option value="assistant_editor">Asistenta - modificare Program operator</option><option value="assistant_viewer">Doar vizualizare si tiparire</option></select><small>Contul Dashboard afișează panoul tactil al blocului operator și poate fi folosit simultan pe mai multe ecrane.</small></label>
                <label class="full">Username<input id="adminUsername" required pattern="[a-z0-9._-]{3,100}"></label>
                <label>Stare<select id="adminUserStatus"><option value="active">Activ</option><option value="inactive">Inactiv</option><option value="locked">Blocat</option></select></label>
                <label>Acces API / token<select id="adminUserApiAccess"><option value="none">Fara acces API</option><option value="read">Doar citire</option><option value="schedule">Citire si programare operatii</option><option value="full">Conform tuturor drepturilor contului</option></select><small>Nu creeaza automat un token; stabileste doar limita maxima permisa contului.</small></label>
                <label>Parola temporara<input type="text" id="adminTemporaryPassword" minlength="12" placeholder="Se genereaza automat"></label>
                <details class="full admin-advanced-rights"><summary>Drepturi avansate si explicatii</summary><fieldset><legend>Roluri</legend><div class="admin-role-options" id="adminRoleOptions"></div></fieldset></details>
                <div class="drawer-actions full"><button type="button" class="ghost-button" id="adminResetPassword">Reseteaza parola</button><button type="submit" class="primary-button">Salveaza contul</button></div>
            </form>
            <section class="admin-permissions-section" id="adminPermissionsSection" hidden>
                <header><h3>Drepturi individuale</h3><p>Bifele reprezinta accesul efectiv dupa aplicarea rolurilor. Debifarea unui drept modifica explicit contul.</p></header>
                <form id="adminPermissionsForm"><div id="adminPermissionOptions"></div><button type="submit" class="primary-button">Salveaza drepturile</button></form>
            </section>
            <div class="admin-temporary-result" id="adminTemporaryResult" hidden></div>
            <section class="admin-account-tokens" id="adminAccountTokens" hidden><h3>Tokenurile acestui cont</h3><div id="adminAccountTokenList"></div></section>
        </section>
    </aside>

    <section class="admin-users-card admin-reports-card" id="adminReportsCard" hidden>
        <header class="admin-users-header">
            <div><span class="eyebrow">Statistici si rapoarte</span><h3>Garzi si Program operator</h3><p>Rapoarte lunare sau anuale, fara numele pacientelor. Generarea este disponibila numai Administratorului platformei si Superuserului.</p></div>
        </header>
        <form class="admin-report-filters" id="adminReportsForm">
            <label>Modul<select id="adminReportKind"><option value="guards">Garzi</option><option value="progop">Program operator</option><option value="daily_activity">Activitate ziua precedenta</option></select></label>
            <label>Perioada<select id="adminReportPeriod"><option value="monthly">Lunar</option><option value="annual">Anual</option></select></label>
            <label id="adminReportMonthLabel">Luna<input type="month" id="adminReportMonth" required></label>
            <label id="adminReportYearLabel" hidden>Anul<input type="number" id="adminReportYear" min="2020" max="2100"></label>
            <label id="adminReportSpecialtyLabel">Specialitate<select id="adminReportSpecialty"><option value="">Toate</option><option>OG</option><option>ATI</option><option>NN</option></select></label>
            <label>Medic<select id="adminReportDoctor"><option value="">Toti medicii</option></select></label>
            <div class="admin-report-actions"><button type="submit" class="primary-button">Afiseaza statistica</button><button type="button" class="ghost-button" id="adminReportPdf">Descarca PDF</button><button type="button" class="ghost-button" id="adminReportXlsx">Descarca Excel</button></div>
        </form>
        <div class="admin-report-status" id="adminReportStatus">Alege perioada si apasa Afiseaza statistica.</div>
        <div class="admin-report-preview" id="adminReportPreview"></div>
    </section>

    <section class="admin-users-card admin-backup-card" id="adminBackupCard" hidden>
        <header class="admin-users-header">
            <div><span class="eyebrow">Siguranta si istoric</span><h3>Versiuni si backup</h3><p>Restaurarea unei versiuni de garzi creeaza o versiune noua si pastreaza istoricul. Copiile JSON contin datele Programului operator, nu intreaga baza de date.</p></div>
            <button type="button" class="ghost-button" id="adminCreateProgOpBackup">Creeaza copie Program operator</button>
        </header>
        <div class="admin-backup-layout">
            <section><header><h4>Versiuni program de garzi</h4><span>Versiunea activa este marcata clar</span></header><div class="admin-version-list" id="adminGuardVersionList"></div></section>
            <section><header><h4>Copii Program operator</h4><span>Fisiere JSON descarcabile</span></header><div class="admin-backup-list" id="adminBackupList"></div><p class="admin-backup-warning">Restaurarea unui fisier JSON nu se face automat, deoarece ar putea suprascrie operatii introduse ulterior. Backupul complet al bazei MySQL se restaureaza separat de administratorul serverului.</p></section>
        </div>
        <section class="operational-backup-downloads">
            <header><h4>Backup operational descarcabil</h4><span>Operatii, EXTRA, urgente, statusuri, garzi publicate si loguri</span></header>
            <div class="admin-report-actions">
                <a class="ghost-button" href="api/backup-export.php?period=daily&amp;date=<?= e(date('Y-m-d')) ?>">Descarca ziua curenta</a>
                <a class="ghost-button" href="api/backup-export.php?period=monthly&amp;date=<?= e(date('Y-m-d')) ?>">Descarca luna curenta</a>
                <a class="ghost-button" href="api/backup-export.php?period=annual&amp;date=<?= e(date('Y-m-d')) ?>">Descarca anul curent</a>
            </div>
            <p class="admin-backup-warning">Pentru copia automata pe serverul spitalului foloseste un token tehnic dedicat si instructiunile din docs/BACKUP_SERVER_SPITAL.md. Exportul nu schimba baza de date.</p>
            <form id="adminBackupTokenForm" class="admin-form-grid backup-token-form">
                <label>Cont tehnic / administrator<select id="adminBackupTokenOwner" required></select></label>
                <label>Expira la<input type="date" id="adminBackupTokenExpiry" required></label>
                <div class="drawer-actions full"><button type="submit" class="primary-button">Genereaza token pentru backup</button></div>
            </form>
            <div class="admin-temporary-result" id="adminBackupTokenResult" hidden></div>
        </section>
    </section>

    <section class="admin-users-card admin-audit-card" id="adminAuditCard" hidden>
        <header class="admin-users-header">
            <div><span class="eyebrow">Monitorizare</span><h3>Activitate si utilizatori conectati</h3><p>Ultimele actiuni sunt afisate cu utilizatorul, momentul, obiectul modificat si adresa de conectare.</p></div>
            <button type="button" class="ghost-button" id="adminAuditRefresh">Reincarca</button>
        </header>
        <div class="admin-audit-stats">
            <article><span>Utilizatori conectati</span><strong id="adminConnectedUsers">0</strong><small>activi in ultimele 5 minute</small></article>
            <article><span>Sesiuni active</span><strong id="adminConnectedSessions">0</strong><small>include mai multe dispozitive</small></article>
            <article><span>Actiuni in 24 ore</span><strong id="adminActionsToday">0</strong><small>toate modulele</small></article>
            <article><span>Conturi active</span><strong id="adminActiveAccounts">0</strong><small>utilizatori care se pot conecta</small></article>
        </div>
        <div class="admin-connected-list" id="adminConnectedList"></div>
        <div class="admin-users-filters">
            <input type="search" id="adminAuditSearch" placeholder="Cauta dupa utilizator, actiune sau obiect...">
            <select id="adminAuditModule"><option value="">Toate modulele</option><option value="auth">Autentificare</option><option value="guards">Garzi</option><option value="progop">Program operator</option><option value="admin">Administrare</option><option value="demo">Populare test</option></select>
        </div>
        <div class="admin-audit-list" id="adminAuditList"></div>
    </section>

    <section class="admin-users-card api-access-card" id="adminApiAccess">
        <header class="admin-users-header">
            <div><span class="eyebrow">Integrare securizata</span><h3>Acces API / AI</h3><p>Creeaza tokenuri limitate pentru ChatGPT sau alte servicii. Tokenul este afisat o singura data si fiecare apel este logat.</p></div>
        </header>
        <div class="api-base-url"><span>Adresa API dupa deploy</span><code id="adminApiBaseUrl"></code></div>
        <form id="adminApiTokenForm" class="admin-form-grid">
            <label class="full">Contul pentru care se emite tokenul<select id="adminApiTokenOwner" required></select></label>
            <label>Nume integrare<input id="adminApiTokenName" maxlength="120" placeholder="ChatGPT - garzi" required></label>
            <label>Expira la<input type="date" id="adminApiTokenExpiry" required></label>
            <label class="full">Nivel de acces<select id="adminApiAccessPreset"><option value="custom">Personalizat</option><option value="read">Doar citire si analiza</option><option value="schedule">Citire si programare operatii</option><option value="full">Control complet</option></select></label>
            <fieldset class="full"><legend>Drepturile tokenului</legend><div class="admin-role-options api-permission-options" id="adminApiPermissionOptions"></div></fieldset>
            <div class="drawer-actions full"><button type="submit" class="primary-button">Genereaza token</button></div>
        </form>
        <div class="admin-temporary-result api-token-result" id="adminApiTokenResult" hidden></div>
        <div class="api-token-list" id="adminApiTokenList"></div>
    </section>
</section>
