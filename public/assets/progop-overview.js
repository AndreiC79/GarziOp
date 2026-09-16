const API_URL = "api/progop-legacy.php";
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || "";
let ROOM_NAMES = ["Sala 1", "Sala 2", "Sala 3", "Sala 4"];
const GUARD_SPECIALTIES = ["ATI", "OG", "NN"];
const IS_OPERATING_ROOM_DASHBOARD = document.body.dataset.operatingRoomDashboard === "1";

const runtimeToday = formatDate(new Date());
const state = {
    selectedDate: runtimeToday,
    mode: "day",
    focus: "current",
    payload: null
};

const el = {};

document.addEventListener("DOMContentLoaded", async () => {
    cacheElements();
    bindEvents();
    syncControls();
    await loadOverview();
});

function cacheElements() {
    el.modeDayBtn = document.getElementById("modeDayBtn");
    el.modeWeekBtn = document.getElementById("modeWeekBtn");
    el.focusSelectedBtn = document.getElementById("focusSelectedBtn");
    el.focusCurrentBtn = document.getElementById("focusCurrentBtn");
    el.referenceDateInput = document.getElementById("referenceDateInput");
    el.refreshOverviewBtn = document.getElementById("refreshOverviewBtn");
    el.openSchedulerBtn = document.getElementById("openSchedulerBtn");
    el.referenceDateHint = document.getElementById("referenceDateHint");
    el.summaryProgress = document.getElementById("summaryProgress");
    el.summaryPercent = document.getElementById("summaryPercent");
    el.summaryProgressFill = document.getElementById("summaryProgressFill");
    el.summaryProgressMarker = document.getElementById("summaryProgressMarker");
    el.summaryProgressMessage = document.getElementById("summaryProgressMessage");
    el.summaryExtra = document.getElementById("summaryExtra");
    el.summaryBlocked = document.getElementById("summaryBlocked");
    el.summaryGuardsNote = document.getElementById("summaryGuardsNote");
    el.summaryGuardsList = document.getElementById("summaryGuardsList");
    el.overviewDays = document.getElementById("overviewDays");
    el.modalHost = document.getElementById("overviewModalHost");
    el.toast = document.getElementById("overviewToast");
    el.dailyStatusLabel = document.getElementById("dailyStatusLabel");
    el.dailyStatusMessage = document.getElementById("dailyStatusMessage");
    el.kioskPrevDay = document.getElementById("kioskPrevDay");
    el.kioskToday = document.getElementById("kioskToday");
    el.kioskNextDay = document.getElementById("kioskNextDay");
}

function bindEvents() {
    el.modeDayBtn.addEventListener("click", async () => {
        state.mode = "day";
        syncControls();
        await loadOverview();
    });
    el.modeWeekBtn.addEventListener("click", async () => {
        state.mode = "week";
        syncControls();
        await loadOverview();
    });
    el.focusSelectedBtn.addEventListener("click", async () => {
        state.focus = "selected";
        syncControls();
        await loadOverview();
    });
    el.focusCurrentBtn.addEventListener("click", async () => {
        state.focus = "current";
        syncControls();
        await loadOverview();
    });
    el.referenceDateInput.addEventListener("change", async () => {
        state.selectedDate = el.referenceDateInput.value || runtimeToday;
        state.focus = "selected";
        syncControls();
        await loadOverview();
    });
    el.refreshOverviewBtn.addEventListener("click", loadOverview);
    el.openSchedulerBtn?.addEventListener("click", () => {
        window.open(`progop-legacy.php?date=${encodeURIComponent(getReferenceDate())}`, "_blank", "noopener");
    });
    el.kioskPrevDay?.addEventListener("click", () => selectKioskDay(-1));
    el.kioskToday?.addEventListener("click", () => selectKioskDay(0));
    el.kioskNextDay?.addEventListener("click", () => selectKioskDay(1));
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && el.modalHost?.children.length) closeEmergencyModal();
    });
}

function syncControls() {
    el.modeDayBtn.classList.toggle("active", state.mode === "day");
    el.modeWeekBtn.classList.toggle("active", state.mode === "week");
    el.focusSelectedBtn.classList.toggle("active", state.focus === "selected");
    el.focusCurrentBtn.classList.toggle("active", state.focus === "current");
    el.referenceDateInput.value = state.selectedDate;
    el.referenceDateHint.textContent = formatDisplayDate(state.selectedDate);
    if (IS_OPERATING_ROOM_DASHBOARD) {
        const offset = dayOffsetFromToday(state.selectedDate);
        el.kioskPrevDay?.classList.toggle("active", offset === -1);
        el.kioskToday?.classList.toggle("active", offset === 0);
        el.kioskNextDay?.classList.toggle("active", offset === 1);
    }
}

async function selectKioskDay(offset) {
    const date = new Date();
    date.setDate(date.getDate() + offset);
    state.selectedDate = formatDate(date);
    state.focus = offset === 0 ? "current" : "selected";
    state.mode = "day";
    syncControls();
    await loadOverview();
}

function dayOffsetFromToday(dateString) {
    const selected = new Date(`${dateString}T12:00:00`);
    const today = new Date(`${runtimeToday}T12:00:00`);
    return Math.round((selected - today) / 86400000);
}

function getReferenceDate() {
    return state.focus === "current" ? runtimeToday : state.selectedDate;
}

async function loadOverview() {
    const date = getReferenceDate();
    const response = await fetch(`${API_URL}?action=overview&mode=${encodeURIComponent(state.mode)}&date=${encodeURIComponent(date)}`, { cache: "no-store" });
    const result = await response.json();
    if (!result.success) {
        alert(result.message || "Nu am putut încărca overview-ul.");
        return;
    }
    state.payload = result.data;
    renderOverview();
}

function renderOverview() {
    if (Array.isArray(state.payload.settings?.rooms) && state.payload.settings.rooms.length) {
        ROOM_NAMES = state.payload.settings.rooms.map(String);
    }
    const days = state.payload.days || {};
    const dates = state.payload.range?.dates || [];
    let total = 0;
    let completed = 0;
    let extra = 0;
    let blocked = 0;
    const guardsBySpecialty = createEmptyGuardSummary();

    dates.forEach((date) => {
        const day = days[date];
        const guards = getGuardAssignmentsForDate(date);
        mergeGuardsIntoSummary(guardsBySpecialty, guards);
        ROOM_NAMES.forEach((room) => {
            const roomState = day?.rooms?.[room];
            if (!roomState) {
                return;
            }
            if (roomState.blocked) {
                blocked += 1;
            }
            roomState.interventions.forEach((item) => {
                total += 1;
                if (item.completed) {
                    completed += 1;
                }
                if (item.extra) {
                    extra += 1;
                }
            });
        });
    });

    const progressPercent = total ? Math.round((completed / total) * 100) : 0;
    el.summaryProgress.textContent = `${completed} / ${total}`;
    el.summaryPercent.textContent = `${progressPercent}% efectuate`;
    el.summaryProgressFill.style.width = `${progressPercent}%`;
    el.summaryProgressMarker.style.left = `${progressPercent}%`;
    el.summaryProgressMarker.parentElement.setAttribute("aria-valuenow", String(progressPercent));
    el.summaryProgressMessage.textContent = getProgressMessage(progressPercent, total);
    renderDailyStatus(progressPercent, total, completed, extra);
    el.summaryExtra.textContent = String(extra);
    el.summaryBlocked.textContent = String(blocked);
    el.summaryGuardsNote.textContent = state.mode === "day"
        ? "Categorii pentru ziua selectata"
        : "Medici unici din intervalul afisat";
    el.summaryGuardsList.innerHTML = renderGuardSummaryList(guardsBySpecialty);

    el.overviewDays.innerHTML = dates.map((date) => renderDayPanel(date, days[date])).join("");
    bindCheckboxes();
    bindDashboardStatusControls();
    bindEstimatedTimes();
    bindEmergencyButtons();
    bindOperationDragAndDrop();
}

function renderDailyStatus(percent, total, completed, extra) {
    if (!el.dailyStatusMessage) return;
    const messages = [
        "Precizie, răbdare și cafea. În ordinea corectă.",
        "Astăzi lucrăm curat: operațiile la locul lor, cafeaua aproape.",
        "Blocul operator: unde timpul zboară, dar checklistul rămâne.",
        "Calm, coordonare și câte un pas pe rând.",
        "Planul este simplu: grijă mare și butoane apăsate corect.",
        "Echipa bună face și ziua lungă să încapă în program.",
        "Totul sub control. Inclusiv cafeaua, aproximativ.",
        "Mai întâi siguranța, apoi viteza, apoi încă o cafea.",
        "Fiecare operație terminată mută bara în direcția bună.",
        "Azi facem ordine în program și bine în sală.",
        "Programul poate fi plin. Echipa rămâne calmă.",
        "În blocul operator, și optimismul poartă bonetă."
    ];
    const point = new Date(`${getReferenceDate()}T12:00:00`);
    const dayIndex = Math.floor(point.getTime() / 86400000) % messages.length;
    el.dailyStatusLabel.textContent = total ? `${completed}/${total} efectuate${extra ? ` · ${extra} EXTRA` : ""}` : "Program liber";
    el.dailyStatusMessage.textContent = messages[(dayIndex + messages.length) % messages.length];
    el.dailyStatusMessage.closest(".daily-status-bar")?.style.setProperty("--daily-progress", `${percent}%`);
}

function getProgressMessage(percent, total) {
    if (!total) return "Cafeaua e pregatita. Operatiile inca se gandesc.";
    if (percent === 100) return "Lista inchisa. Aplauze silentioase, suntem in spital.";
    if (percent >= 75) return "Ultima sutura de progres. Aproape gata.";
    if (percent >= 50) return "Jumatate trecuta. Blocul operator respira controlat.";
    if (percent >= 25) return "Ritmul e bun. Cafeaua cere intariri.";
    return "Am pornit. Halatele sunt optimiste.";
}

function renderDayPanel(date, day) {
    const daySummary = summarizeDay(day);
    const guards = getGuardAssignmentsForDate(date);
    return `
        <article class="day-panel">
            <div class="day-header">
                <div>
                    <h2>${escapeHtml(formatFriendlyDate(date))}</h2>
                    <p class="intervention-submeta">${daySummary.completed} din ${daySummary.total} efectuate</p>
                </div>
                <div class="day-progress">${daySummary.percent}%</div>
            </div>
            ${renderGuardStrip(guards)}
            <div class="rooms-overview-grid">
                ${ROOM_NAMES.map((room) => renderRoomPanel(date, room, day?.rooms?.[room])).join("")}
            </div>
        </article>
    `;
}

function renderRoomPanel(date, roomName, roomState) {
    const stateSafe = roomState || { blocked: false, blockReason: "", interventions: [] };
    const completed = stateSafe.interventions.filter((item) => item.completed).length;
    const canAddEmergency = date === runtimeToday && Boolean(state.payload?.capabilities?.canCreate) && !stateSafe.blocked;
    const atiNames = getAtiNames(date, roomName);
    return `
        <section class="room-panel ${stateSafe.blocked ? "blocked" : ""}" data-drop-date="${date}" data-drop-room="${escapeHtml(roomName)}">
            <div class="room-head">
                <div>
                    <h3>${escapeHtml(roomName)}</h3>
                    <p class="room-meta">${completed}/${stateSafe.interventions.length} efectuate</p>
                    <p class="room-ati"><b>ATI</b> ${atiNames.length ? atiNames.map(escapeHtml).join(" · ") : "Nerepartizat"}</p>
                </div>
                ${canAddEmergency ? `<button type="button" class="emergency-room-btn" data-emergency-date="${date}" data-emergency-room="${escapeHtml(roomName)}">+ Adaugă urgență</button>` : ""}
            </div>
            ${stateSafe.blocked ? `<p class="blocked-note">Sala este blocată${stateSafe.blockReason ? `: ${escapeHtml(stateSafe.blockReason)}` : ""}.</p>` : ""}
            ${stateSafe.interventions.length
                ? stateSafe.interventions.map((item, index) => `
                    <article class="intervention-mini status-${escapeHtml(item.operatingStatus || "scheduled")} ${item.extra ? "extra" : ""} ${item.urgent ? "urgent" : ""} ${item.extra && item.approvalState !== "approved" ? "approval-pending" : ""} ${item.completed ? "done" : ""}" draggable="${canDragOperation(item)}" data-drag-operation="${item.id}" data-source-date="${date}" data-source-room="${escapeHtml(roomName)}" data-source-position="${index + 1}" data-patient-name="${escapeHtml(item.patientName)}" data-operation-name="${escapeHtml(item.operationType || "Operație")}">
                        <div class="check-row">
                            <strong>${escapeHtml(item.patientName)}</strong>
                            ${IS_OPERATING_ROOM_DASHBOARD ? "" : `<label class="completed-toggle">
                                <input type="checkbox" data-date="${date}" data-room="${roomName}" data-id="${item.id}" ${item.completed ? "checked" : ""}>
                                efectuată
                            </label>`}
                        </div>
                        <div class="operation-time-row">
                            <label><span>Ora estimată</span><input class="estimated-time-input" type="time" data-estimated-time="${item.id}" value="${escapeHtml(item.estimatedTime || "")}" ${item.completed ? "disabled" : ""}></label>
                            <span class="operation-status-label">${escapeHtml(statusLabel(item.operatingStatus))}</span>
                        </div>
                        <div class="operation-facts"><span><b>Operație</b>${escapeHtml(item.operationType || "Nespecificată")}</span><span><b>Medic</b>${escapeHtml(item.doctor)}</span>${item.diagnostic ? `<span><b>Diagnostic</b>${escapeHtml(item.diagnostic)}</span>` : ""}</div>
                        <div class="pill-row">
                            ${item.urgent ? `<span class="pill urgent">URGENȚĂ</span>` : ""}
                            ${item.extra ? `<span class="pill extra">${escapeHtml(item.approvalLabel || "EXTRA")}</span>` : ""}
                            ${item.completed ? `<span class="pill done">Finalizată</span>` : ""}
                        </div>
                        ${IS_OPERATING_ROOM_DASHBOARD ? renderDashboardStatusActions(item) : ""}
                        <p class="intervention-submeta">${escapeHtml(buildNotes(item))}</p>
                    </article>
                `).join("")
                : `<p class="empty-room">Nu există intervenții în această sală.</p>`}
        </section>
    `;
}

function getAtiNames(date, roomName) {
    const ids = state.payload?.atiAssignmentsByDate?.[date]?.[roomName] || [];
    const names = new Map((state.payload?.settings?.atiDoctorOptions || []).map((doctor) => [Number(doctor.id), doctor.name]));
    return ids.map((id) => names.get(Number(id))).filter(Boolean);
}

function canDragOperation(item) {
    const capabilities = state.payload?.capabilities || {};
    if (item.completed) return false;
    return Boolean(capabilities.canManageAll || (capabilities.canEditOwn && Number(item.createdBy) === Number(capabilities.currentUserId)));
}

function statusLabel(status) {
    return ({ scheduled: "Programată", in_room: "Pacienta în sală", in_progress: "În desfășurare", completed: "Efectuată" })[status] || "Programată";
}

function renderDashboardStatusActions(item) {
    const statuses = [
        ["scheduled", "Programată"],
        ["in_room", "În sală"],
        ["in_progress", "În desfășurare"],
        ["completed", "Efectuată"]
    ];
    return `<div class="dashboard-status-actions" role="group" aria-label="Starea operației">
        ${statuses.map(([status, label]) => `<button type="button" data-dashboard-status="${status}" data-operation-id="${item.id}" class="${item.operatingStatus === status ? "active" : ""}">${label}</button>`).join("")}
    </div>`;
}

function bindOperationDragAndDrop() {
    document.querySelectorAll('[data-drag-operation][draggable="true"]').forEach((card) => {
        card.addEventListener("dragstart", (event) => {
            card.classList.add("dragging");
            event.dataTransfer.effectAllowed = "move";
            event.dataTransfer.setData("application/x-intern-operation", JSON.stringify({
                id: Number(card.dataset.dragOperation),
                fromDate: card.dataset.sourceDate,
                fromRoom: card.dataset.sourceRoom,
                fromPosition: Number(card.dataset.sourcePosition),
                patientName: card.dataset.patientName,
                operationName: card.dataset.operationName
            }));
        });
        card.addEventListener("dragend", () => card.classList.remove("dragging"));
    });
    document.querySelectorAll("[data-drop-date][data-drop-room]").forEach((room) => {
        room.addEventListener("dragover", (event) => {
            if (!event.dataTransfer.types.includes("application/x-intern-operation")) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = "move";
            room.classList.add("drag-target");
        });
        room.addEventListener("dragleave", (event) => {
            if (!room.contains(event.relatedTarget)) room.classList.remove("drag-target");
        });
        room.addEventListener("drop", async (event) => {
            event.preventDefault();
            room.classList.remove("drag-target");
            let transfer;
            try { transfer = JSON.parse(event.dataTransfer.getData("application/x-intern-operation")); } catch { return; }
            const toDate = room.dataset.dropDate;
            const toRoom = room.dataset.dropRoom;
            const targetCard = event.target.closest("[data-drag-operation]");
            const position = targetCard ? Number(targetCard.dataset.sourcePosition) : room.querySelectorAll("[data-drag-operation]").length + 1;
            if (!transfer?.id || (transfer.fromDate === toDate && transfer.fromRoom === toRoom && transfer.fromPosition === position)) return;
            const dateSuffix = transfer.fromDate === toDate ? "" : `, în data de ${formatDisplayDate(toDate)}`;
            if (!window.confirm(`Mutați ${transfer.patientName} - ${transfer.operationName} în ${toRoom}${dateSuffix}?`)) return;
            try {
                const response = await fetch(API_URL, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
                    body: JSON.stringify({ action: "moveIntervention", interventionId: transfer.id, toDate, toRoom, position })
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || "Operatia nu a putut fi mutata.");
                showOverviewToast("Operatia a fost mutata si modificarea a fost jurnalizata.");
                await loadOverview();
            } catch (error) {
                showOverviewToast(error.message || "Operatia nu a putut fi mutata.", true);
            }
        });
    });
}

function bindEmergencyButtons() {
    document.querySelectorAll("[data-emergency-room]").forEach((button) => {
        button.addEventListener("click", () => openEmergencyModal(button.dataset.emergencyRoom, button.dataset.emergencyDate));
    });
}

function openEmergencyModal(roomName, date) {
    if (date !== runtimeToday || !state.payload?.capabilities?.canCreate) {
        showOverviewToast("Urgențele pot fi adăugate numai pentru ziua curentă.", true);
        return;
    }
    const doctors = Array.isArray(state.payload?.settings?.doctorOptions) ? state.payload.settings.doctorOptions : [];
    const operationTypes = Array.isArray(state.payload?.settings?.operationTypes) ? state.payload.settings.operationTypes : [];
    const currentPersonId = Number(state.payload?.capabilities?.currentPersonId || 0);
    const doctorOptions = doctors.map((doctor) => `
        <option value="${escapeHtml(doctor.name)}" ${Number(doctor.id) === currentPersonId ? "selected" : ""}>${escapeHtml(doctor.name)}</option>
    `).join("");
    const typeOptions = operationTypes.map((type) => `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`).join("");

    el.modalHost.innerHTML = `
        <div class="overview-modal-backdrop">
            <section class="overview-modal" role="dialog" aria-modal="true" aria-labelledby="emergencyModalTitle">
                <header class="overview-modal-head">
                    <div><span>OPERAȚIE EXTRA · ${escapeHtml(formatDisplayDate(date))}</span><h2 id="emergencyModalTitle">Adaugă urgență în ${escapeHtml(roomName)}</h2></div>
                    <button type="button" class="overview-modal-close" data-close-emergency aria-label="Închide">×</button>
                </header>
                <p class="emergency-rule">Intervenția va fi adăugată direct în sala aleasă ca <strong>EXTRA</strong>, va apărea gri până la aprobarea directorului medical și va fi jurnalizată cu utilizatorul care a introdus-o.</p>
                <form id="emergencyOperationForm" class="overview-modal-form">
                    <div class="overview-form-grid">
                        <label><span>Data</span><input value="${escapeHtml(formatDisplayDate(date))}" disabled></label>
                        <label><span>Sala</span><input value="${escapeHtml(roomName)}" disabled></label>
                        <label class="full"><span>Nume pacientă, opțional pentru urgență</span><input name="patientName" maxlength="220" placeholder="Urgență - identitate în curs" autofocus></label>
                        <label><span>Medic operator</span><select name="doctor" required><option value="">Alege medicul</option>${doctorOptions}</select></label>
                        <label><span>Tip operație</span><select name="operationType" required><option value="">Alege tipul</option>${typeOptions}</select></label>
                        <label><span>Ora estimată, opțional</span><input name="estimatedTime" type="time"></label>
                        <label class="full"><span>Diagnostic, opțional</span><input name="diagnostic" maxlength="180"></label>
                        <label class="full"><span>Observații, opțional</span><textarea name="customNotes" maxlength="1000" rows="3"></textarea></label>
                    </div>
                    <p class="overview-form-error" hidden></p>
                    <div class="overview-modal-actions">
                        <button type="button" class="ghost-btn" data-close-emergency>Renunță</button>
                        <button type="submit" class="emergency-submit-btn">Adaugă urgența</button>
                    </div>
                </form>
            </section>
        </div>`;
    document.body.classList.add("modal-open");
    el.modalHost.querySelectorAll("[data-close-emergency]").forEach((button) => button.addEventListener("click", closeEmergencyModal));
    el.modalHost.querySelector(".overview-modal-backdrop").addEventListener("click", (event) => {
        if (event.target === event.currentTarget) closeEmergencyModal();
    });
    el.modalHost.querySelector("#emergencyOperationForm").addEventListener("submit", (event) => saveEmergencyOperation(event, roomName, date));
}

async function saveEmergencyOperation(event, roomName, date) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const submit = form.querySelector('button[type="submit"]');
    const errorBox = form.querySelector(".overview-form-error");
    const intervention = {
        patientName: String(formData.get("patientName") || "").trim() || "Urgență - identitate în curs",
        doctor: String(formData.get("doctor") || "").trim(),
        operationType: String(formData.get("operationType") || "").trim(),
        diagnostic: String(formData.get("diagnostic") || "").trim(),
        customNotes: String(formData.get("customNotes") || "").trim()
    };
    if (!intervention.doctor || !intervention.operationType) {
        errorBox.textContent = "Completează medicul și tipul operației.";
        errorBox.hidden = false;
        return;
    }
    submit.disabled = true;
    submit.textContent = "Se salvează...";
    errorBox.hidden = true;
    try {
        const response = await fetch(API_URL, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
            body: JSON.stringify({ action: "addEmergencyIntervention", date, room: roomName, estimatedTime: String(formData.get("estimatedTime") || ""), intervention })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || "Urgența nu a putut fi adăugată.");
        closeEmergencyModal();
        showOverviewToast(result.message || "Urgența a fost adăugată.");
        await loadOverview();
    } catch (error) {
        errorBox.textContent = error.message || "Urgența nu a putut fi adăugată.";
        errorBox.hidden = false;
        submit.disabled = false;
        submit.textContent = "Adaugă urgența";
    }
}

function closeEmergencyModal() {
    el.modalHost.innerHTML = "";
    document.body.classList.remove("modal-open");
}

let overviewToastTimer = null;
function showOverviewToast(message, isError = false) {
    clearTimeout(overviewToastTimer);
    el.toast.textContent = message;
    el.toast.classList.toggle("error", isError);
    el.toast.hidden = false;
    overviewToastTimer = setTimeout(() => { el.toast.hidden = true; }, 4200);
}

function bindCheckboxes() {
    document.querySelectorAll('input[type="checkbox"][data-id]').forEach((checkbox) => {
        checkbox.addEventListener("change", async (event) => {
            const target = event.currentTarget;
            const response = await fetch(API_URL, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
                body: JSON.stringify({
                    action: "toggleCompleted",
                    date: target.dataset.date,
                    room: target.dataset.room,
                    interventionId: target.dataset.id,
                    completed: target.checked
                })
            });
            const result = await response.json();
            if (!result.success) {
                alert(result.message || "Nu am putut salva statusul.");
                target.checked = !target.checked;
                return;
            }
            await loadOverview();
        });
    });
}

function bindDashboardStatusControls() {
    document.querySelectorAll("[data-dashboard-status]").forEach((button) => {
        button.addEventListener("click", async () => {
            if (button.classList.contains("active")) return;
            const card = button.closest("[data-drag-operation]");
            const currentStatus = card?.classList.contains("status-completed") ? "completed" : "";
            const nextStatus = button.dataset.dashboardStatus;
            if (nextStatus === "completed" && !window.confirm("Marchezi operația ca efectuată?")) return;
            if (currentStatus === "completed" && nextStatus !== "completed"
                && !window.confirm("Operația este marcată efectuată. Revii la o stare activă?")) return;
            button.disabled = true;
            try {
                await mutateOverview({
                    action: "setDashboardStatus",
                    interventionId: Number(button.dataset.operationId),
                    status: nextStatus
                });
                showOverviewToast(`Stare actualizată: ${button.textContent.trim()}.`);
                await loadOverview();
            } catch (error) {
                showOverviewToast(error.message || "Starea nu a putut fi actualizată.", true);
                button.disabled = false;
            }
        });
    });
}

function bindEstimatedTimes() {
    document.querySelectorAll("[data-estimated-time]").forEach((input) => {
        input.addEventListener("change", async () => {
            input.disabled = true;
            try {
                await mutateOverview({
                    action: "setEstimatedTime",
                    interventionId: Number(input.dataset.estimatedTime),
                    estimatedTime: input.value
                });
                showOverviewToast(input.value ? `Ora estimată a fost setată la ${input.value}.` : "Ora estimată a fost eliminată.");
                await loadOverview();
            } catch (error) {
                showOverviewToast(error.message || "Ora estimată nu a putut fi salvată.", true);
                input.disabled = false;
            }
        });
    });
}

async function mutateOverview(payload) {
    const response = await fetch(API_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF_TOKEN },
        body: JSON.stringify(payload)
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || "Modificarea nu a putut fi salvată.");
    return result;
}

function summarizeDay(day) {
    let total = 0;
    let completed = 0;
    ROOM_NAMES.forEach((room) => {
        const roomState = day?.rooms?.[room];
        if (!roomState) {
            return;
        }
        total += roomState.interventions.length;
        completed += roomState.interventions.filter((item) => item.completed).length;
    });
    return {
        total,
        completed,
        percent: total ? Math.round((completed / total) * 100) : 0
    };
}

function buildNotes(item) {
    const parts = [];
    if (item.extra) {
        parts.push(`EXTRA: ${item.extraReason || "adăugată ulterior"}`);
    }
    if (item.customNotes) {
        parts.push(item.customNotes);
    }
    return parts.length ? parts.join(" | ") : "Fără observații";
}

function renderGuardStrip(assignments) {
    if (!assignments.length) {
        return `
            <div class="guard-strip empty">
                <span class="guard-strip-label">De garda</span>
                <span class="guard-empty">Nu exista garda importata pentru aceasta zi.</span>
            </div>
        `;
    }
    return `
        <div class="guard-strip">
            <span class="guard-strip-label">De garda</span>
            <div class="guard-pills">
                ${assignments.map((assignment) => `
                    <span class="guard-chip specialty-${escapeHtml(normalizeGuardSpecialty(assignment.specialty).toLowerCase())}">${escapeHtml(buildGuardLineLabel(assignment))} - ${escapeHtml(assignment.personName)}</span>
                `).join("")}
            </div>
        </div>
    `;
}

function createEmptyGuardSummary() {
    return Object.fromEntries(GUARD_SPECIALTIES.map((specialty) => [specialty, []]));
}

function mergeGuardsIntoSummary(summary, assignments) {
    assignments.forEach((assignment) => {
        const specialty = normalizeGuardSpecialty(assignment.specialty);
        if (!specialty) {
            return;
        }
        const alreadyIncluded = summary[specialty].some((item) =>
            item.personName === assignment.personName && item.line === assignment.line
        );
        if (!alreadyIncluded) {
            summary[specialty].push(assignment);
            summary[specialty].sort(compareGuardAssignments);
        }
    });
}

function normalizeGuardSpecialty(value) {
    const normalized = String(value || "").trim().toUpperCase();
    return GUARD_SPECIALTIES.includes(normalized) ? normalized : "";
}

function renderGuardSummaryList(guardsBySpecialty) {
    return GUARD_SPECIALTIES.map((specialty) => {
        const assignments = guardsBySpecialty[specialty] || [];
        return `
            <div class="summary-guard-row specialty-${specialty.toLowerCase()}">
                <span class="summary-guard-specialty">${specialty}</span>
                <div class="summary-guard-values">
                    ${assignments.length
                        ? assignments.map((assignment) => `
                            <span class="summary-guard-pill">${escapeHtml(renderGuardSummaryText(assignment))}</span>
                        `).join("")
                        : '<span class="summary-guard-pill missing">Lipsa</span>'}
                </div>
            </div>
        `;
    }).join("");
}

function renderGuardSummaryText(assignment) {
    const lineLabel = assignment.line ? `L${assignment.line}` : "Garda";
    return `${lineLabel} - ${assignment.personName}`;
}

function getGuardAssignmentsForDate(date) {
    const assignments = state.payload?.guardsByDate?.[date];
    if (!Array.isArray(assignments)) {
        return [];
    }
    return assignments
        .filter((assignment) => assignment && assignment.personName)
        .slice()
        .sort(compareGuardAssignments);
}

function compareGuardAssignments(left, right) {
    const specialtyOrder = { ATI: 0, OG: 1, NN: 2 };
    const leftSpecialty = normalizeGuardSpecialty(left.specialty);
    const rightSpecialty = normalizeGuardSpecialty(right.specialty);
    if (leftSpecialty !== rightSpecialty) return (specialtyOrder[leftSpecialty] ?? 9) - (specialtyOrder[rightSpecialty] ?? 9);
    if ((left.line || 0) !== (right.line || 0)) {
        return (left.line || 0) - (right.line || 0);
    }
    return String(left.personName || "").localeCompare(String(right.personName || ""), "ro");
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

function formatDate(date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0")
    ].join("-");
}

function formatFriendlyDate(dateString) {
    const [year, month, day] = dateString.split("-").map(Number);
    const date = new Date(year, month - 1, day);
    const weekday = date.toLocaleDateString("ro-RO", { weekday: "long" });
    return `${weekday}, ${formatDisplayDate(dateString)}`;
}

function formatDisplayDate(dateString) {
    const [year, month, day] = dateString.split("-").map(Number);
    const date = new Date(year, month - 1, day);
    const monthLabel = date.toLocaleDateString("ro-RO", { month: "short" }).replace(".", "");
    return `${String(day).padStart(2, "0")} ${monthLabel} ${year}`;
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#39;");
}
