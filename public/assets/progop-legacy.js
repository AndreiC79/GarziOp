const API_URL = "api/progop-legacy.php";
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || "";
const IMPORT_API_URL = "api/progop-import.php";
let ROOM_NAMES = ["Sala 1", "Sala 2", "Sala 3", "Sala 4"];
const MONTH_NAMES = [
    "Ianuarie", "Februarie", "Martie", "Aprilie", "Mai", "Iunie",
    "Iulie", "August", "Septembrie", "Octombrie", "Noiembrie", "Decembrie"
];
const WEEKDAY_NAMES = ["L", "Ma", "Mi", "J", "V", "S", "D"];

const requestedDate = new URLSearchParams(window.location.search).get("date");
const initialDate = /^\d{4}-\d{2}-\d{2}$/.test(requestedDate || "") ? requestedDate : formatDate(new Date());
const state = {
    settings: null,
    capabilities: {
        currentUserId: 0,
        currentPersonId: 0,
        currentPersonName: "",
        canCreate: false,
        canEditOwn: false,
        canManageAll: false,
        canAssignRooms: false,
        canManageSettings: false,
        canScheduleOwn: false,
        canReviewExtra: false
    },
    months: {},
    guardsByDate: {},
    atiAssignmentsByDate: {},
    selectedDate: initialDate,
    currentYear: Number(initialDate.slice(0, 4)),
    currentMonth: Number(initialDate.slice(5, 7)) - 1,
    exportMode: "day",
    exportFormat: "pdf"
};

{
    const initialParts = parseDateParts(state.selectedDate);
    state.currentYear = initialParts.year;
    state.currentMonth = initialParts.month;
}

const elements = {};

document.addEventListener("DOMContentLoaded", async () => {
    cacheElements();
    bindMobileViewport();
    renderWeekdays();
    bindTopbarActions();
    populateSelectors();
    try {
        await preloadVisibleMonths();
        renderAll();
        if (new URLSearchParams(window.location.search).get("openStage") === "1" && state.capabilities.currentIsResident) openResidentStageModal();
    } catch (error) {
        console.error(error);
        setStatus(error.message || "Nu am putut încărca datele.", "error");
        toast(error.message || "Nu am putut încărca datele.");
    }
});

function bindMobileViewport() {
    document.documentElement.classList.toggle("embedded-module", window.self !== window.top);
    const update = () => {
        const viewport = window.visualViewport;
        document.documentElement.style.setProperty("--visible-viewport-height", `${viewport?.height || window.innerHeight}px`);
        document.documentElement.style.setProperty("--visible-viewport-top", `${viewport?.offsetTop || 0}px`);
    };
    update();
    window.addEventListener("resize", update, { passive: true });
    window.visualViewport?.addEventListener("resize", update, { passive: true });
    window.visualViewport?.addEventListener("scroll", update, { passive: true });
}

function cacheElements() {
    elements.yearSelect = document.getElementById("yearSelect");
    elements.monthSelect = document.getElementById("monthSelect");
    elements.calendarGrid = document.getElementById("calendarGrid");
    elements.calendarWeekdays = document.getElementById("calendarWeekdays");
    elements.selectedDateLabel = document.getElementById("selectedDateLabel");
    elements.scheduleTitle = document.getElementById("scheduleTitle");
    elements.roomsContainer = document.getElementById("roomsContainer");
    elements.schedulingQueues = document.getElementById("schedulingQueues");
    elements.saveStatus = document.getElementById("saveStatus");
    elements.modalOverlay = document.getElementById("modalOverlay");
    elements.modalRoot = document.getElementById("modalRoot");
    elements.statTotal = document.getElementById("statTotal");
    elements.statRooms = document.getElementById("statRooms");
    elements.statTypes = document.getElementById("statTypes");
    elements.statDoctors = document.getElementById("statDoctors");
    elements.statGuards = document.getElementById("statGuards");
    elements.statBlockedRooms = document.getElementById("statBlockedRooms");
    elements.scheduleOwnBtn = document.getElementById("scheduleOwnBtn");
    elements.residentStageBtn = document.getElementById("residentStageBtn");
    elements.unassignedBtn = document.getElementById("unassignedBtn");
    elements.atiScheduleBtn = document.getElementById("atiScheduleBtn");
    elements.patientSearchBtn = document.getElementById("patientSearchBtn");
    elements.reloadBtn = document.getElementById("reloadBtn");
    elements.backupBtn = document.getElementById("backupBtn");
    elements.manageTypesBtn = document.getElementById("manageTypesBtn");
    elements.importBtn = document.getElementById("importBtn");
    elements.exportBtn = document.getElementById("exportBtn");
    elements.overviewBtn = document.getElementById("overviewBtn");
}

function bindTopbarActions() {
    elements.yearSelect.addEventListener("change", async () => {
        try {
            state.currentYear = Number(elements.yearSelect.value);
            syncSelectedDateToCurrentView();
            await preloadVisibleMonths();
            renderAll();
        } catch (error) {
            console.error(error);
            toast(error.message || "Nu am putut schimba luna.");
        }
    });

    elements.monthSelect.addEventListener("change", async () => {
        try {
            state.currentMonth = Number(elements.monthSelect.value);
            syncSelectedDateToCurrentView();
            await preloadVisibleMonths();
            renderAll();
        } catch (error) {
            console.error(error);
            toast(error.message || "Nu am putut schimba luna.");
        }
    });

    elements.scheduleOwnBtn.addEventListener("click", () => openSurgeryRequestModal());
    elements.residentStageBtn.addEventListener("click", openResidentStageModal);
    elements.unassignedBtn.addEventListener("click", openUnassignedOperationsModal);
    elements.atiScheduleBtn.addEventListener("click", openAtiScheduleModal);
    elements.patientSearchBtn.addEventListener("click", openPatientSearchModal);
    elements.reloadBtn.addEventListener("click", async () => {
        state.months = {};
        state.guardsByDate = {};
        await preloadVisibleMonths(true);
        renderAll();
    });
    elements.backupBtn.addEventListener("click", createBackup);
    elements.manageTypesBtn.addEventListener("click", () => openListManager("operationTypes"));
    elements.importBtn.addEventListener("click", openImportModal);
    elements.exportBtn.addEventListener("click", openExportModal);
    elements.overviewBtn.addEventListener("click", openOverviewWindow);
    elements.modalOverlay.addEventListener("click", closeModal);
}

function populateSelectors() {
    const baseYear = new Date().getFullYear();
    const years = [];
    for (let year = baseYear - 3; year <= baseYear + 5; year += 1) {
        years.push(year);
    }

    elements.yearSelect.innerHTML = years.map((year) => `<option value="${year}">${year}</option>`).join("");
    elements.monthSelect.innerHTML = MONTH_NAMES.map((month, index) => `<option value="${index}">${month}</option>`).join("");
    elements.yearSelect.value = String(state.currentYear);
    elements.monthSelect.value = String(state.currentMonth);
}

async function preloadVisibleMonths(force = false) {
    const currentMonthKey = monthKeyFromParts(state.currentYear, state.currentMonth);
    const currentDate = new Date(state.currentYear, state.currentMonth, 1);
    const prevKey = monthKeyFromDate(formatDate(new Date(state.currentYear, state.currentMonth - 1, 1)));
    const nextKey = monthKeyFromDate(formatDate(new Date(state.currentYear, state.currentMonth + 1, 1)));
    await Promise.all([
        loadMonth(currentMonthKey, force),
        loadMonth(prevKey, force),
        loadMonth(nextKey, force)
    ]);
    elements.yearSelect.value = String(currentDate.getFullYear());
    elements.monthSelect.value = String(currentDate.getMonth());
}

async function loadMonth(monthKey, force = false) {
    if (!force && state.months[monthKey]) {
        return;
    }
    setStatus("Se încarcă datele...", "busy");
    const response = await fetch(`${API_URL}?action=load&month=${encodeURIComponent(monthKey)}`, { cache: "no-store" });
    const result = await response.json();
    if (!result.success) {
        throw new Error(result.message || "Nu am putut încărca datele.");
    }
    updateStateFromPayload(result.data);
    setStatus(`Date sincronizate. Ultima actualizare: ${formatDateTime(result.data?.meta?.updatedAt)}`, "");
}

function updateStateFromPayload(data) {
    if (!data) {
        return;
    }
    const incomingSettings = data.settings || state.settings || {};
    if (Array.isArray(incomingSettings.rooms) && incomingSettings.rooms.length) {
        ROOM_NAMES = incomingSettings.rooms.map(String);
    }
    state.settings = normalizeSettings(incomingSettings);
    state.capabilities = { ...state.capabilities, ...(data.capabilities || {}) };
    applyCapabilities();
    const incomingMonths = data.months || {};
    for (const [monthKey, monthData] of Object.entries(incomingMonths)) {
        state.months[monthKey] = normalizeMonthData(monthData, monthKey);
    }
    const incomingGuards = data.guardsByDate || {};
    for (const [dateKey, assignments] of Object.entries(incomingGuards)) {
        state.guardsByDate[dateKey] = normalizeGuardAssignments(assignments);
    }
    for (const [dateKey, assignments] of Object.entries(data.atiAssignmentsByDate || {})) {
        state.atiAssignmentsByDate[dateKey] = assignments || {};
    }
}

function normalizeSettings(settings) {
    return {
        rooms: ROOM_NAMES.slice(),
        doctors: Array.isArray(settings.doctors) ? settings.doctors : [],
        doctorOptions: Array.isArray(settings.doctorOptions) ? settings.doctorOptions.map((doctor) => ({
            id: Number(doctor.id) || 0,
            name: String(doctor.name || ""),
            resident: Boolean(doctor.resident)
        })).filter((doctor) => doctor.id && doctor.name) : [],
        atiDoctorOptions: Array.isArray(settings.atiDoctorOptions) ? settings.atiDoctorOptions.map((doctor) => ({ id: Number(doctor.id), name: String(doctor.name || "") })).filter((doctor) => doctor.id && doctor.name) : [],
        residentStages: Array.isArray(settings.residentStages) ? settings.residentStages : [],
        operationTypes: Array.isArray(settings.operationTypes) && settings.operationTypes.length
            ? settings.operationTypes
            : ["Laparoscopie", "Operație deschisă", "Cezariană", "Operație vaginală", "Histeroscopie"]
    };
}

function normalizeMonthData(monthData, monthKey) {
    const normalized = structuredClone(monthData || {});
    normalized.meta = normalized.meta || { month: monthKey };
    normalized.schedule = normalized.schedule || {};
    for (const [dateKey, day] of Object.entries(normalized.schedule)) {
        day.rooms = day.rooms || {};
        day.pendingInterventions = Array.isArray(day.pendingInterventions) ? day.pendingInterventions.map(normalizeIntervention) : [];
        day.extraInterventions = Array.isArray(day.extraInterventions) ? day.extraInterventions.map(normalizeIntervention) : [];
        ROOM_NAMES.forEach((room) => {
            if (!day.rooms[room]) {
                day.rooms[room] = defaultRoomState();
            } else {
                day.rooms[room].blocked = Boolean(day.rooms[room].blocked);
                day.rooms[room].blockReason = day.rooms[room].blockReason || "";
                day.rooms[room].interventions = Array.isArray(day.rooms[room].interventions) ? day.rooms[room].interventions : [];
                day.rooms[room].interventions = day.rooms[room].interventions.map(normalizeIntervention);
            }
        });
        normalized.schedule[dateKey] = day;
    }
    return normalized;
}

function normalizeIntervention(item) {
    return {
        id: item.id,
        patientName: item.patientName || "",
        diagnostic: item.diagnostic || "",
        operationType: item.operationType || "",
        doctor: item.doctor || "",
        doctorId: Number(item.doctorId) || 0,
        customNotes: item.customNotes || item.notes || "",
        completed: Boolean(item.completed),
        completedAt: item.completedAt || "",
        extra: Boolean(item.extra),
        extraReason: item.extraReason || "",
        createdAt: item.createdAt || "",
        updatedAt: item.updatedAt || "",
        createdBy: Number(item.createdBy) || 0,
        roomName: item.roomName || "",
        displayOrder: Number(item.displayOrder) || 0,
        approvalState: item.approvalState || (item.extra ? "approved" : "none"),
        approvalLabel: item.approvalLabel || (item.extra ? "EXTRA" : "")
    };
}

function applyCapabilities() {
    elements.manageTypesBtn.hidden = !state.capabilities.canManageSettings;
    elements.backupBtn.hidden = !state.capabilities.canManageAll;
    elements.importBtn.hidden = !state.capabilities.canCreate;
    elements.scheduleOwnBtn.hidden = !state.capabilities.canScheduleOwn;
    elements.residentStageBtn.hidden = !state.capabilities.currentIsResident;
    elements.unassignedBtn.hidden = !state.capabilities.canAssignRooms;
    elements.atiScheduleBtn.hidden = !state.capabilities.canAssignRooms;
}

function defaultRoomState() {
    return {
        blocked: false,
        blockReason: "",
        interventions: []
    };
}

function normalizeGuardAssignments(assignments) {
    if (!Array.isArray(assignments)) {
        return [];
    }
    return assignments
        .map((assignment) => ({
            personId: Number(assignment?.personId) || 0,
            personName: String(assignment?.personName || "").trim(),
            line: Number(assignment?.line) || 0,
            specialty: String(assignment?.specialty || "").trim(),
            weekend: Boolean(assignment?.weekend)
        }))
        .filter((assignment) => assignment.personName)
        .sort(compareGuardAssignments);
}

function compareGuardAssignments(left, right) {
    const specialtyOrder = { ATI: 0, OG: 1, NN: 2 };
    if (left.specialty !== right.specialty) return (specialtyOrder[left.specialty] ?? 9) - (specialtyOrder[right.specialty] ?? 9);
    if (left.line !== right.line) {
        return left.line - right.line;
    }
    return left.personName.localeCompare(right.personName, "ro");
}

function renderWeekdays() {
    elements.calendarWeekdays.innerHTML = WEEKDAY_NAMES.map((day) => `<div class="weekday">${day}</div>`).join("");
}

function renderAll() {
    renderCalendar();
    renderStats();
    renderRooms();
}

function renderCalendar() {
    const first = new Date(state.currentYear, state.currentMonth, 1);
    const firstDay = (first.getDay() + 6) % 7;
    const gridStart = new Date(state.currentYear, state.currentMonth, 1 - firstDay);
    elements.calendarGrid.innerHTML = "";

    for (let index = 0; index < 42; index += 1) {
        const current = new Date(gridStart);
        current.setDate(gridStart.getDate() + index);
        const dateKey = formatDate(current);
        const dayData = getDayDataSafe(dateKey);
        const dayInterventions = getAllDayInterventions(dayData);
        const interventionCount = dayInterventions.length;
        const myInterventionCount = dayInterventions.filter((item) => item.doctorId === state.capabilities.currentPersonId).length;
        const onCall = getGuardAssignmentsForDate(dateKey).some((assignment) => assignment.personId === state.capabilities.currentPersonId);
        const blockedCount = ROOM_NAMES.filter((room) => dayData.rooms[room].blocked).length;
        const extraCount = dayData.extraInterventions.length
            + ROOM_NAMES.reduce((sum, room) => sum + dayData.rooms[room].interventions.filter((item) => item.extra).length, 0);
        const unassignedCount = dayData.pendingInterventions.length + dayData.extraInterventions.length;
        const pendingApprovalCount = state.capabilities.canReviewExtra ? dayInterventions.filter((item) => item.extra && item.approvalState === "pending").length : 0;

        const button = document.createElement("button");
        button.type = "button";
        button.className = [
            "calendar-day",
            current.getMonth() !== state.currentMonth ? "other-month" : "",
            dateKey === state.selectedDate ? "selected" : "",
            dateKey === formatDate(new Date()) ? "today" : "",
            interventionCount > 0 ? "has-events" : "",
            myInterventionCount > 0 ? "has-my-events" : "",
            onCall ? "on-call-day" : "",
            blockedCount > 0 ? "blocked-day" : "",
            extraCount > 0 ? "extra-day" : "",
            unassignedCount > 0 ? "unassigned-day" : "",
            pendingApprovalCount > 0 ? "approval-day" : ""
        ].filter(Boolean).join(" ");
        button.innerHTML = `
            <span class="day-number">${current.getDate()}</span>
            <span class="day-ratio">${interventionCount}/${myInterventionCount}</span>
        `;
        button.title = `${interventionCount} operatii, ${myInterventionCount} ale tale${onCall ? ", esti de garda" : ""}${unassignedCount ? `, ${unassignedCount} de repartizat` : ""}${pendingApprovalCount ? `, ${pendingApprovalCount} de aprobat` : ""}`;
        button.addEventListener("click", async () => {
            state.selectedDate = dateKey;
            const parts = parseDateParts(dateKey);
            state.currentYear = parts.year;
            state.currentMonth = parts.month;
            elements.yearSelect.value = String(parts.year);
            elements.monthSelect.value = String(parts.month);
            await preloadVisibleMonths();
            renderAll();
        });
        elements.calendarGrid.appendChild(button);
    }
}

function renderStats() {
    const dayData = getDayDataSafe(state.selectedDate);
    const guards = getGuardAssignmentsForDate(state.selectedDate);
    const roomCounts = {};
    const typeCounts = {};
    const doctorCounts = {};
    const blockedRooms = [];
    let total = 0;

    ROOM_NAMES.forEach((room) => {
        const roomState = dayData.rooms[room];
        roomCounts[room] = roomState.interventions.length;
        total += roomState.interventions.length;
        if (roomState.blocked) {
            blockedRooms.push(`${room}${roomState.blockReason ? ` · ${roomState.blockReason}` : ""}`);
        }
        roomState.interventions.forEach((item) => {
            typeCounts[item.operationType] = (typeCounts[item.operationType] || 0) + 1;
            doctorCounts[item.doctor] = (doctorCounts[item.doctor] || 0) + 1;
        });
    });
    [...dayData.pendingInterventions, ...dayData.extraInterventions].forEach((item) => {
        total += 1;
        typeCounts[item.operationType] = (typeCounts[item.operationType] || 0) + 1;
        doctorCounts[item.doctor] = (doctorCounts[item.doctor] || 0) + 1;
    });

    elements.selectedDateLabel.textContent = formatFriendlyDate(state.selectedDate);
    elements.scheduleTitle.textContent = `Programarea pentru ${formatFriendlyDate(state.selectedDate)}`;
    elements.statTotal.textContent = String(total);
    renderTagCollection(elements.statRooms, roomCounts, "Nu există intervenții pe săli.");
    renderTagCollection(elements.statTypes, typeCounts, "Nu există tipuri de operații în această zi.");
    renderTagCollection(elements.statDoctors, doctorCounts, "Nu există medici alocați în această zi.");
    renderGuardCollection(elements.statGuards, guards, "Nu exista garda importata in aceasta zi.");

    elements.statBlockedRooms.innerHTML = blockedRooms.length
        ? blockedRooms.map((text) => `<div class="list-item">${escapeHtml(text)}</div>`).join("")
        : `<div class="inline-note">Nu există săli blocate în ziua selectată.</div>`;
}

function renderTagCollection(container, dataMap, emptyText) {
    const entries = Object.entries(dataMap).filter(([, value]) => value > 0);
    if (entries.length === 0) {
        container.innerHTML = `<div class="inline-note">${escapeHtml(emptyText)}</div>`;
        return;
    }
    container.innerHTML = entries.map(([key, value]) => `<span class="tag">${escapeHtml(key)}: ${value}</span>`).join("");
}

function renderGuardCollection(container, assignments, emptyText) {
    if (!assignments.length) {
        container.innerHTML = `<div class="inline-note">${escapeHtml(emptyText)}</div>`;
        return;
    }
    container.innerHTML = assignments.map((assignment) => `
        <div class="guard-entry specialty-${escapeHtml(String(assignment.specialty || "").toLowerCase())}">
            <span class="guard-line">${escapeHtml(buildGuardLineLabel(assignment))}</span>
            <strong>${escapeHtml(assignment.personName)}</strong>
        </div>
    `).join("");
}

function renderRooms() {
    const dayData = getDayDataSafe(state.selectedDate);
    renderSchedulingQueues(dayData);
    const roomTemplate = document.getElementById("roomTemplate");
    const interventionTemplate = document.getElementById("interventionTemplate");
    elements.roomsContainer.innerHTML = "";

    ROOM_NAMES.forEach((roomName) => {
        const roomState = dayData.rooms[roomName];
        const fragment = roomTemplate.content.cloneNode(true);
        const card = fragment.querySelector(".room-card");
        const title = fragment.querySelector(".room-title");
        const subtitle = fragment.querySelector(".room-subtitle");
        const ati = fragment.querySelector(".room-ati");
        const blockBtn = fragment.querySelector(".room-block-btn");
        const addBtn = fragment.querySelector(".add-intervention-btn");
        const alertBox = fragment.querySelector(".room-alert");
        const list = fragment.querySelector(".interventions-list");
        list.dataset.dropRoom = roomName;

        blockBtn.hidden = !state.capabilities.canManageAll;
        addBtn.hidden = !state.capabilities.canManageAll;
        addBtn.textContent = "Adaugă direct în sală";

        title.textContent = roomName;
        const completedCount = roomState.interventions.filter((item) => item.completed).length;
        subtitle.textContent = `${completedCount}/${roomState.interventions.length} efectuate`;
        const atiNames = getAtiNamesForRoom(state.selectedDate, roomName);
        ati.innerHTML = `<span>ATI</span><strong>${atiNames.length ? atiNames.map(escapeHtml).join(" · ") : "Nerepartizat"}</strong>`;

        if (roomState.blocked) {
            card.classList.add("blocked");
            blockBtn.textContent = "Deblochează sala";
            addBtn.disabled = true;
            alertBox.classList.remove("hidden");
            alertBox.textContent = roomState.interventions.length > 0
                ? `Sala este blocată: ${roomState.blockReason || "fără motiv specificat"}. Există deja intervenții programate și ar trebui mutate.`
                : `Sala este blocată: ${roomState.blockReason || "fără motiv specificat"}.`;
        } else {
            blockBtn.textContent = "Blochează sala";
        }

        blockBtn.addEventListener("click", () => {
            if (roomState.blocked) {
                toggleRoomBlock(roomName, false);
            } else {
                openBlockRoomModal(roomName);
            }
        });

        addBtn.addEventListener("click", () => openInterventionModal({ mode: "add", roomName }));

        if (roomState.interventions.length === 0) {
            const empty = document.createElement("div");
            empty.className = "empty-room";
            empty.textContent = roomState.blocked
                ? "Sala este blocată și momentan nu poate primi intervenții noi."
                : "Nu există intervenții programate în această sală.";
            list.appendChild(empty);
        } else {
            roomState.interventions.forEach((item, index) => {
                const interventionNode = interventionTemplate.content.cloneNode(true);
                const article = interventionNode.querySelector(".intervention-card");
                if (item.doctorId === state.capabilities.currentPersonId) {
                    article.classList.add("my-intervention");
                }
                if (item.extra) {
                    article.classList.add("extra-intervention");
                    if (item.approvalState !== "approved") article.classList.add("approval-pending");
                }
                if (item.completed) {
                    article.classList.add("completed-intervention");
                }

                interventionNode.querySelector(".patient-name").textContent = item.patientName;
                interventionNode.querySelector(".intervention-diagnostic").textContent = item.diagnostic || "Diagnostic necompletat";
                interventionNode.querySelector(".intervention-order").textContent = `#${index + 1}`;

                const metaItems = [
                    `<span class="pill">${escapeHtml(item.operationType || "Tip nedefinit")}</span>`,
                    `<span class="pill">${escapeHtml(item.doctor || "Medic nealocat")}</span>`
                ];
                if (item.extra) {
                    metaItems.push(`<span class="pill pill-extra">${escapeHtml(item.approvalLabel || "EXTRA")}</span>`);
                }
                if (item.completed) {
                    metaItems.push(`<span class="pill pill-success">Efectuată</span>`);
                }
                interventionNode.querySelector(".intervention-meta").innerHTML = metaItems.join("");
                interventionNode.querySelector(".intervention-notes").textContent = buildNotesText(item);

                const canEditItem = state.capabilities.canManageAll
                    || (state.capabilities.canEditOwn && item.createdBy === state.capabilities.currentUserId);
                article.dataset.dragOperation = item.id;
                article.dataset.dragRoom = roomName;
                article.dataset.dragPosition = String(index + 1);
                article.draggable = canEditItem;
                if (canEditItem) {
                    article.addEventListener("dragstart", startProgramOperationDrag);
                    article.addEventListener("dragend", endProgramOperationDrag);
                }
                interventionNode.querySelector(".intervention-actions").hidden = !canEditItem;

                interventionNode.querySelector(".edit-btn").addEventListener("click", () => {
                    openInterventionModal({ mode: "edit", roomName, interventionId: item.id });
                });
                interventionNode.querySelector(".delete-btn").addEventListener("click", () => {
                    deleteIntervention(roomName, item.id);
                });
                interventionNode.querySelector(".move-btn").addEventListener("click", () => {
                    openMoveModal(roomName, item.id);
                });
                interventionNode.querySelector(".move-up-btn").addEventListener("click", () => {
                    reorderIntervention(roomName, item.id, "up");
                });
                interventionNode.querySelector(".move-down-btn").addEventListener("click", () => {
                    reorderIntervention(roomName, item.id, "down");
                });
                if (state.capabilities.canAssignRooms) {
                    const unassignButton = document.createElement("button");
                    unassignButton.type = "button";
                    unassignButton.className = "ghost-btn";
                    unassignButton.textContent = item.extra ? "Înapoi la EXTRA" : "Înapoi la repartizat";
                    unassignButton.addEventListener("click", () => unassignSurgeryRoom(item.id));
                    interventionNode.querySelector(".intervention-actions").appendChild(unassignButton);
                }

                list.appendChild(interventionNode);
            });
        }

        list.addEventListener("dragover", programRoomDragOver);
        list.addEventListener("dragleave", programRoomDragLeave);
        list.addEventListener("drop", dropProgramOperation);

        elements.roomsContainer.appendChild(fragment);
    });
}

function startProgramOperationDrag(event) {
    const card = event.currentTarget;
    card.classList.add("dragging");
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("application/x-intern-operation", JSON.stringify({
        id: Number(card.dataset.dragOperation),
        room: card.dataset.dragRoom,
        position: Number(card.dataset.dragPosition)
    }));
}

function endProgramOperationDrag(event) {
    event.currentTarget.classList.remove("dragging");
    document.querySelectorAll(".interventions-list.drag-target").forEach((list) => list.classList.remove("drag-target"));
}

function programRoomDragOver(event) {
    if (!event.dataTransfer.types.includes("application/x-intern-operation")) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    event.currentTarget.classList.add("drag-target");
}

function programRoomDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) event.currentTarget.classList.remove("drag-target");
}

async function dropProgramOperation(event) {
    event.preventDefault();
    const list = event.currentTarget;
    list.classList.remove("drag-target");
    let transfer;
    try { transfer = JSON.parse(event.dataTransfer.getData("application/x-intern-operation")); } catch { return; }
    const targetCard = event.target.closest("[data-drag-operation]");
    const position = targetCard ? Number(targetCard.dataset.dragPosition) : list.querySelectorAll("[data-drag-operation]").length + 1;
    const toRoom = list.dataset.dropRoom;
    if (!transfer?.id || !toRoom || (transfer.room === toRoom && transfer.position === position)) return;
    try {
        await apiMutation("moveIntervention", {
            interventionId: transfer.id,
            toDate: state.selectedDate,
            toRoom,
            position
        }, `Operația a fost mutată în ${toRoom}.`);
    } catch (error) {
        console.error(error);
    }
}

function renderSchedulingQueues(dayData) {
    const pending = [...dayData.pendingInterventions].sort(compareSchedulingChronology);
    const extras = [...dayData.extraInterventions].sort(compareSchedulingChronology);
    const sections = [];
    if (pending.length) {
        sections.push(`
            <section class="scheduling-queue pending-queue">
                <header><div><p class="eyebrow">În ordinea programării</p><h3>De repartizat pe săli</h3></div><strong>${pending.length}</strong></header>
                <p class="queue-explanation">Prima programare introdusă are prioritate. Lista este ordonată după data și ora salvării.</p>
                <div class="scheduling-list">${pending.map((item, index) => schedulingQueueCard(item, index, false)).join("")}</div>
            </section>`);
    }
    if (extras.length) {
        sections.push(`
            <section class="scheduling-queue extra-queue">
                <header><div><p class="eyebrow">Săptămâna curentă</p><h3>Operații EXTRA</h3></div><strong>${extras.length}</strong></header>
                <p class="queue-explanation">Aceste programări sunt urgente și pot fi repartizate pe sală de asistente sau de medicii ATI.</p>
                <div class="scheduling-list">${extras.map((item, index) => schedulingQueueCard(item, index, true)).join("")}</div>
            </section>`);
    }
    elements.schedulingQueues.innerHTML = sections.join("");
    elements.schedulingQueues.querySelectorAll("[data-assign-room]").forEach((button) => button.addEventListener("click", () => assignSurgeryRoom(button.dataset.interventionId, button.dataset.assignRoom)));
    elements.schedulingQueues.querySelectorAll("[data-edit-request]").forEach((button) => button.addEventListener("click", () => {
        const item = [...pending, ...extras].find((entry) => String(entry.id) === button.dataset.editRequest);
        if (item) openSurgeryRequestModal(item);
    }));
    elements.schedulingQueues.querySelectorAll("[data-delete-request]").forEach((button) => button.addEventListener("click", () => deleteIntervention("", button.dataset.deleteRequest)));
}

function schedulingQueueCard(item, index, isExtra) {
    const canEdit = state.capabilities.canManageAll || (state.capabilities.canEditOwn && item.createdBy === state.capabilities.currentUserId);
    const roomButtons = state.capabilities.canAssignRooms
        ? `<div class="room-choice" aria-label="Repartizează pe sală">${ROOM_NAMES.map((room) => `<button type="button" class="secondary-btn" data-assign-room="${room}" data-intervention-id="${item.id}">${room}</button>`).join("")}</div>`
        : "";
    return `
        <article class="scheduling-row${isExtra ? " is-extra" : ""}${isExtra && item.approvalState !== "approved" ? " approval-pending" : ""}${item.doctorId === state.capabilities.currentPersonId ? " my-intervention" : ""}">
            <span class="seniority-number">${index + 1}</span>
            <div class="scheduling-person"><strong>${escapeHtml(item.patientName)}</strong><span>${escapeHtml(item.operationType || "Tip nespecificat")} · ${escapeHtml(item.doctor)}</span>${item.diagnostic ? `<small>Diagnostic: ${escapeHtml(item.diagnostic)}</small>` : ""}${item.customNotes ? `<small>Observații: ${escapeHtml(item.customNotes)}</small>` : ""}</div>
            <div class="scheduling-time"><span>Programată la</span><strong>${escapeHtml(formatDateTime(item.createdAt))}</strong></div>
            ${isExtra ? `<span class="extra-badge">${escapeHtml(item.approvalLabel || "EXTRA")}</span>${roomButtons}` : roomButtons}
            ${canEdit ? `<div class="queue-actions"><button type="button" class="ghost-btn" data-edit-request="${item.id}">Editează</button><button type="button" class="danger-btn" data-delete-request="${item.id}">Șterge</button></div>` : ""}
        </article>`;
}

function compareSchedulingChronology(left, right) {
    return String(left.createdAt).localeCompare(String(right.createdAt)) || Number(left.id) - Number(right.id);
}

function openPatientSearchModal() {
    const modal = createModal({
        title: "Cauta o pacienta programata",
        subtitle: "Cautarea include toate programarile, indiferent de medic sau data."
    });
    modal.body.innerHTML = `
        <div class="modal-stack patient-search-modal">
            <label><span>Nume pacienta, diagnostic sau observatie</span><input id="patientGlobalSearch" minlength="2" autocomplete="off" autofocus placeholder="Scrie cel putin 2 caractere"></label>
            <div id="patientSearchResults" class="patient-search-results"><p>Introdu numele pacientei pentru a cauta.</p></div>
            <div class="modal-actions"><button type="button" class="ghost-btn" data-close-modal>Inchide</button></div>
        </div>`;
    const input = modal.body.querySelector("#patientGlobalSearch");
    const results = modal.body.querySelector("#patientSearchResults");
    let timer = null;
    input.addEventListener("input", () => {
        clearTimeout(timer);
        const query = input.value.trim();
        if (query.length < 2) {
            results.innerHTML = "<p>Scrie cel putin 2 caractere.</p>";
            return;
        }
        timer = setTimeout(async () => {
            results.innerHTML = "<p>Se cauta...</p>";
            try {
                const response = await fetch(`${API_URL}?action=searchPatients&q=${encodeURIComponent(query)}`, { cache: "no-store" });
                const payload = await response.json();
                if (!payload.success) throw new Error(payload.message || "Cautarea nu a putut fi efectuata.");
                const rows = payload.data?.results || [];
                results.innerHTML = rows.length ? rows.map((row) => `
                    <button type="button" class="patient-search-row" data-patient-date="${escapeAttribute(row.scheduled_date)}">
                        <strong>${escapeHtml(row.patient_name)}</strong>
                        <span>${escapeHtml(formatFriendlyDate(row.scheduled_date))} · ${escapeHtml(row.doctor_name)} · ${escapeHtml(row.operation_type || "Tip nespecificat")}</span>
                        <small>${escapeHtml(row.room_name)}${row.diagnostic ? ` · ${escapeHtml(row.diagnostic)}` : ""}</small>
                    </button>`).join("") : "<p>Nu am gasit nicio programare.</p>";
                results.querySelectorAll("[data-patient-date]").forEach((button) => button.addEventListener("click", async () => {
                    const date = button.dataset.patientDate;
                    state.selectedDate = date;
                    const parts = parseDateParts(date);
                    state.currentYear = parts.year;
                    state.currentMonth = parts.month;
                    closeModal();
                    await preloadVisibleMonths();
                    renderAll();
                }));
            } catch (error) {
                results.innerHTML = `<p class="search-error">${escapeHtml(error.message)}</p>`;
            }
        }, 250);
    });
}

function openResidentStageModal() {
    const currentId = Number(state.capabilities.currentPersonId);
    const stage = state.settings.residentStages.find((item) => Number(item.person_id || 0) === currentId) || {};
    const modal = createModal({ title: "Stagiul meu extern", subtitle: "Intervalul se aplică după data operației și poate fi actualizat oricând." });
    modal.body.innerHTML = `<form class="modal-stack" id="residentStageForm"><div class="modal-grid">
        <label><span>Început stagiu</span><input type="date" name="startDate" value="${escapeAttribute(stage.start_date || "")}" required></label>
        <label><span>Sfârșit stagiu</span><input type="date" name="endDate" value="${escapeAttribute(stage.end_date || "")}" required></label>
        <label class="full"><span>Spital / stagiu</span><input name="hospital" maxlength="220" value="${escapeAttribute(stage.hospital || "")}" placeholder="Opțional"></label>
        <label class="full"><span>Observații</span><textarea name="notes" maxlength="500">${escapeHtml(stage.notes || "")}</textarea></label>
        </div><div class="request-rule-note">În acest interval poți programa operații în zilele tale de gardă. În celelalte zile trebuie bifată confirmarea că operația este după ora 14:00.</div>
        <div class="modal-actions"><button type="button" class="ghost-btn" data-close-modal>Anulează</button><button type="submit" class="primary-btn">Salvează intervalul</button></div></form>`;
    modal.body.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault(); const data = new FormData(event.currentTarget);
        const result = await apiMutation("saveMyResidentStage", { startDate:String(data.get("startDate")||""),endDate:String(data.get("endDate")||""),hospital:String(data.get("hospital")||"").trim(),notes:String(data.get("notes")||"").trim() }, null, { skipRender:true });
        closeModal(); await preloadVisibleMonths(true); renderAll(); toast(result.message || "Perioada de stagiu a fost salvată.");
    });
}

async function openUnassignedOperationsModal() {
    const modal=createModal({title:"Operații de repartizat",subtitle:"Toate programările viitoare, în ordinea datei și a momentului introducerii."});
    modal.body.innerHTML='<div class="modal-stack"><p>Se încarcă...</p></div>';
    try {
        const response=await fetch(`${API_URL}?action=unassignedOperations`,{cache:"no-store"});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||"Lista nu a putut fi încărcată.");
        const rows=result.data?.operations||[];const rooms=result.data?.rooms||ROOM_NAMES;
        modal.body.innerHTML=`<div class="modal-stack unassigned-all-list">${rows.length?rows.map((row,index)=>`<article class="scheduling-row unassigned-dialog-row${row.is_extra?" is-extra":""}" data-unassigned-row="${row.id}"><span class="seniority-number" title="Ordinea programării">${index+1}</span><div class="scheduling-person"><strong>${escapeHtml(row.patient_name)}</strong><div class="unassigned-facts"><span><b>Data operației</b>${escapeHtml(formatFriendlyDate(row.scheduled_date))}</span><span><b>Operație</b>${escapeHtml(row.operation_type||"Tip nespecificat")}</span><span><b>Medic</b>${escapeHtml(row.doctor_name)}</span>${row.diagnostic?`<span class="wide"><b>Diagnostic</b>${escapeHtml(row.diagnostic)}</span>`:""}</div><small>Programată la ${escapeHtml(formatDateTime(row.created_at))}${row.is_extra?` · ${escapeHtml(row.approval_label||"EXTRA")}`:""}</small></div><div class="unassigned-room-actions"><span>Alege sala</span><div class="room-choice">${rooms.map((room)=>`<button type="button" class="secondary-btn" data-global-assign="${escapeAttribute(room)}" data-operation-id="${row.id}">${escapeHtml(room)}</button>`).join("")}</div></div></article>`).join(""):'<div class="inline-note">Nu există operații de repartizat.</div>'}<div class="modal-actions"><button type="button" class="ghost-btn" data-close-modal>Închide</button></div></div>`;
        modal.body.querySelectorAll("[data-global-assign]").forEach((button)=>button.addEventListener("click",async()=>{await apiMutation("assignSurgeryRoom",{interventionId:Number(button.dataset.operationId),room:button.dataset.globalAssign},null,{skipRender:true});button.closest("[data-unassigned-row]")?.remove();await preloadVisibleMonths(true);renderAll();toast(`Operația a fost repartizată în ${button.dataset.globalAssign}.`);}));
    } catch(error){modal.body.innerHTML=`<div class="modal-stack"><p class="search-error">${escapeHtml(error.message)}</p><button type="button" class="ghost-btn" data-close-modal>Închide</button></div>`;}
}

function openSurgeryRequestModal(item = null) {
    const isEdit = Boolean(item);
    const modal = createModal({
        title: isEdit ? "Editează programarea" : "Adaugă programare",
        subtitle: "Programarea va fi repartizată ulterior pe sală de către asistentă."
    });
    modal.modal.classList.add("surgery-request-modal");
    const headerSubmit = document.createElement("button");
    headerSubmit.type = "submit";
    headerSubmit.className = "primary-btn mobile-modal-submit";
    headerSubmit.setAttribute("form", "surgeryRequestForm");
    headerSubmit.textContent = isEdit ? "Salveaz\u0103" : "Adaug\u0103";
    modal.modal.querySelector(".modal-header")?.appendChild(headerSubmit);
    const defaultDoctorId = item?.doctorId || state.capabilities.currentPersonId;
    const doctorOptions = state.settings.doctorOptions
        .map((doctor) => `<option value="${doctor.id}" ${doctor.id === defaultDoctorId ? "selected" : ""}>${escapeHtml(doctor.name)}</option>`)
        .join("");
    const typeOptions = state.settings.operationTypes
        .map((type) => `<option value="${escapeAttribute(type)}" ${item?.operationType === type ? "selected" : ""}>${escapeHtml(type)}</option>`)
        .join("");
    const selectedDate = state.selectedDate < formatDate(new Date()) ? formatDate(new Date()) : state.selectedDate;

    modal.body.innerHTML = `
        <form class="modal-stack" id="surgeryRequestForm">
            <div class="modal-grid">
                <label><span>Data operației</span><input type="date" name="date" min="${formatDate(new Date())}" value="${escapeAttribute(selectedDate)}" required></label>
                <label><span>Medic operator</span><select name="doctorId" required><option value="">Alege medicul</option>${doctorOptions}</select></label>
                <label class="full"><span>Nume pacientă</span><input name="patientName" maxlength="220" value="${escapeAttribute(item?.patientName || "")}" required></label>
                <label class="full"><span>Diagnostic</span><input name="diagnostic" maxlength="2000" value="${escapeAttribute(item?.diagnostic || "")}"></label>
                <label class="full"><span>Tip operație</span><select name="operationType" required><option value="">Alege tipul operației</option>${typeOptions}</select></label>
                <label class="full"><span>Observații</span><textarea name="customNotes" maxlength="4000">${escapeHtml(item?.customNotes || "")}</textarea></label>
                <label class="full resident-after-hours" id="residentAfterHours" hidden><input type="checkbox" name="after14Confirmed" value="1"><span>Confirm că operația este programată după ora 14:00</span></label>
            </div>
            <div class="request-rule-note">Programările pentru săptămâna curentă sunt acceptate ca <strong>operații EXTRA</strong>. Apar gri cât timp așteaptă aprobarea; după aprobare apar cu roșu.</div>
            <div class="modal-actions">
                <button type="button" class="ghost-btn" data-close-modal>Anulează</button>
                <button type="submit" class="primary-btn">${isEdit ? "Salvează modificările" : "Salvează programarea"}</button>
            </div>
        </form>`;

    const requestForm = modal.body.querySelector("form");
    const doctorSelect = requestForm.elements.doctorId;
    const dateInput = requestForm.elements.date;
    const afterHours = requestForm.querySelector("#residentAfterHours");
    const updateResidentRule = () => {
        const doctorId = Number(doctorSelect.value);
        const doctor = state.settings.doctorOptions.find((option) => option.id === doctorId);
        const date = dateInput.value;
        const inExternalStage = Boolean(doctor?.resident && state.settings.residentStages.some((stage) =>
            (Number(stage.person_id || 0) === doctorId || String(stage.display_name || "").toLocaleLowerCase("ro") === String(doctor.name || "").toLocaleLowerCase("ro"))
            && date >= String(stage.start_date || "") && date <= String(stage.end_date || "")));
        afterHours.hidden = !inExternalStage;
        if (!inExternalStage) afterHours.querySelector("input").checked = false;
    };
    doctorSelect.addEventListener("change", updateResidentRule);
    dateInput.addEventListener("change", updateResidentRule);
    updateResidentRule();
    requestForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const date = String(formData.get("date") || "");
        const payload = {
            date,
            interventionId: item?.id || undefined,
            intervention: {
                patientName: String(formData.get("patientName") || "").trim(),
                operationType: String(formData.get("operationType") || "").trim(),
                doctorId: Number(formData.get("doctorId")) || 0,
                diagnostic: String(formData.get("diagnostic") || "").trim(),
                customNotes: String(formData.get("customNotes") || "").trim(),
                after14Confirmed: formData.get("after14Confirmed") === "1"
            }
        };
        if (!payload.intervention.patientName || !payload.intervention.operationType || !payload.intervention.doctorId) {
            toast("Completează data, pacienta, tipul operației și medicul.");
            return;
        }
        try {
            const result = await apiMutation(
                isEdit ? "editSurgeryRequest" : "addSurgeryRequest",
                payload,
                null,
                { skipRender: true }
            );
            closeModal();
            state.selectedDate = date;
            const parts = parseDateParts(date);
            state.currentYear = parts.year;
            state.currentMonth = parts.month;
            elements.yearSelect.value = String(parts.year);
            elements.monthSelect.value = String(parts.month);
            await preloadVisibleMonths();
            renderAll();
            toast(result.message || "Programarea a fost salvată.");
        } catch (error) {
            console.error(error);
        }
    });
}

async function assignSurgeryRoom(interventionId, room) {
    await apiMutation("assignSurgeryRoom", { interventionId: Number(interventionId), room }, `Programarea a fost repartizată în ${room}.`);
}

async function unassignSurgeryRoom(interventionId) {
    if (!window.confirm("Retrimiți programarea în lista de repartizat?")) return;
    await apiMutation("unassignSurgeryRoom", { interventionId: Number(interventionId) }, "Programarea a revenit în lista de repartizat.");
}

function getDayDataSafe(dateKey) {
    const monthKey = monthKeyFromDate(dateKey);
    const month = state.months[monthKey];
    if (!month || !month.schedule[dateKey]) {
        return {
            rooms: Object.fromEntries(ROOM_NAMES.map((room) => [room, defaultRoomState()])),
            pendingInterventions: [],
            extraInterventions: []
        };
    }
    return month.schedule[dateKey];
}

function getAllDayInterventions(dayData) {
    return [
        ...ROOM_NAMES.flatMap((room) => dayData.rooms[room].interventions),
        ...dayData.pendingInterventions,
        ...dayData.extraInterventions
    ];
}

function getGuardAssignmentsForDate(dateKey) {
    return state.guardsByDate[dateKey] || [];
}

function getAtiNamesForRoom(dateKey, roomName) {
    const ids = state.atiAssignmentsByDate?.[dateKey]?.[roomName] || [];
    const names = new Map((state.settings?.atiDoctorOptions || []).map((doctor) => [Number(doctor.id), doctor.name]));
    return ids.map((id) => names.get(Number(id))).filter(Boolean);
}

function mondayFor(dateKey) {
    const parts = parseDateParts(dateKey);
    const date = new Date(parts.year, parts.month, parts.day);
    const offset = (date.getDay() + 6) % 7;
    date.setDate(date.getDate() - offset);
    return formatDate(date);
}

function openAtiScheduleModal() {
    const weekStart = mondayFor(state.selectedDate);
    const start = parseDateParts(weekStart);
    const dates = Array.from({ length: 7 }, (_, index) => formatDate(new Date(start.year, start.month, start.day + index)));
    const doctors = state.settings.atiDoctorOptions || [];
    const optionHtml = (selected) => `<option value="">Nerepartizat</option>${doctors.map((doctor) => `<option value="${doctor.id}"${Number(selected) === doctor.id ? " selected" : ""}>${escapeHtml(doctor.name)}</option>`).join("")}`;
    const modal = createModal({ title: "Repartizare săptămânală ATI", subtitle: `${formatDisplayDate(dates[0])} - ${formatDisplayDate(dates[6])} · maximum doi medici pe sală` });
    modal.modal.classList.add("ati-schedule-modal");
    modal.body.innerHTML = `
        <form id="atiScheduleForm" class="modal-stack">
            <div class="ati-week-toolbar"><button type="button" class="secondary-btn" data-copy-ati-week>Copiază săptămâna precedentă</button><button type="button" class="ghost-btn" data-clear-ati-week>Golește săptămâna</button></div>
            <div class="ati-week-grid">
                ${dates.map((date) => `<section class="ati-day-card" data-ati-date="${date}"><header><strong>${escapeHtml(formatFriendlyDate(date))}</strong><button type="button" class="ghost-btn" data-copy-ati-day="${date}">Copiază ziua anterioară</button></header>${ROOM_NAMES.map((room) => { const values = state.atiAssignmentsByDate?.[date]?.[room] || []; return `<div class="ati-room-row"><b>${escapeHtml(room)}</b><select data-ati-room="${escapeAttribute(room)}" data-slot="0">${optionHtml(values[0])}</select><select data-ati-room="${escapeAttribute(room)}" data-slot="1">${optionHtml(values[1])}</select></div>`; }).join("")}</section>`).join("")}
            </div>
            <div class="modal-actions"><button type="button" class="ghost-btn" data-close-modal>Anulează</button><button type="submit" class="primary-btn">Salvează repartizarea ATI</button></div>
        </form>`;
    const form = modal.body.querySelector("#atiScheduleForm");
    const setDay = (date, source) => ROOM_NAMES.forEach((room) => [0, 1].forEach((slot) => { const target = form.querySelector(`[data-ati-date="${date}"] [data-ati-room="${CSS.escape(room)}"][data-slot="${slot}"]`); const sourceValue = source?.[room]?.[slot] || ""; if (target) target.value = String(sourceValue); }));
    form.querySelectorAll("[data-copy-ati-day]").forEach((button) => button.addEventListener("click", () => { const date = button.dataset.copyAtiDay; const index = dates.indexOf(date); if (index > 0) { const source = collectAtiDay(form, dates[index - 1]); setDay(date, source); } }));
    form.querySelector("[data-clear-ati-week]").addEventListener("click", () => form.querySelectorAll("select").forEach((select) => { select.value = ""; }));
    form.querySelector("[data-copy-ati-week]").addEventListener("click", () => dates.forEach((date) => { const previous = parseDateParts(date); setDay(date, state.atiAssignmentsByDate[formatDate(new Date(previous.year, previous.month, previous.day - 7))] || {}); }));
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const assignments = Object.fromEntries(dates.map((date) => [date, collectAtiDay(form, date)]));
        await apiMutation("saveAtiWeek", { weekStart, assignments }, "Repartizarea ATI a fost salvată.");
        closeModal();
    });
}

function collectAtiDay(form, date) {
    const day = {};
    ROOM_NAMES.forEach((room) => {
        const ids = [...form.querySelectorAll(`[data-ati-date="${date}"] [data-ati-room="${CSS.escape(room)}"]`)].map((select) => Number(select.value)).filter(Boolean);
        if (ids.length) day[room] = [...new Set(ids)].slice(0, 2);
    });
    return day;
}

function syncSelectedDateToCurrentView() {
    const parts = parseDateParts(state.selectedDate);
    const maxDay = new Date(state.currentYear, state.currentMonth + 1, 0).getDate();
    const day = Math.min(parts.day, maxDay);
    state.selectedDate = formatDate(new Date(state.currentYear, state.currentMonth, day));
}

function openInterventionModal({ mode, roomName, interventionId = null }) {
    const intervention = getDayDataSafe(state.selectedDate).rooms[roomName].interventions.find((item) => item.id === interventionId) || null;
    const isEdit = mode === "edit";
    const modal = createModal({
        title: isEdit ? "Editează intervenția" : `Adaugă intervenție · ${roomName}`,
        subtitle: `Zi selectată: ${formatFriendlyDate(state.selectedDate)}`
    });

    const typeOptions = state.settings.operationTypes
        .map((type) => `<option value="${escapeAttribute(type)}" ${intervention?.operationType === type ? "selected" : ""}>${escapeHtml(type)}</option>`)
        .join("");
    const doctorOptions = state.settings.doctors
        .map((doctor) => `<option value="${escapeAttribute(doctor)}" ${intervention?.doctor === doctor ? "selected" : ""}>${escapeHtml(doctor)}</option>`)
        .join("");

    modal.body.innerHTML = `
        <form class="modal-stack" id="interventionForm">
            <div class="modal-grid">
                <label>
                    <span>Nume pacient</span>
                    <input name="patientName" required maxlength="120" value="${escapeAttribute(intervention?.patientName || "")}">
                </label>
                <label>
                    <span>Diagnostic</span>
                    <input name="diagnostic" maxlength="180" value="${escapeAttribute(intervention?.diagnostic || "")}">
                </label>
                <label>
                    <span>Tip operație</span>
                    <select name="operationType" required>
                        <option value="">Alege tipul operației</option>
                        ${typeOptions}
                    </select>
                </label>
                <label>
                    <span>Medic operator</span>
                    <select name="doctor" required>
                        <option value="">Alege medicul operator</option>
                        ${doctorOptions}
                    </select>
                </label>
                <label class="full">
                    <span>Observații libere</span>
                    <textarea name="customNotes" maxlength="1000">${escapeHtml(intervention?.customNotes || "")}</textarea>
                </label>
            </div>
            ${intervention?.extra ? `<div class="fixed-note">Marcaj fix: EXTRA · ${escapeHtml(intervention.extraReason || "adăugată ulterior")}</div>` : ""}
            <div class="modal-actions">
                <button type="button" class="ghost-btn" data-close-modal>Anulează</button>
                <button type="submit" class="primary-btn">${isEdit ? "Salvează modificările" : "Adaugă intervenția"}</button>
            </div>
        </form>
    `;

    modal.body.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const payload = {
            patientName: formData.get("patientName")?.toString().trim(),
            diagnostic: formData.get("diagnostic")?.toString().trim(),
            operationType: formData.get("operationType")?.toString().trim(),
            doctor: formData.get("doctor")?.toString().trim(),
            customNotes: formData.get("customNotes")?.toString().trim()
        };

        if (!payload.patientName || !payload.operationType || !payload.doctor) {
            toast("Completează pacientul, tipul operației și medicul operator.");
            return;
        }

        if (isEdit) {
            await apiMutation("editIntervention", {
                date: state.selectedDate,
                room: roomName,
                interventionId,
                intervention: payload
            }, "Intervenția a fost actualizată.");
        } else {
            await apiMutation("addIntervention", {
                date: state.selectedDate,
                room: roomName,
                intervention: payload
            }, "Intervenția a fost adăugată.");
        }
        closeModal();
    });
}

function openMoveModal(roomName, interventionId) {
    const intervention = getDayDataSafe(state.selectedDate).rooms[roomName].interventions.find((item) => item.id === interventionId);
    if (!intervention) {
        toast("Intervenția nu mai există în listă.");
        return;
    }

    const modal = createModal({
        title: "Mută / Reprogramează",
        subtitle: `${intervention.patientName} · ${roomName} · ${formatFriendlyDate(state.selectedDate)}`
    });

    modal.body.innerHTML = `
        <form class="modal-stack" id="moveForm">
            <div class="modal-grid">
                <label>
                    <span>Data nouă</span>
                    <input type="date" name="newDate" required value="${state.selectedDate}">
                    <small class="date-hint">${escapeHtml(formatDisplayDate(state.selectedDate))}</small>
                </label>
                <label>
                    <span>Sala nouă</span>
                    <select name="newRoom" required>
                        ${ROOM_NAMES.map((room) => `<option value="${room}" ${room === roomName ? "selected" : ""}>${room}</option>`).join("")}
                    </select>
                </label>
                <label>
                    <span>Poziție</span>
                    <input type="number" name="position" min="1" placeholder="Lasă gol pentru final">
                </label>
            </div>
            <p class="inline-note">Dacă lași poziția goală, intervenția va fi adăugată la finalul listei din sala aleasă.</p>
            <div class="modal-actions">
                <button type="button" class="ghost-btn" data-close-modal>Anulează</button>
                <button type="submit" class="primary-btn">Aplică mutarea</button>
            </div>
        </form>
    `;

    const dateInput = modal.body.querySelector('input[name="newDate"]');
    const dateHint = modal.body.querySelector(".date-hint");
    dateInput.addEventListener("input", () => {
        if (dateInput.value) {
            dateHint.textContent = formatDisplayDate(dateInput.value);
        }
    });

    modal.body.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        const newDate = formData.get("newDate")?.toString();
        const newRoom = formData.get("newRoom")?.toString();
        const positionText = formData.get("position")?.toString().trim();
        await apiMutation("moveIntervention", {
            interventionId,
            fromDate: state.selectedDate,
            fromRoom: roomName,
            toDate: newDate,
            toRoom: newRoom,
            position: positionText ? Number(positionText) : null
        }, "Intervenția a fost mutată.");
        state.selectedDate = newDate;
        const parts = parseDateParts(newDate);
        state.currentYear = parts.year;
        state.currentMonth = parts.month;
        await preloadVisibleMonths();
        closeModal();
    });
}

function openBlockRoomModal(roomName) {
    const modal = createModal({
        title: `Blochează ${roomName}`,
        subtitle: `Zi selectată: ${formatFriendlyDate(state.selectedDate)}`
    });

    modal.body.innerHTML = `
        <form class="modal-stack" id="blockRoomForm">
            <label>
                <span>Motivul blocării</span>
                <textarea name="reason" required maxlength="500" placeholder="Ex.: revizie aparatură, igienizare, indisponibilitate personal..."></textarea>
            </label>
            <div class="modal-actions">
                <button type="button" class="ghost-btn" data-close-modal>Anulează</button>
                <button type="submit" class="primary-btn">Blochează sala</button>
            </div>
        </form>
    `;

    modal.body.querySelector("form").addEventListener("submit", async (event) => {
        event.preventDefault();
        const reason = new FormData(event.currentTarget).get("reason")?.toString().trim();
        if (!reason) {
            toast("Introdu motivul blocării.");
            return;
        }
        await apiMutation("blockRoom", { date: state.selectedDate, room: roomName, reason }, "Sala a fost blocată.");
        closeModal();
    });
}

async function toggleRoomBlock(roomName, blocked) {
    await apiMutation(blocked ? "blockRoom" : "unblockRoom", {
        date: state.selectedDate,
        room: roomName
    }, blocked ? "Sala a fost blocată." : "Sala a fost deblocată.");
}

async function deleteIntervention(roomName, interventionId) {
    if (!window.confirm("Ștergi această intervenție?")) {
        return;
    }
    await apiMutation("deleteIntervention", {
        date: state.selectedDate,
        room: roomName,
        interventionId
    }, "Intervenția a fost ștearsă.");
}

async function reorderIntervention(roomName, interventionId, direction) {
    await apiMutation("reorderIntervention", {
        date: state.selectedDate,
        room: roomName,
        interventionId,
        direction
    }, "Ordinea intervențiilor a fost actualizată.");
}

function openListManager(type) {
    const config = {
        doctors: {
            title: "Gestionează medici",
            subtitle: "Lista se salvează permanent și apare imediat în dropdown-urile intervențiilor.",
            key: "doctors",
            singular: "medicul"
        },
        operationTypes: {
            title: "Gestionează tipuri de operații",
            subtitle: "Tipurile noi vor apărea imediat în formularele de intervenție.",
            key: "operationTypes",
            singular: "tipul"
        }
    }[type];

    const modal = createModal({ title: config.title, subtitle: config.subtitle });
    modal.modal.classList.add("list-manager-modal");
    modal.body.innerHTML = `
        <div class="modal-stack">
            <form id="addListItemForm" class="modal-grid">
                <label class="full">
                    <span>Adaugă element nou</span>
                    <input name="value" required maxlength="120" placeholder="Introduceți denumirea">
                </label>
                <div class="modal-actions full">
                    <button type="submit" class="primary-btn">Adaugă</button>
                </div>
            </form>
            <div class="item-list" id="managedItemList"></div>
        </div>
    `;

    const listNode = modal.body.querySelector("#managedItemList");
    let localItems = [...(state.settings[config.key] || [])];
    let renderPageOnClose = false;
    modal.modal.addEventListener("modal:close", () => {
        if (renderPageOnClose) {
            renderAll();
        }
    }, { once: true });

    const syncItemsFromResult = (result, fallbackValue = null, mode = "replace") => {
        const serverItems = result?.data?.settings?.[config.key];
        if (Array.isArray(serverItems)) {
            localItems = [...serverItems];
            state.settings[config.key] = [...serverItems];
            return;
        }

        if (fallbackValue === null) {
            return;
        }

        if (mode === "add" && !localItems.includes(fallbackValue)) {
            localItems = [...localItems, fallbackValue];
        } else if (mode === "rename" && Array.isArray(fallbackValue) && fallbackValue.length === 2) {
            localItems = localItems.map((item) => item === fallbackValue[0] ? fallbackValue[1] : item);
        } else if (mode === "delete") {
            localItems = localItems.filter((item) => item !== fallbackValue);
        }
        state.settings[config.key] = [...localItems];
    };

    const renderManagedList = (items = localItems, focusItem = null) => {
        localItems = [...items];
        if (localItems.length === 0) {
            listNode.innerHTML = `<div class="inline-note">Lista este goală momentan.</div>`;
            return;
        }
        listNode.innerHTML = "";
        localItems.forEach((item) => {
            const row = document.createElement("div");
            row.className = "item-row";
            if (item === focusItem) {
                row.classList.add("item-row-new");
            }
            row.innerHTML = `
                <strong>${escapeHtml(item)}</strong>
                <div class="item-row-actions">
                    <button type="button" class="secondary-btn">Redenumește</button>
                    <button type="button" class="danger-btn">Șterge</button>
                </div>
            `;
            const [renameBtn, deleteBtn] = row.querySelectorAll("button");
            renameBtn.addEventListener("click", async () => {
                const nextValue = window.prompt(`Denumire nouă pentru ${config.singular}:`, item);
                if (!nextValue || nextValue.trim() === item) {
                    return;
                }
                const trimmed = nextValue.trim();
                const previousItems = [...localItems];
                syncItemsFromResult(null, [item, trimmed], "rename");
                renderManagedList();
                try {
                    const result = await apiMutation(type === "doctors" ? "updateDoctor" : "updateOperationType", {
                        oldValue: item,
                        newValue: trimmed
                    }, "Lista a fost actualizată.", { skipRender: true });
                    syncItemsFromResult(result, [item, trimmed], "rename");
                    renderPageOnClose = true;
                } catch (error) {
                    localItems = previousItems;
                    state.settings[config.key] = [...previousItems];
                    renderManagedList();
                    return;
                }
                renderManagedList();
            });
            deleteBtn.addEventListener("click", async () => {
                if (!window.confirm(`Ștergi ${config.singular} "${item}"?`)) {
                    return;
                }
                const previousItems = [...localItems];
                syncItemsFromResult(null, item, "delete");
                renderManagedList();
                try {
                    const result = await apiMutation(type === "doctors" ? "deleteDoctor" : "deleteOperationType", {
                        value: item
                    }, "Elementul a fost șters.", { skipRender: true });
                    syncItemsFromResult(result, item, "delete");
                } catch (error) {
                    localItems = previousItems;
                    state.settings[config.key] = [...previousItems];
                    renderManagedList();
                    return;
                }
                renderManagedList();
            });
            listNode.appendChild(row);
        });
        if (focusItem) {
            const focusedRow = listNode.querySelector(".item-row-new");
            focusedRow?.scrollIntoView({ block: "nearest" });
            window.setTimeout(() => focusedRow?.classList.remove("item-row-new"), 1800);
        }
    };

    modal.body.querySelector("#addListItemForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        const input = new FormData(event.currentTarget).get("value")?.toString().trim();
        if (!input) {
            return;
        }
        if (localItems.includes(input)) {
            toast("Elementul există deja în listă.");
            return;
        }
        const previousItems = [...localItems];
        syncItemsFromResult(null, input, "add");
        event.currentTarget.reset();
        renderManagedList(localItems, input);
        try {
            const result = await apiMutation(type === "doctors" ? "addDoctor" : "addOperationType", { value: input }, "Elementul a fost adăugat.", { skipRender: true });
            syncItemsFromResult(result, input, "add");
            renderManagedList(localItems, input);
        } catch (error) {
            localItems = previousItems;
            state.settings[config.key] = [...previousItems];
            renderManagedList();
        }
    });

    renderManagedList();
}

function openExportModal() {
    const nextWeek = getNextWeekRange(state.selectedDate);
    const modal = createModal({
        title: "Export program",
        subtitle: "Poți genera un raport PDF, inclusiv rapid pentru săptămâna următoare."
    });

    modal.body.innerHTML = `
        <div class="modal-stack">
            <div class="export-mode-grid">
                ${[
                    ["day", "Zi selectată", "Exportă doar ziua curentă."],
                    ["month", "Luna selectată", "Exportă toate zilele din lună."],
                    ["nextWeek", "Săptămâna următoare", `Luni-Duminică: ${formatShortDate(nextWeek.start)} - ${formatShortDate(nextWeek.end)}`],
                    ["range", "Interval date", "Exportă între două date."],
                    ["room", "Pe sală", "Filtrare după sală."],
                    ["doctor", "Pe medic", "Filtrare după medic."],
                    ["all", "Toate datele", "Export complet."],
                    ["blocked", "Săli blocate", "Doar zile și săli blocate."]
                ].map(([value, label, description]) => `
                    <button type="button" class="export-mode-btn ${state.exportMode === value ? "active" : ""}" data-export-mode="${value}">
                        ${label}
                        <span>${description}</span>
                    </button>
                `).join("")}
            </div>
            <form id="exportForm" class="modal-grid">
                <label>
                    <span>Format export</span>
                    <select name="format" disabled>
                        <option value="pdf" selected>PDF (.pdf)</option>
                    </select>
                </label>
                <label>
                    <span>Data început</span>
                    <input type="date" name="startDate" value="${state.selectedDate}">
                    <small class="date-hint">${escapeHtml(formatDisplayDate(state.selectedDate))}</small>
                </label>
                <label>
                    <span>Data sfârșit</span>
                    <input type="date" name="endDate" value="${state.selectedDate}">
                    <small class="date-hint">${escapeHtml(formatDisplayDate(state.selectedDate))}</small>
                </label>
                <label>
                    <span>Sală</span>
                    <select name="room">
                        <option value="">Toate sălile</option>
                        ${ROOM_NAMES.map((room) => `<option value="${room}">${room}</option>`).join("")}
                    </select>
                </label>
                <label>
                    <span>Medic</span>
                    <select name="doctor">
                        <option value="">Toți medicii</option>
                        ${(state.settings?.doctors || []).map((doctor) => `<option value="${escapeAttribute(doctor)}">${escapeHtml(doctor)}</option>`).join("")}
                    </select>
                </label>
                <div class="modal-actions full">
                    <button type="button" class="ghost-btn" data-close-modal>Anulează</button>
                    <button type="submit" class="primary-btn">Generează exportul</button>
                </div>
            </form>
        </div>
    `;

    modal.body.querySelectorAll("[data-export-mode]").forEach((button) => {
        button.addEventListener("click", () => {
            state.exportMode = button.dataset.exportMode;
            openExportModal();
        });
    });

    modal.body.querySelectorAll('input[type="date"]').forEach((input) => {
        const hint = input.parentElement.querySelector(".date-hint");
        input.addEventListener("input", () => {
            if (hint && input.value) {
                hint.textContent = formatDisplayDate(input.value);
            }
        });
    });

    modal.body.querySelector("#exportForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        state.exportFormat = "pdf";
        const query = new URLSearchParams({
            action: "export",
            mode: state.exportMode,
            format: state.exportFormat,
            date: state.selectedDate,
            month: monthKeyFromParts(state.currentYear, state.currentMonth),
            startDate: formData.get("startDate")?.toString() || "",
            endDate: formData.get("endDate")?.toString() || "",
            room: formData.get("room")?.toString() || "",
            doctor: formData.get("doctor")?.toString() || ""
        });

        setStatus("Se generează fișierul de export...", "busy");
        try {
            const response = await fetch(`${API_URL}?${query.toString()}`, { cache: "no-store" });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || "Exportul nu a putut fi generat.");
            }
            const exportFile = result.data?.file;
            const exportFormat = result.data?.format?.toUpperCase() || state.exportFormat.toUpperCase();
            if (!exportFile) {
                throw new Error("Fișierul de export nu a fost returnat de server.");
            }
            triggerFileDownload(exportFile);
            toast(`Exportul ${exportFormat} a fost generat.`);
            setStatus(`Export ${exportFormat} generat cu succes.`, "");
            closeModal();
        } catch (error) {
            console.error(error);
            toast(error.message);
            setStatus(error.message, "error");
        }
    });
}

async function openImportModal() {
    const modal = createModal({
        title: "Import operatii din Excel",
        subtitle: "Descarca modelul, completeaza-l si verifica toate randurile inainte de salvare."
    });
    modal.modal.classList.add("import-modal");
    modal.body.innerHTML = `
        <div class="modal-stack import-workflow">
            <section class="import-step">
                <span class="import-step-number">1</span>
                <div><strong>Descarca modelul configurat</strong><p>Pastreaza antetele si completeaza operatiile in prima foaie.</p><button type="button" class="secondary-btn" id="downloadImportTemplate">Descarca model XLSX</button></div>
            </section>
            <form class="import-step" id="importPreviewForm">
                <span class="import-step-number">2</span>
                <div class="import-step-content"><strong>Incarca fisierul completat</strong><p>Limita 5 MB, maximum 1000 de operatii per import.</p><input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><button type="submit" class="primary-btn">Verifica fisierul</button></div>
            </form>
            <div id="importLastBatch" class="import-last-batch"></div>
            <div id="importPreviewResult"></div>
        </div>`;
    modal.body.querySelector("#downloadImportTemplate").addEventListener("click", () => triggerFileDownload(`${IMPORT_API_URL}?action=template`));
    modal.body.querySelector("#importPreviewForm").addEventListener("submit", previewImportFile);
    await loadLastImport(modal.body.querySelector("#importLastBatch"));
}

async function previewImportFile(event) {
    event.preventDefault();
    const resultBox = elements.modalRoot.querySelector("#importPreviewResult");
    const formData = new FormData(event.currentTarget);
    formData.set("action", "preview");
    setStatus("Se verifica fisierul XLSX...", "busy");
    resultBox.innerHTML = `<div class="inline-note">Se citesc si se valideaza randurile...</div>`;
    try {
        const response = await fetch(IMPORT_API_URL, { method: "POST", headers: { "X-CSRF-Token": CSRF_TOKEN }, body: formData });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || "Fisierul nu a putut fi verificat.");
        renderImportPreview(result.data, resultBox);
        setStatus("Fisier verificat. Confirma importul dupa ce verifici lista.");
    } catch (error) {
        resultBox.innerHTML = `<div class="import-error-box">${escapeHtml(error.message)}</div>`;
        setStatus(error.message, "error");
    }
}

function renderImportPreview(data, container) {
    const summary = data.summary || {};
    const hasErrors = Number(summary.errors || 0) > 0;
    const rows = Array.isArray(data.rows) ? data.rows : [];
    container.innerHTML = `
        <section class="import-preview-card">
            <div class="import-summary"><span><strong>${summary.total || 0}</strong> randuri</span><span class="import-valid"><strong>${summary.valid || 0}</strong> pregatite</span><span class="import-warning"><strong>${summary.duplicates || 0}</strong> duplicate omise</span><span class="import-invalid"><strong>${summary.errors || 0}</strong> cu erori</span></div>
            ${hasErrors ? `<div class="import-error-box">Corecteaza randurile marcate cu rosu in fisier si incarca-l din nou.</div>` : ""}
            <div class="import-table-wrap"><table class="import-table"><thead><tr><th>Rand</th><th>Data</th><th>Sala</th><th>Pacient</th><th>Tip operatie</th><th>Medic</th><th>Rezultat</th></tr></thead><tbody>${rows.map((row) => {
                const errors = Array.isArray(row.errors) ? row.errors : [];
                const statusClass = errors.length ? "invalid" : row.duplicate ? "duplicate" : "valid";
                const status = errors.length ? errors.join(" ") : row.duplicate ? "Duplicat - va fi omis" : "Pregatit pentru import";
                return `<tr class="${statusClass}"><td>${row.source_row}</td><td>${escapeHtml(row.date)}</td><td>${escapeHtml(row.room)}</td><td>${escapeHtml(row.patient)}</td><td>${escapeHtml(row.operation_type)}</td><td>${escapeHtml(row.doctor)}</td><td>${escapeHtml(status)}</td></tr>`;
            }).join("")}</tbody></table></div>
            <div class="modal-actions"><button type="button" class="ghost-btn" data-close-modal>Anuleaza</button><button type="button" class="primary-btn" id="confirmImportButton" ${hasErrors || Number(summary.valid || 0) === 0 ? "disabled" : ""}>Importa ${summary.valid || 0} operatii</button></div>
        </section>`;
    container.querySelector("#confirmImportButton")?.addEventListener("click", () => commitImport(data.previewToken));
}

async function commitImport(previewToken) {
    if (!window.confirm("Confirmi adaugarea operatiilor validate in Programul operator?")) return;
    setStatus("Se importa operatiile...", "busy");
    try {
        const result = await importJsonRequest({ action: "commit", previewToken });
        state.months = {};
        await preloadVisibleMonths(true);
        renderAll();
        closeModal();
        toast(`${result.data.imported} operatii au fost importate. ${result.data.skipped || 0} duplicate au fost omise.`);
        setStatus("Import finalizat si inregistrat in jurnal.");
    } catch (error) {
        toast(error.message);
        setStatus(error.message, "error");
    }
}

async function loadLastImport(container) {
    try {
        const response = await fetch(`${IMPORT_API_URL}?action=last`, { cache: "no-store" });
        const result = await response.json();
        const batch = result.data?.lastImport;
        if (!result.success || !batch || batch.status !== "completed") return;
        container.innerHTML = `<div><strong>Ultimul import:</strong> ${escapeHtml(batch.source_filename)} · ${batch.imported_rows} operatii · ${escapeHtml(formatDateTime(batch.created_at))}</div><button type="button" class="danger-btn">Anuleaza acest import</button>`;
        container.querySelector("button").addEventListener("click", () => undoImport(batch.id));
    } catch (error) {
        console.error(error);
    }
}

async function undoImport(importId) {
    if (!window.confirm("Anulezi acest import? Operatiile modificate ulterior vor fi pastrate.")) return;
    try {
        const result = await importJsonRequest({ action: "undo", importId: Number(importId) });
        state.months = {};
        await preloadVisibleMonths(true);
        renderAll();
        closeModal();
        toast(`${result.data.undone} operatii importate au fost eliminate.`);
        setStatus("Importul a fost anulat si actiunea a fost jurnalizata.");
    } catch (error) {
        toast(error.message);
        setStatus(error.message, "error");
    }
}

async function importJsonRequest(payload) {
    const response = await fetch(IMPORT_API_URL, { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN }, body: JSON.stringify(payload) });
    const result = await response.json();
    if (!result.success) throw new Error(result.message || "Operatiunea de import nu a putut fi finalizata.");
    return result;
}

async function createBackup() {
    await apiMutation("backup", {}, "Backup-ul a fost creat.");
}

async function apiMutation(action, payload, successMessage, options = {}) {
    setStatus("Se salvează modificările...", "busy");
    const response = await fetch(API_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
        body: JSON.stringify({ action, ...payload })
    });
    const result = await response.json();
    if (!result.success) {
        const message = result.message || "Operațiunea nu a putut fi finalizată.";
        toast(message);
        setStatus(message, "error");
        throw new Error(message);
    }
    updateStateFromPayload(result.data);
    if (!options.skipRender) {
        renderAll();
    }
    setStatus(`Salvat. Ultima actualizare: ${formatDateTime(result.data?.meta?.updatedAt)}`, "");
    if (successMessage) {
        toast(successMessage);
    }
    return result;
}

function createModal({ title, subtitle }) {
    closeModal();
    elements.modalOverlay.classList.remove("hidden");
    const modal = document.createElement("div");
    modal.className = "modal";
    modal.innerHTML = `
        <div class="modal-header">
            <div>
                <h3>${escapeHtml(title)}</h3>
                <p>${escapeHtml(subtitle || "")}</p>
            </div>
            <button type="button" class="ghost-btn" data-close-modal>Închide</button>
        </div>
        <div class="modal-body"></div>
    `;
    modal.addEventListener("click", (event) => {
        if (event.target.closest("[data-close-modal]")) {
            closeModal();
        }
    });
    elements.modalRoot.appendChild(modal);
    return { modal, body: modal.querySelector(".modal-body") };
}

function closeModal() {
    const activeModal = elements.modalRoot.querySelector(".modal");
    if (activeModal) {
        activeModal.dispatchEvent(new CustomEvent("modal:close"));
    }
    elements.modalOverlay.classList.add("hidden");
    elements.modalRoot.innerHTML = "";
}

function setStatus(message, kind = "") {
    elements.saveStatus.textContent = message;
    elements.saveStatus.className = "save-status";
    if (kind) {
        elements.saveStatus.classList.add(kind);
    }
}

function toast(message) {
    const toastNode = document.createElement("div");
    toastNode.className = "toast";
    toastNode.textContent = message;
    document.body.appendChild(toastNode);
    setTimeout(() => toastNode.remove(), 3200);
}

function openOverviewWindow() {
    window.open("progop-overview.php", "_blank", "noopener");
}

function buildGuardLineLabel(assignment) {
    const parts = [];
    if (assignment.specialty) {
        parts.push(assignment.specialty);
    }
    if (assignment.line) {
        parts.push(`L${assignment.line}`);
    }
    return parts.length ? parts.join(" / ") : "Garda";
}

function buildNotesText(item) {
    const parts = [];
    if (item.extra) {
        parts.push(item.approvalState === "approved" ? `EXTRA: ${item.extraReason || "adăugată ulterior"}` : "Operație EXTRA neaprobată");
    }
    if (item.customNotes) {
        parts.push(item.customNotes);
    }
    return parts.length ? parts.join(" | ") : "Fără observații.";
}

function formatDate(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0")
    ].join("-");
}

function parseDateParts(dateString) {
    const [year, month, day] = dateString.split("-").map(Number);
    return { year, month: month - 1, day };
}

function monthKeyFromDate(dateString) {
    return dateString.slice(0, 7);
}

function monthKeyFromParts(year, monthIndex) {
    return `${year}-${String(monthIndex + 1).padStart(2, "0")}`;
}

function formatFriendlyDate(dateString) {
    const parts = parseDateParts(dateString);
    const date = new Date(parts.year, parts.month, parts.day);
    const weekday = date.toLocaleDateString("ro-RO", { weekday: "long" });
    return `${weekday}, ${formatDisplayDate(dateString)}`;
}

function formatDateTime(timestamp) {
    if (!timestamp) {
        return "necunoscută";
    }
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? "necunoscută" : date.toLocaleString("ro-RO");
}

function formatShortDate(dateString) {
    return formatDisplayDate(dateString);
}

function getNextWeekRange(referenceDate) {
    const parts = parseDateParts(referenceDate);
    const date = new Date(parts.year, parts.month, parts.day);
    const weekday = (date.getDay() + 6) % 7;
    date.setDate(date.getDate() - weekday + 7);
    const start = formatDate(date);
    const endDate = new Date(date);
    endDate.setDate(endDate.getDate() + 6);
    return { start, end: formatDate(endDate) };
}

function triggerFileDownload(filePath) {
    const link = document.createElement("a");
    link.href = `${filePath}${filePath.includes("?") ? "&" : "?"}t=${Date.now()}`;
    link.download = "";
    document.body.appendChild(link);
    link.click();
    link.remove();
}

function formatDisplayDate(dateString) {
    const parts = parseDateParts(dateString);
    const date = new Date(parts.year, parts.month, parts.day);
    const month = date.toLocaleDateString("ro-RO", { month: "short" }).replace(".", "");
    return `${String(parts.day).padStart(2, "0")} ${month} ${parts.year}`;
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}

function escapeAttribute(value) {
    return escapeHtml(value).replaceAll("`", "&#96;");
}
