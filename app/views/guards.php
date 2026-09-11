<section class="guards-legacy-module" id="guardsApp">
    <div class="guards-commandbar">
        <div class="month-navigation compact">
            <button type="button" class="icon-button" id="guardsPrevMonth" aria-label="Luna anterioara">‹</button>
            <label class="month-picker">Luna<input type="month" id="guardsMonth" value="<?= e($initialGuardMonth) ?>"></label>
            <button type="button" class="icon-button" id="guardsNextMonth" aria-label="Luna urmatoare">›</button>
        </div>
        <div class="specialty-tabs compact" role="tablist" aria-label="Specialitate">
            <button type="button" class="active" data-specialty="OG">OG</button>
            <button type="button" data-specialty="ATI">ATI</button>
            <button type="button" data-specialty="NN">NN</button>
        </div>
        <strong class="specialty-name" id="guardsSpecialtyName">Obstetrica-Ginecologie</strong>
        <label class="guards-all-lines-switch"><input type="checkbox" id="guardsAllLines"><span>Toate liniile OG + ATI + NN</span></label>
        <div class="module-status" id="guardsStatus">Se incarca garzile...</div>
        <button type="button" class="ghost-button guards-export-trigger" id="guardsExportButton">Export</button>
    </div>

    <div class="guards-line-legend" id="guardsLineLegend"></div>
    <div class="guards-progress-row">
        <span>Completare:</span>
        <div class="guards-progress-items" id="guardsProgress"></div>
        <strong id="guardsProgressTotal">0%</strong>
    </div>

    <div class="guards-workbench">
        <aside class="guards-side-panel">
            <header class="guards-side-tabs">
                <button type="button" class="active" data-stats-view="personal">Personal</button>
                <button type="button" data-stats-view="summary">Statistici</button>
                <button type="button" data-stats-view="journal">Jurnal</button>
            </header>
            <section class="guards-personnel-panel" id="guardsPersonnelView">
                <header class="guards-panel-header">
                    <div class="person-filter" id="personFilter">
                        <button type="button" class="active" data-person-filter="all">Toti</button>
                        <button type="button" data-person-filter="specialist">Spec.</button>
                        <button type="button" data-person-filter="resident">Rez.</button>
                    </div>
                    <input type="search" id="personSearch" placeholder="Cauta dupa nume..." autocomplete="off">
                </header>
                <div class="guards-people-list" id="guardsPeople"></div>
                <footer class="guards-personnel-footer">
                    <span>Sunt afisati numai medicii activi care participa la garzi.</span>
                    <button type="button" class="ghost-button" id="clearPersonHighlight">Arata tot calendarul</button>
                </footer>
            </section>
            <section class="guards-stats-view" id="guardsStatsSummary" hidden>
                <div class="guards-line-stats" id="guardsLineStats"></div>
                <div class="guards-person-stats-title">Garzi / persoana</div>
                <div class="guards-person-stats" id="guardsPersonStats"></div>
            </section>
            <section class="guards-journal-view" id="guardsJournal" hidden></section>
        </aside>

        <section class="guards-calendar-panel">
            <div class="guards-line-selector">
                <span>Linia:</span>
                <div id="guardsLineSelector"></div>
            </div>
            <div class="guards-action-bar" id="guardsActionBar">
                <span id="guardsSelectionStatus">Selecteaza un medic si apoi zilele in care intra de garda.</span>
                <button type="button" class="ghost-button" id="guardsCancelExchange" hidden>Anuleaza schimbul</button>
                <button type="button" class="ghost-button guards-undo-button" id="guardsUndoButton" hidden>Anuleaza ultima actiune</button>
            </div>
            <div class="calendar-weekdays" aria-hidden="true"><span>Lu</span><span>Ma</span><span>Mi</span><span>Jo</span><span>Vi</span><span>Sa</span><span>Du</span></div>
            <div class="guards-calendar legacy" id="guardsCalendar"></div>
        </section>

    </div>

    <aside class="detail-drawer" id="guardDrawer" aria-hidden="true">
        <div class="drawer-backdrop" data-close-drawer></div>
        <section class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="guardDrawerTitle">
            <button type="button" class="drawer-close" data-close-drawer aria-label="Inchide">×</button>
            <span class="eyebrow">Garda selectata</span>
            <h2 id="guardDrawerTitle">Completeaza linia</h2>
            <form id="guardForm" class="module-form">
                <input type="hidden" id="guardDate">
                <input type="hidden" id="guardAssignmentId">
                <label>Linia<select id="guardLine" required></select></label>
                <label>Medic<select id="guardPerson" required></select></label>
                <div class="drawer-actions">
                    <button type="button" class="danger-button" id="guardDelete">Sterge garda</button>
                    <button type="submit" class="primary-button">Salveaza</button>
                </div>
            </form>
        </section>
    </aside>

    <aside class="detail-drawer" id="guardActionDrawer" aria-hidden="true">
        <div class="drawer-backdrop" data-close-guard-action></div>
        <section class="drawer-panel guard-action-panel" role="dialog" aria-modal="true" aria-labelledby="guardActionTitle">
            <button type="button" class="drawer-close" data-close-guard-action aria-label="Inchide">x</button>
            <span class="eyebrow">Linia 1</span>
            <h2 id="guardActionTitle">Garda selectata</h2>
            <p id="guardActionDescription"></p>
            <div class="guard-action-buttons" id="guardActionMenu">
                <button type="button" class="primary-button" id="guardStartExchange" hidden>Schimba cu alta garda</button>
                <button type="button" class="secondary-button" id="guardTransfer" hidden>Cedeaza garda</button>
                <button type="button" class="primary-button" id="guardTakeover" hidden>Preia aceasta garda</button>
                <button type="button" class="ghost-button" data-close-guard-action>Renunta</button>
            </div>
            <div class="guard-action-flow" id="guardActionFlow" hidden>
                <h3 id="guardActionFlowTitle">Confirma actiunea</h3>
                <label id="guardActionSelectLabel">Selecteaza
                    <select id="guardActionSelect"></select>
                </label>
                <div class="guard-action-confirmation" id="guardActionConfirmation"></div>
                <div class="guard-action-buttons">
                    <button type="button" class="primary-button" id="guardActionConfirm">Confirma</button>
                    <button type="button" class="ghost-button" id="guardActionBack">Inapoi</button>
                </div>
            </div>
        </section>
    </aside>

    <aside class="detail-drawer" id="guardExportDrawer" aria-hidden="true">
        <div class="drawer-backdrop" data-close-guard-export></div>
        <section class="drawer-panel guard-export-panel" role="dialog" aria-modal="true" aria-labelledby="guardExportTitle">
            <button type="button" class="drawer-close" data-close-guard-export aria-label="Inchide">x</button>
            <span class="eyebrow">Export garzi</span><h2 id="guardExportTitle">Tabel lunar formatat</h2>
            <form id="guardExportForm" class="guard-export-form">
                <fieldset><legend>Specialitati</legend><label><input type="checkbox" name="specialties" value="OG" checked> OG</label><label><input type="checkbox" name="specialties" value="ATI"> ATI</label><label><input type="checkbox" name="specialties" value="NN"> NN</label></fieldset>
                <label>Afisarea medicilor<select name="name_format"><option value="full">Nume complet</option><option value="compact">Nume de familie + initiala</option></select></label>
                <input type="hidden" id="guardExportFormat" value="pdf">
                <div class="guard-export-note"><strong>Format: PDF</strong></div>
                <div class="guard-export-note">Specialitatile selectate sunt reunite intr-un singur tabel general, pe aceeasi pagina. Weekendurile si sarbatorile raman evidentiate.</div>
                <div class="drawer-actions"><button type="button" class="ghost-button" data-close-guard-export>Renunta</button><button type="submit" class="primary-button">Descarca</button></div>
            </form>
        </section>
    </aside>
</section>
