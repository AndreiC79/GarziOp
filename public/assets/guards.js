(() => {
    const root = document.getElementById("guardsApp");
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
    let lineConfig = [
        { line: 1, label: "L1 Spec.", short: "L1" },
        { line: 2, label: "L2 Spec.", short: "L2" },
        { line: 3, label: "L3 Rez.", short: "L3" },
        { line: 4, label: "L4 Rez.", short: "L4" },
        { line: 5, label: "L5 Rez.", short: "L5" },
        { line: 6, label: "L6 Rez.", short: "L6" },
    ];
    const specialtyNames = { OG: "Obstetrica-Ginecologie", ATI: "Anestezie si Terapie Intensiva", NN: "Neonatologie" };
    const stateLabels = { initial: "initiala", exchanged: "schimbata", transferred: "preluata", admin_corrected: "corectata", manual_corrected: "inlocuita" };
    const el = {
        month: document.getElementById("guardsMonth"), calendar: document.getElementById("guardsCalendar"), status: document.getElementById("guardsStatus"),
        prev: document.getElementById("guardsPrevMonth"), next: document.getElementById("guardsNextMonth"), tabs: [...document.querySelectorAll("[data-specialty]")],
        specialtyName: document.getElementById("guardsSpecialtyName"), lineLegend: document.getElementById("guardsLineLegend"), progress: document.getElementById("guardsProgress"),
        progressTotal: document.getElementById("guardsProgressTotal"), people: document.getElementById("guardsPeople"), search: document.getElementById("personSearch"),
        filters: [...document.querySelectorAll("[data-person-filter]")], clearPerson: document.getElementById("clearPersonHighlight"), lineSelector: document.getElementById("guardsLineSelector"),
        lineStats: document.getElementById("guardsLineStats"), personStats: document.getElementById("guardsPersonStats"), drawer: document.getElementById("guardDrawer"),
        statsTabs: [...document.querySelectorAll("[data-stats-view]")], personnelView: document.getElementById("guardsPersonnelView"), statsSummary: document.getElementById("guardsStatsSummary"), journal: document.getElementById("guardsJournal"),
        title: document.getElementById("guardDrawerTitle"), form: document.getElementById("guardForm"), date: document.getElementById("guardDate"),
        assignmentId: document.getElementById("guardAssignmentId"), line: document.getElementById("guardLine"), person: document.getElementById("guardPerson"), remove: document.getElementById("guardDelete"),
        selectionStatus: document.getElementById("guardsSelectionStatus"), cancelExchange: document.getElementById("guardsCancelExchange"), undo: document.getElementById("guardsUndoButton"),
        actionDrawer: document.getElementById("guardActionDrawer"), actionTitle: document.getElementById("guardActionTitle"), actionDescription: document.getElementById("guardActionDescription"),
        actionMenu: document.getElementById("guardActionMenu"), actionFlow: document.getElementById("guardActionFlow"), actionFlowTitle: document.getElementById("guardActionFlowTitle"),
        actionSelectLabel: document.getElementById("guardActionSelectLabel"), actionSelect: document.getElementById("guardActionSelect"), actionConfirmation: document.getElementById("guardActionConfirmation"),
        actionConfirm: document.getElementById("guardActionConfirm"), actionBack: document.getElementById("guardActionBack"),
        startExchange: document.getElementById("guardStartExchange"), transfer: document.getElementById("guardTransfer"), takeover: document.getElementById("guardTakeover"),
        exportButton: document.getElementById("guardsExportButton"), exportDrawer: document.getElementById("guardExportDrawer"), exportForm: document.getElementById("guardExportForm"), exportFormat: document.getElementById("guardExportFormat"),
        allLines: document.getElementById("guardsAllLines"),
    };
    const state = {
        specialty: "OG", data: null, dataBySpecialty: {}, showAllLines: false, personFilter: "all", personSearch: "", selectedPersonId: null, draggingGuard: null,
        selectedLine: 1, statsView: "personal", exchangeSource: null, actionAssignment: null, actionMode: null, busy: false,
    };

    el.month.addEventListener("change", () => { cancelExchange(); load(); });
    el.prev.addEventListener("click", () => shiftMonth(-1));
    el.next.addEventListener("click", () => shiftMonth(1));
    el.tabs.forEach((tab) => tab.addEventListener("click", () => selectSpecialty(tab)));
    el.allLines.addEventListener("change", () => { state.showAllLines = el.allLines.checked; cancelExchange(); load(); });
    el.search.addEventListener("input", () => { state.personSearch = el.search.value.trim().toLocaleLowerCase("ro-RO"); renderPeople(); });
    el.filters.forEach((filter) => filter.addEventListener("click", () => {
        state.personFilter = filter.dataset.personFilter;
        el.filters.forEach((item) => item.classList.toggle("active", item === filter));
        renderPeople();
    }));
    el.clearPerson.addEventListener("click", () => { state.selectedPersonId = null; renderPeople(); renderCalendar(); renderStats(); renderActionBar(); });
    el.statsTabs.forEach((tab) => tab.addEventListener("click", () => { state.statsView = tab.dataset.statsView; renderStats(); }));
    document.querySelectorAll("[data-close-drawer]").forEach((button) => button.addEventListener("click", closeDrawer));
    document.querySelectorAll("[data-close-guard-action]").forEach((button) => button.addEventListener("click", closeActionDrawer));
    el.form.addEventListener("submit", saveGuard);
    el.remove.addEventListener("click", deleteGuard);
    el.cancelExchange.addEventListener("click", cancelExchange);
    el.undo.addEventListener("click", undoLastAction);
    el.startExchange.addEventListener("click", startExchange);
    el.transfer.addEventListener("click", () => beginGuardAction("transfer"));
    el.takeover.addEventListener("click", takeoverGuard);
    el.actionBack.addEventListener("click", showGuardActionMenu);
    el.actionConfirm.addEventListener("click", confirmGuardAction);
    el.exportButton.addEventListener("click", openGuardExport);
    el.exportForm.addEventListener("submit", downloadGuardExport);
    document.querySelectorAll("[data-close-guard-export]").forEach((button) => button.addEventListener("click", closeGuardExport));

    async function load() {
        setStatus("Se incarca...");
        try {
            const specialties = state.showAllLines ? ["OG", "ATI", "NN"] : [state.specialty];
            const responses = await Promise.all(specialties.map(async (specialty) => {
                const response = await fetch(`api/guards.php?month=${encodeURIComponent(el.month.value)}&specialty=${encodeURIComponent(specialty)}`, { cache: "no-store" });
                const data = await response.json();
                if (!response.ok || !data.ok) throw new Error(data.message || `Garzile ${specialty} nu au putut fi incarcate.`);
                return [specialty, data];
            }));
            state.dataBySpecialty = Object.fromEntries(responses);
            state.data = state.dataBySpecialty[state.specialty];
            const data = state.data;
            lineConfig = getLineConfig(data);
            if (state.selectedLine > lineConfig.length) state.selectedLine = 1;
            if (state.selectedPersonId && !data.people.some((person) => String(person.id) === String(state.selectedPersonId))) state.selectedPersonId = null;
            renderAll();
            const shownCount = Object.values(state.dataBySpecialty).reduce((sum, item) => sum + item.assignments.length, 0);
            const lineStatus = !state.showAllLines && data.nextLockedLine
                ? ` · L${data.nextLockedLine} se deschide după completarea L1-L${data.configuredLineCount} în toate zilele`
                : (!state.showAllLines && data.extraLineOpen ? ` · L${data.lineCount} extra deschisă` : "");
            setStatus(`${shownCount} garzi${state.showAllLines ? " · toate specialitatile" : ""}${lineStatus}`);
        } catch (error) {
            setStatus(error.message, true);
            el.calendar.innerHTML = "";
        }
    }

    function getLineConfig(data) {
        return Array.isArray(data?.lineConfig) && data.lineConfig.length
            ? data.lineConfig.map((item) => ({ line: Number(item.line), label: item.label, short: item.short || `L${item.line}` }))
            : [];
    }

    function getSpecialtyData(specialty) { return state.dataBySpecialty[specialty] || (specialty === state.specialty ? state.data : null); }

    function renderAll() {
        el.specialtyName.textContent = specialtyNames[state.specialty];
        renderLineControls();
        renderProgress();
        renderPeople();
        renderCalendar();
        renderStats();
        renderActionBar();
    }

    function renderLineControls() {
        const counts = countsByLine();
        el.lineLegend.innerHTML = lineConfig.map((config) => `<span class="line-legend-chip line-${config.line}"><i></i>${config.label}<b>${counts[config.line] || 0}</b></span>`).join("");
        el.lineSelector.innerHTML = lineConfig.map((config) => `<button type="button" class="guard-line-button line-${config.line}${state.selectedLine === config.line ? " active" : ""}" data-select-line="${config.line}">${config.label}</button>`).join("");
        el.lineSelector.querySelectorAll("[data-select-line]").forEach((button) => button.addEventListener("click", () => {
            state.selectedLine = Number(button.dataset.selectLine);
            renderLineControls();
            renderCalendar();
            renderActionBar();
        }));
    }

    function renderProgress() {
        const [year, month] = el.month.value.split("-").map(Number);
        const days = new Date(year, month, 0).getDate();
        const counts = countsByLine();
        el.progress.innerHTML = lineConfig.map((config) => {
            const count = counts[config.line] || 0;
            return `<div class="progress-line line-${config.line}"><span>${config.label}</span><div><i style="width:${Math.round(count / days * 100)}%"></i></div><b>${count}/${days}</b></div>`;
        }).join("");
        el.progressTotal.textContent = `${Math.round(state.data.assignments.length / Math.max(1, days * lineConfig.length) * 100)}%`;
    }

    function renderPeople() {
        const counts = countsByPerson();
        const people = state.data.people.filter((person) => {
            if (Number(person.active) !== 1 || Number(person.guard_eligible) !== 1) return false;
            const resident = isResident(person);
            if (state.personFilter === "resident" && !resident) return false;
            if (state.personFilter === "specialist" && resident) return false;
            return !state.personSearch || person.display_name.toLocaleLowerCase("ro-RO").includes(state.personSearch);
        });
        el.people.innerHTML = people.length ? people.map((person) => {
            const selected = String(person.id) === String(state.selectedPersonId);
            const category = isResident(person) ? "REZ" : "SPEC";
            return `<button type="button" class="personnel-item${selected ? " selected" : ""}" data-person="${person.id}"><span class="person-avatar">${escapeHtml(initials(person.display_name))}</span><span class="person-copy"><strong>${escapeHtml(person.display_name)}</strong><small><i>${category}</i> #${person.rotation_position || "-"}</small></span><b>${counts[person.id] || 0}</b></button>`;
        }).join("") : '<div class="personnel-empty">Niciun medic gasit.</div>';
        el.people.querySelectorAll("[data-person]").forEach((button) => button.addEventListener("click", () => {
            state.selectedPersonId = String(state.selectedPersonId) === button.dataset.person ? null : button.dataset.person;
            renderPeople();
            renderCalendar();
            renderStats();
            renderActionBar();
        }));
    }

    function renderCalendar() {
        const [year, month] = el.month.value.split("-").map(Number);
        const days = new Date(year, month, 0).getDate();
        const firstOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
        const specialties = state.showAllLines ? ["OG", "ATI", "NN"] : [state.specialty];
        const bundles = specialties.map((specialty) => {
            const data = getSpecialtyData(specialty);
            return { specialty, data, config: getLineConfig(data), assignments: groupBy(data?.assignments || [], "guard_date") };
        }).filter((bundle) => bundle.data);
        const totalLines = bundles.reduce((sum, bundle) => sum + bundle.config.length, 0);
        const holidays = Object.fromEntries(state.data.holidays.map((holiday) => [holiday.holiday_date, holiday.name]));
        const html = [];
        for (let index = 0; index < firstOffset; index++) html.push('<div class="calendar-spacer legacy"></div>');

        for (let day = 1; day <= days; day++) {
            const date = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
            const weekday = new Date(`${date}T12:00:00`).getDay();
            const dayAssignments = bundles.flatMap((bundle) => bundle.assignments[date] || []);
            const selectedAssignments = state.selectedPersonId
                ? dayAssignments.filter((item) => String(item.current_person_id) === String(state.selectedPersonId))
                : [];
            const selectedLine = selectedAssignments.length
                ? Math.min(...selectedAssignments.map((item) => Number(item.line_no)))
                : null;
            const classes = ["legacy-guard-day", `coverage-${dayAssignments.length}`];
            if (dayAssignments.length >= totalLines) classes.push("coverage-complete");
            if (weekday === 0 || weekday === 6) classes.push("weekend");
            if (holidays[date]) classes.push("holiday");
            if (date === today()) classes.push("today");
            if (selectedLine !== null) classes.push("person-highlight", `person-line-${selectedLine}`);
            const rows = bundles.map((bundle) => {
                const groupRows = bundle.config.map((config) => {
                    const assignment = (bundle.assignments[date] || []).find((item) => Number(item.line_no) === config.line);
                    const lineSelected = bundle.specialty === state.specialty && state.selectedLine === config.line;
                    if (assignment) {
                        const personSelected = bundle.specialty === state.specialty && state.selectedPersonId && String(assignment.current_person_id) === String(state.selectedPersonId);
                        const exchangeSource = state.exchangeSource && String(state.exchangeSource.id) === String(assignment.id);
                        const exchangeTarget = isProtectedOgLine1(config.line, bundle.specialty, bundle.data) && state.exchangeSource && !exchangeSource && date >= today();
                        const draggable = canDragGuardLine(bundle.specialty, config.line, date);
                        return `<button type="button" class="legacy-line-slot occupied line-${config.line}${lineSelected ? " line-active" : ""}${personSelected ? " person-active" : ""}${exchangeSource ? " exchange-source" : ""}${exchangeTarget ? " exchange-target" : ""}" data-slot data-specialty="${bundle.specialty}" data-assignment="${assignment.id}" data-date="${date}" data-line="${config.line}"${draggable ? ' draggable="true"' : ''} title="${bundle.specialty} L${config.line} · Garda ${escapeHtml(stateLabels[assignment.assignment_state] || assignment.assignment_state)}"><i></i><span>${escapeHtml(shortName(assignment.current_name))}</span></button>`;
                    }
                    return `<button type="button" class="legacy-line-slot empty line-${config.line}${lineSelected ? " line-active" : ""}" data-slot data-specialty="${bundle.specialty}" data-date="${date}" data-line="${config.line}"><i></i><span>${config.short} Lipsa</span></button>`;
                }).join("");
                return `<section class="guard-specialty-lines" data-line-group="${bundle.specialty}">${state.showAllLines ? `<b>${bundle.specialty}</b>` : ""}${groupRows}</section>`;
            }).join("");
            const badge = holidays[date] ? `<span class="legacy-day-badge holiday-badge" title="${escapeHtml(holidays[date])}">Liber</span>` : (weekday === 0 || weekday === 6 ? '<span class="legacy-day-badge">WE</span>' : "");
            html.push(`<article class="${classes.join(" ")}" data-day-date="${date}"><header><b>${day}</b><span>${weekdayShort(date)}</span>${badge}<em>${dayAssignments.length}/${totalLines}</em></header><div>${rows}</div></article>`);
        }
        el.calendar.innerHTML = html.join("");
        el.calendar.querySelectorAll("[data-slot]").forEach((button) => button.addEventListener("click", () => {
            if (state.draggingGuard) return;
            const specialty = button.dataset.specialty || state.specialty;
            const specialtyData = getSpecialtyData(specialty);
            const assignment = button.dataset.assignment ? specialtyData.assignments.find((item) => String(item.id) === button.dataset.assignment) : null;
            handleSlotClick(button.dataset.date, Number(button.dataset.line), assignment, specialty);
        }));
        el.calendar.querySelectorAll('[data-slot][draggable="true"]').forEach((button) => button.addEventListener("dragstart", startGuardDrag));
        el.calendar.querySelectorAll("[data-slot]").forEach((button) => {
            button.addEventListener("dragover", guardDragOver);
            button.addEventListener("dragleave", () => button.classList.remove("guard-drop-target"));
            button.addEventListener("drop", dropGuardLine);
        });
        el.calendar.querySelectorAll("[data-day-date]").forEach((day) => day.addEventListener("click", (event) => {
            if (event.target.closest("[data-slot]")) return;
            handleSlotClick(day.dataset.dayDate, state.selectedLine, assignmentFor(day.dataset.dayDate, state.selectedLine), state.specialty);
        }));
    }

    function canDragGuardLine(specialty, line, date) {
        const data = getSpecialtyData(specialty);
        return date >= today() && specialty === state.specialty && Boolean(data?.canManage || data?.canEditOtherLines)
            && !(specialty === "OG" && data?.line1Protected && Number(line) === 1);
    }

    function startGuardDrag(event) {
        const button = event.currentTarget;
        state.draggingGuard = { assignmentId: Number(button.dataset.assignment), specialty: button.dataset.specialty, date: button.dataset.date, line: Number(button.dataset.line) };
        button.classList.add("guard-dragging");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", String(button.dataset.assignment));
        button.addEventListener("dragend", () => {
            button.classList.remove("guard-dragging");
            el.calendar.querySelectorAll(".guard-drop-target").forEach((slot) => slot.classList.remove("guard-drop-target"));
            window.setTimeout(() => { state.draggingGuard = null; }, 80);
        }, { once: true });
    }

    function guardDragOver(event) {
        const source = state.draggingGuard;
        const target = event.currentTarget;
        if (!source || target.dataset.specialty !== source.specialty || target.dataset.date !== source.date || Number(target.dataset.line) === source.line || !canDragGuardLine(target.dataset.specialty, Number(target.dataset.line), target.dataset.date)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        target.classList.add("guard-drop-target");
    }

    async function dropGuardLine(event) {
        event.preventDefault();
        const target = event.currentTarget;
        target.classList.remove("guard-drop-target");
        const source = state.draggingGuard;
        if (!source || target.dataset.specialty !== source.specialty || target.dataset.date !== source.date || Number(target.dataset.line) === source.line) return;
        const targetAssignment = assignmentFor(source.date, Number(target.dataset.line), source.specialty);
        const message = targetAssignment
            ? `Schimbi intre ele garzile de pe L${source.line} si L${target.dataset.line}, in aceeasi zi?`
            : `Muti garda de pe L${source.line} pe L${target.dataset.line}, in aceeasi zi?`;
        if (!window.confirm(message)) return;
        await mutate({ action: "move_assignment_line", assignment_id: source.assignmentId, target_line: Number(target.dataset.line) }, true);
    }

    async function handleSlotClick(date, line, assignment, specialty = state.specialty) {
        if (state.busy || date < today()) return;
        if (specialty !== state.specialty) {
            const tab = el.tabs.find((item) => item.dataset.specialty === specialty);
            if (!tab) return;
            await selectSpecialty(tab);
            const currentAssignment = assignment ? state.data.assignments.find((item) => String(item.id) === String(assignment.id)) : null;
            await handleSlotClick(date, line, currentAssignment, specialty);
            return;
        }
        if (state.exchangeSource) {
            if (!isProtectedOgLine1(line) || !assignment) return;
            if (String(assignment.id) === String(state.exchangeSource.id)) { cancelExchange(); return; }
            await mutate({ action: "exchange_guards", source_assignment_id: Number(state.exchangeSource.id), target_assignment_id: Number(assignment.id) });
            return;
        }

        if (state.selectedPersonId && (state.data.canManage || state.data.canEditOtherLines)) {
            // An empty calendar slot chooses only the date; the line comes from the selector above.
            // Occupied slots keep their own line so delete/replace actions still target the clicked guard.
            const selectedLine = assignment ? line : state.selectedLine;
            if (isProtectedOgLine1(selectedLine) && state.data.currentPersonId && !state.data.canManage) {
                if (line === 1 && assignment && state.data.canOwnChanges) openActionDrawer(assignment);
                return;
            }
            const targetAssignment = assignmentFor(date, selectedLine);
            if (targetAssignment && String(targetAssignment.current_person_id) === String(state.selectedPersonId)) {
                if (!canModifyAssignment(targetAssignment)) return;
                await confirmDeleteAssignment(targetAssignment);
                return;
            }
            if (targetAssignment && !canModifyAssignment(targetAssignment)) return;
            if (targetAssignment && String(targetAssignment.current_person_id) !== String(state.selectedPersonId) && !window.confirm(`Inlocuiesti ${targetAssignment.current_name} pe ${lineConfig[selectedLine - 1].label} cu medicul selectat?`)) return;
            await mutate({ action: "save_assignment", date, specialty: state.specialty, line: selectedLine, person_id: Number(state.selectedPersonId) }, true);
            return;
        }

        if (isProtectedOgLine1(line) && state.data.currentPersonId && assignment && state.data.canOwnChanges && !state.data.canManage) {
            openActionDrawer(assignment);
            return;
        }

        if (assignment && !isProtectedOgLine1(line) && canModifyAssignment(assignment)) {
            await confirmDeleteAssignment(assignment);
            return;
        }

        if (state.data.canManage) {
            openDrawer(date, line, assignment);
            return;
        }

        if (state.data.canEditOtherLines && !isProtectedOgLine1(line) && (!assignment || canModifyAssignment(assignment))) {
            openDrawer(date, line, assignment);
            return;
        }

        if (!isProtectedOgLine1(line) || !assignment || !state.data.canOwnChanges) return;
        openActionDrawer(assignment);
    }

    function renderActionBar() {
        if (!state.data) return;
        if (state.exchangeSource) {
            el.selectionStatus.innerHTML = `<strong>Schimb pornit:</strong> ${escapeHtml(formatDate(state.exchangeSource.guard_date))}. Selecteaza o alta garda de pe Linia 1.`;
            el.cancelExchange.hidden = false;
        } else if (isProtectedOgLine1(state.selectedLine) && state.data.currentPersonId && state.data.canEditOtherLines && !state.data.canManage) {
            el.selectionStatus.textContent = "Linia 1 este protejata: click pe garda ta pentru schimb sau pe garda altui medic pentru preluare.";
            el.cancelExchange.hidden = true;
        } else if ((state.data.canManage || state.data.canEditOtherLines) && state.selectedPersonId) {
            const person = state.data.people.find((item) => String(item.id) === String(state.selectedPersonId));
            el.selectionStatus.innerHTML = `<strong>${escapeHtml(person?.display_name || "Medic selectat")}</strong> pe ${lineConfig[state.selectedLine - 1].label}. Apasa zilele dorite; poti continua fara sa reselectionezi medicul.`;
            el.cancelExchange.hidden = true;
        } else if (state.data.canManage) {
            el.selectionStatus.textContent = "Selecteaza un medic din lista, apoi linia si zilele dorite. Click pe o garda fara medic selectat deschide editarea completa.";
            el.cancelExchange.hidden = true;
        } else if (state.data.canEditOtherLines) {
            el.selectionStatus.textContent = state.specialty === "OG" && state.data.line1Protected
                ? `Pe liniile 2-${lineConfig.length} poti adauga, inlocui sau sterge orice garda. OG Linia 1 permite numai schimbul si preluarea.`
                : "Toate liniile acestei specialitati pot fi completate, inlocuite sau sterse direct.";
            el.cancelExchange.hidden = true;
        } else if (state.data.canOwnChanges) {
            el.selectionStatus.textContent = state.specialty === "OG" && state.data.line1Protected
                ? "OG Linia 1 este blocata. Click pe garda ta pentru schimb sau pe garda altui medic pentru preluare."
                : "Selecteaza un medic si apoi ziua dorita pentru a completa aceasta linie.";
            el.cancelExchange.hidden = true;
        } else {
            el.selectionStatus.textContent = "Programul este disponibil doar pentru vizualizare.";
            el.cancelExchange.hidden = true;
        }
        el.undo.hidden = !state.data.lastUndo;
        if (state.data.lastUndo) el.undo.title = `Actiune: ${state.data.lastUndo.action_type}`;
    }

    function renderStats() {
        el.statsTabs.forEach((tab) => tab.classList.toggle("active", tab.dataset.statsView === state.statsView));
        el.personnelView.hidden = state.statsView !== "personal";
        el.statsSummary.hidden = state.statsView !== "summary";
        el.journal.hidden = state.statsView !== "journal";
        const [year, month] = el.month.value.split("-").map(Number);
        const days = new Date(year, month, 0).getDate();
        const lineCounts = countsByLine();
        el.lineStats.innerHTML = lineConfig.map((config) => {
            const count = lineCounts[config.line] || 0;
            return `<div class="stat-line line-${config.line}"><div><strong>${config.label}</strong><span>${count}/${days}</span></div><i><b style="width:${Math.round(count / days * 100)}%"></b></i></div>`;
        }).join("");
        const personCounts = countsByPerson();
        const max = Math.max(1, ...Object.values(personCounts));
        el.personStats.innerHTML = state.data.people.filter((person) => Number(person.active) === 1 && Number(person.guard_eligible) === 1).sort((left, right) => (personCounts[right.id] || 0) - (personCounts[left.id] || 0)).map((person) => {
            const count = personCounts[person.id] || 0;
            return `<button type="button" data-stat-person="${person.id}"><i style="width:${Math.round(count / max * 100)}%"></i><span>${escapeHtml(lastNameFirst(person.display_name))}</span><b>${count}</b></button>`;
        }).join("");
        el.personStats.querySelectorAll("[data-stat-person]").forEach((button) => button.addEventListener("click", () => {
            state.selectedPersonId = button.dataset.statPerson;
            state.statsView = "journal";
            renderPeople(); renderCalendar(); renderStats(); renderActionBar();
        }));
        renderJournal();
    }

    function renderJournal() {
        if (!state.selectedPersonId) {
            el.journal.innerHTML = '<div class="guards-journal-empty"><strong>Selecteaza un medic</strong><span>Alege un nume din lista pentru a vedea situatia garzilor din luna afisata.</span></div>';
            return;
        }
        const person = state.data.people.find((item) => String(item.id) === String(state.selectedPersonId));
        if (!person) {
            el.journal.innerHTML = '<div class="guards-journal-empty">Medicul selectat nu mai este disponibil.</div>';
            return;
        }
        const relevant = state.data.assignments.filter((item) => String(item.current_person_id) === String(person.id) || String(item.original_person_id) === String(person.id));
        const current = relevant.filter((item) => String(item.current_person_id) === String(person.id));
        const taken = current.filter((item) => String(item.original_person_id) !== String(person.id));
        const given = relevant.filter((item) => String(item.original_person_id) === String(person.id) && String(item.current_person_id) !== String(person.id));
        const exchanged = relevant.filter((item) => item.assignment_state === "exchanged");
        const lineCounts = current.reduce((counts, item) => { counts[item.line_no] = (counts[item.line_no] || 0) + 1; return counts; }, {});
        const lineBreakdown = lineConfig.filter((config) => lineCounts[config.line]).map((config) => `<span class="line-${config.line}">${config.short} <b>${lineCounts[config.line]}</b></span>`).join("") || '<span class="empty">Fara garzi curente</span>';
        const rows = relevant.length ? relevant.map((item) => {
            const status = journalStatus(item, person.id);
            const counterpart = String(item.current_person_id) === String(person.id)
                ? (String(item.original_person_id) !== String(person.id) ? `de la ${item.original_name}` : "")
                : `catre ${item.current_name}`;
            return `<article class="guards-journal-item line-${item.line_no}"><i></i><div><strong>${escapeHtml(formatShortDate(item.guard_date))}</strong><span>${state.specialty} / L${item.line_no}${counterpart ? ` - ${escapeHtml(counterpart)}` : ""}</span></div><em class="status-${status.code}">${escapeHtml(status.label)}</em></article>`;
        }).join("") : '<div class="guards-journal-empty compact"><strong>Nicio garda</strong><span>Medicul nu are garzi sau modificari in luna selectata.</span></div>';
        el.journal.innerHTML = `<header class="guards-journal-header"><span>Situatie lunara</span><strong>${escapeHtml(person.display_name)}</strong></header><div class="guards-journal-summary"><div><b>${current.length}</b><span>Curente</span></div><div><b>${taken.length}</b><span>Preluate</span></div><div><b>${given.length}</b><span>Cedate</span></div><div><b>${exchanged.length}</b><span>Schimbate</span></div></div><div class="guards-journal-lines">${lineBreakdown}</div><div class="guards-journal-list">${rows}</div>`;
    }

    function journalStatus(item, personId) {
        const isCurrent = String(item.current_person_id) === String(personId);
        const isOriginal = String(item.original_person_id) === String(personId);
        if (!isCurrent && isOriginal) return { code: item.assignment_state === "exchanged" ? "exchange" : "given", label: item.assignment_state === "exchanged" ? "Schimbata" : "Cedata" };
        if (item.assignment_state === "exchanged") return { code: "exchange", label: "Schimbata" };
        if (isCurrent && !isOriginal) return { code: "taken", label: "Preluata" };
        if (item.assignment_state === "manual_corrected" || item.assignment_state === "admin_corrected") return { code: "corrected", label: "Corectata" };
        return { code: "initial", label: "Initiala" };
    }

    function openDrawer(date, line, assignment) {
        const canEdit = date >= today() && (state.data.canManage || (state.data.canEditOtherLines && !isProtectedOgLine1(line) && (!assignment || canModifyAssignment(assignment))));
        el.title.textContent = `${formatDate(date)} - ${state.specialty} ${lineConfig[line - 1].label}`;
        el.date.value = date;
        el.assignmentId.value = assignment?.id || "";
        el.line.innerHTML = lineConfig.map((config) => `<option value="${config.line}"${config.line === line ? " selected" : ""}>${config.label}</option>`).join("");
        el.person.innerHTML = state.data.people.filter((person) => Number(person.active) === 1 && Number(person.guard_eligible) === 1).map((person) => `<option value="${person.id}"${String(person.id) === String(assignment?.current_person_id) ? " selected" : ""}>${escapeHtml(person.display_name)}</option>`).join("");
        el.line.disabled = !canEdit || !state.data.canManage;
        el.person.disabled = !canEdit;
        el.form.querySelector('[type="submit"]').hidden = !canEdit;
        el.remove.hidden = !canEdit || !assignment;
        el.drawer.classList.add("open");
        el.drawer.setAttribute("aria-hidden", "false");
    }

    function openActionDrawer(assignment) {
        state.actionAssignment = assignment;
        const own = String(assignment.current_person_id) === String(state.data.currentPersonId);
        el.actionTitle.textContent = `${assignment.current_name} - ${formatDate(assignment.guard_date)}`;
        el.actionDescription.textContent = own
            ? "Aceasta garda iti apartine. O poti schimba cu alta garda de pe Linia 1 sau o poti ceda unui coleg."
            : "Aceasta garda apartine altui medic. O poti prelua direct, fara aprobare.";
        el.startExchange.hidden = !own;
        el.transfer.hidden = !own;
        el.takeover.hidden = own;
        showGuardActionMenu();
        el.actionDrawer.classList.add("open");
        el.actionDrawer.setAttribute("aria-hidden", "false");
    }

    function startExchange() {
        beginGuardAction("exchange");
    }

    function takeoverGuard() {
        beginGuardAction("takeover");
    }

    function beginGuardAction(mode) {
        const assignment = state.actionAssignment;
        if (!assignment) return;
        state.actionMode = mode;
        el.actionMenu.hidden = true;
        el.actionFlow.hidden = false;
        el.actionSelectLabel.hidden = mode === "takeover";

        if (mode === "exchange") {
            const options = state.data.assignments
                .filter((item) => Number(item.line_no) === 1
                    && item.guard_date >= today()
                    && String(item.id) !== String(assignment.id)
                    && String(item.current_person_id) !== String(state.data.currentPersonId))
                .sort((left, right) => left.guard_date.localeCompare(right.guard_date));
            el.actionFlowTitle.textContent = "Schimba garda";
            el.actionSelectLabel.firstChild.textContent = "Garda cu care faci schimb ";
            el.actionSelect.innerHTML = options.length
                ? options.map((item) => `<option value="${item.id}">${escapeHtml(formatShortDate(item.guard_date))} - ${escapeHtml(item.current_name)}</option>`).join("")
                : '<option value="">Nu exista alte garzi disponibile</option>';
            el.actionSelect.disabled = !options.length;
            el.actionConfirm.disabled = !options.length;
            el.actionConfirm.textContent = "Confirma schimbul";
            el.actionConfirmation.innerHTML = `<strong>Garda ta:</strong><span>${escapeHtml(formatDate(assignment.guard_date))}</span><small>Medicii si zilele celor doua garzi vor fi inversate.</small>`;
        } else if (mode === "transfer") {
            const people = state.data.people.filter((person) => Number(person.active) === 1
                && Number(person.guard_eligible) === 1
                && String(person.id) !== String(state.data.currentPersonId));
            el.actionFlowTitle.textContent = "Cedeaza garda";
            el.actionSelectLabel.firstChild.textContent = "Medicul care primeste garda ";
            el.actionSelect.innerHTML = people.length
                ? people.map((person) => `<option value="${person.id}">${escapeHtml(person.display_name)}</option>`).join("")
                : '<option value="">Nu exista medici disponibili</option>';
            el.actionSelect.disabled = !people.length;
            el.actionConfirm.disabled = !people.length;
            el.actionConfirm.textContent = "Confirma cedarea";
            el.actionConfirmation.innerHTML = `<strong>Cedezi garda din:</strong><span>${escapeHtml(formatDate(assignment.guard_date))}</span><small>Garda va fi transferata medicului selectat si actiunea va fi jurnalizata.</small>`;
        } else {
            el.actionFlowTitle.textContent = "Preia garda";
            el.actionConfirm.disabled = false;
            el.actionConfirm.textContent = "Confirma preluarea";
            el.actionConfirmation.innerHTML = `<strong>Preiei de la ${escapeHtml(assignment.current_name)}:</strong><span>${escapeHtml(formatDate(assignment.guard_date))}</span><small>Preluarea se face fara aprobare si va fi jurnalizata.</small>`;
        }
    }

    function showGuardActionMenu() {
        state.actionMode = null;
        el.actionMenu.hidden = false;
        el.actionFlow.hidden = true;
        el.actionConfirm.disabled = false;
    }

    async function confirmGuardAction() {
        const assignment = state.actionAssignment;
        if (!assignment || !state.actionMode) return;
        if (state.actionMode === "exchange") {
            if (!el.actionSelect.value) return;
            await mutate({ action: "exchange_guards", source_assignment_id: Number(assignment.id), target_assignment_id: Number(el.actionSelect.value) });
        } else if (state.actionMode === "transfer") {
            if (!el.actionSelect.value) return;
            await mutate({ action: "transfer_guard", assignment_id: Number(assignment.id), target_person_id: Number(el.actionSelect.value) });
        } else if (state.actionMode === "takeover") {
            await mutate({ action: "takeover_guard", assignment_id: Number(assignment.id) });
        }
    }

    function openGuardExport() {
        el.exportForm.querySelectorAll('[name="specialties"]').forEach((input) => { input.checked = input.value === state.specialty; });
        el.exportDrawer.classList.add("open");
        el.exportDrawer.setAttribute("aria-hidden", "false");
    }

    function closeGuardExport() {
        el.exportDrawer.classList.remove("open");
        el.exportDrawer.setAttribute("aria-hidden", "true");
    }

    function downloadGuardExport(event) {
        event.preventDefault();
        const specialties = [...el.exportForm.querySelectorAll('[name="specialties"]:checked')].map((input) => input.value);
        if (!specialties.length) { window.alert("Selecteaza cel putin o specialitate."); return; }
        const nameFormat = el.exportForm.querySelector('[name="name_format"]')?.value || "full";
        const query = new URLSearchParams({ month: el.month.value, specialties: specialties.join(","), format: "pdf", name_format: nameFormat });
        window.location.href = `api/guard-export.php?${query.toString()}`;
        closeGuardExport();
    }

    function cancelExchange() {
        state.exchangeSource = null;
        if (state.data) { renderCalendar(); renderActionBar(); }
    }

    async function undoLastAction() {
        if (!state.data.lastUndo || !window.confirm("Anulezi ultima modificare facuta de tine la garzi?")) return;
        await mutate({ action: "undo_guard_action" });
    }

    async function saveGuard(event) {
        event.preventDefault();
        await mutate({ action: "save_assignment", date: el.date.value, specialty: state.specialty, line: Number(el.line.value), person_id: Number(el.person.value) });
    }

    async function deleteGuard() {
        if (!el.assignmentId.value || !window.confirm("Stergi aceasta garda din linia selectata?")) return;
        await mutate({ action: "delete_assignment", assignment_id: Number(el.assignmentId.value) });
    }

    async function confirmDeleteAssignment(assignment) {
        const lineLabel = lineConfig.find((config) => Number(config.line) === Number(assignment.line_no))?.label || `L${assignment.line_no}`;
        if (!window.confirm(`Stergi garda lui ${assignment.current_name} din ${formatDate(assignment.guard_date)}, ${lineLabel}?`)) return;
        await mutate({ action: "delete_assignment", assignment_id: Number(assignment.id) }, true);
    }

    async function mutate(payload, keepPlannerSelection = false) {
        if (state.busy) return;
        state.busy = true;
        root.classList.add("is-busy");
        try {
            const response = await fetch("api/guards.php", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(payload) });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || "Modificarea nu a putut fi salvata.");
            closeDrawer();
            closeActionDrawer();
            state.exchangeSource = null;
            await load();
            setStatus(data.message || "Modificarea a fost salvata.");
        } catch (error) {
            setStatus(error.message, true);
            window.alert(error.message);
        } finally {
            state.busy = false;
            root.classList.remove("is-busy");
        }
    }

    async function selectSpecialty(tab) {
        state.specialty = tab.dataset.specialty;
        state.selectedPersonId = null;
        state.exchangeSource = null;
        el.tabs.forEach((item) => item.classList.toggle("active", item === tab));
        await load();
    }
    function closeDrawer() { el.drawer.classList.remove("open"); el.drawer.setAttribute("aria-hidden", "true"); }
    function closeActionDrawer() {
        el.actionDrawer.classList.remove("open");
        el.actionDrawer.setAttribute("aria-hidden", "true");
        state.actionAssignment = null;
        state.actionMode = null;
        el.actionMenu.hidden = false;
        el.actionFlow.hidden = true;
    }
    function shiftMonth(delta) {
        const [y, m] = el.month.value.split("-").map(Number);
        const date = new Date(y, m - 1 + delta, 1);
        el.month.value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
        state.exchangeSource = null;
        load();
    }
    function countsByLine() { return state.data.assignments.reduce((counts, item) => { counts[item.line_no] = (counts[item.line_no] || 0) + 1; return counts; }, {}); }
    function countsByPerson() { return state.data.assignments.reduce((counts, item) => { counts[item.current_person_id] = (counts[item.current_person_id] || 0) + 1; return counts; }, {}); }
    function assignmentFor(date, line, specialty = state.specialty) { return (getSpecialtyData(specialty)?.assignments || []).find((item) => item.guard_date === date && Number(item.line_no) === Number(line)) || null; }
    function canModifyAssignment(assignment) { return Boolean(state.data.canManage || Number(assignment?.can_user_modify) === 1); }
    function isProtectedOgLine1(line, specialty = state.specialty, data = state.data) { return specialty === "OG" && Boolean(data?.line1Protected) && Number(line) === 1; }
    function isResident(person) { return /rez/i.test(person.professional_category || ""); }
    function initials(name) { return String(name).split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase(); }
    function shortName(name) { return String(name || "").trim(); }
    function lastNameFirst(name) { return String(name || "").trim(); }
    function weekdayShort(value) { return new Intl.DateTimeFormat("ro-RO", { weekday: "short" }).format(new Date(`${value}T12:00:00`)).replace(".", ""); }
    function today() { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`; }
    function formatDate(value) { return new Intl.DateTimeFormat("ro-RO", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${value}T12:00:00`)); }
    function formatShortDate(value) { return new Intl.DateTimeFormat("ro-RO", { weekday: "short", day: "numeric", month: "short" }).format(new Date(`${value}T12:00:00`)).replace(".", ""); }
    function groupBy(items, key) { return items.reduce((result, item) => { (result[item[key]] ||= []).push(item); return result; }, {}); }
    function setStatus(message, error = false) { el.status.textContent = message; el.status.classList.toggle("error", error); }
    function escapeHtml(value) { return String(value ?? "").replace(/[&<>'"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" })[char]); }

    load();
})();
