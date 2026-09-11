(() => {
    const root = document.getElementById("progopApp");
    if (!root) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const el = {
        month: document.getElementById("progopMonth"), calendar: document.getElementById("progopCalendar"),
        status: document.getElementById("progopStatus"), prev: document.getElementById("progopPrevMonth"), next: document.getElementById("progopNextMonth"),
        add: document.getElementById("addOperationButton"), dayTitle: document.getElementById("progopDayTitle"), dayTotal: document.getElementById("progopDayTotal"),
        dayGuards: document.getElementById("progopDayGuards"), rooms: document.getElementById("progopRooms"), drawer: document.getElementById("operationDrawer"),
        drawerTitle: document.getElementById("operationDrawerTitle"), form: document.getElementById("operationForm"), id: document.getElementById("operationId"),
        date: document.getElementById("operationDate"), room: document.getElementById("operationRoom"), patient: document.getElementById("operationPatient"),
        diagnostic: document.getElementById("operationDiagnostic"), type: document.getElementById("operationType"), doctor: document.getElementById("operationDoctor"),
        notes: document.getElementById("operationNotes"), extra: document.getElementById("operationExtra"), completed: document.getElementById("operationCompleted"),
        remove: document.getElementById("operationDelete"),
    };
    const state = { data: null, selectedDate: null, editing: null };

    el.month.addEventListener("change", load);
    el.prev.addEventListener("click", () => shiftMonth(-1));
    el.next.addEventListener("click", () => shiftMonth(1));
    el.add.addEventListener("click", () => openOperation(null));
    el.form.addEventListener("submit", saveOperation);
    el.remove.addEventListener("click", deleteOperation);
    document.querySelectorAll("[data-close-operation]").forEach((button) => button.addEventListener("click", closeDrawer));

    async function load() {
        setStatus("Se incarca programul...");
        try {
            const response = await fetch(`api/progop.php?month=${encodeURIComponent(el.month.value)}`, { cache: "no-store" });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || "Programul nu a putut fi incarcat.");
            state.data = data;
            el.add.hidden = !data.canCreate;
            const datesWithOperations = data.operations.map((item) => item.scheduled_date).sort();
            if (!state.selectedDate || !state.selectedDate.startsWith(el.month.value)) {
                state.selectedDate = datesWithOperations[0] || `${el.month.value}-01`;
            }
            render();
            setStatus(`${data.operations.length} interventii in ${monthLabel(data.month)} · ${data.rooms.length} sali active`);
        } catch (error) {
            setStatus(error.message, true);
            el.calendar.innerHTML = "";
            el.rooms.innerHTML = "";
        }
    }

    function render() { renderCalendar(); renderDay(); }

    function renderCalendar() {
        const [year, month] = el.month.value.split("-").map(Number);
        const days = new Date(year, month, 0).getDate();
        const firstOffset = (new Date(year, month - 1, 1).getDay() + 6) % 7;
        const operations = groupBy(state.data.operations, "scheduled_date");
        const ownGuardDates = new Set(state.data.guards.filter((guard) => String(guard.person_id) === String(state.data.currentPersonId)).map((guard) => guard.guard_date));
        const html = [];
        for (let index = 0; index < firstOffset; index++) html.push('<div class="calendar-spacer"></div>');
        for (let day = 1; day <= days; day++) {
            const date = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
            const weekday = new Date(`${date}T12:00:00`).getDay();
            const count = (operations[date] || []).length;
            const classes = ["progop-day"];
            if (weekday === 0 || weekday === 6) classes.push("weekend");
            if (date === state.selectedDate) classes.push("selected");
            if (count) classes.push("has-operations");
            if (ownGuardDates.has(date)) classes.push("own-guard");
            html.push(`<button type="button" class="${classes.join(" ")}" data-date="${date}"><span class="progop-day-number">${day}</span><span class="progop-day-count">${count} int.</span></button>`);
        }
        el.calendar.innerHTML = html.join("");
        el.calendar.querySelectorAll("[data-date]").forEach((button) => button.addEventListener("click", () => { state.selectedDate = button.dataset.date; render(); }));
    }

    function renderDay() {
        const operations = state.data.operations.filter((item) => item.scheduled_date === state.selectedDate);
        const guards = state.data.guards.filter((item) => item.guard_date === state.selectedDate);
        const blocks = Object.fromEntries(state.data.blocks.filter((item) => item.block_date === state.selectedDate).map((item) => [String(item.room_id), item]));
        el.dayTitle.textContent = formatDate(state.selectedDate);
        el.dayTotal.textContent = `${operations.length} interventii`;
        el.dayGuards.innerHTML = guards.length
            ? guards.map((guard) => `<span class="day-guard-chip">${escapeHtml(guard.specialty)} / L${guard.line_no} · ${escapeHtml(guard.display_name)}</span>`).join("")
            : '<span class="day-guard-empty">Nu exista medici de garda introdusi.</span>';

        el.rooms.innerHTML = state.data.rooms.map((room) => {
            const roomOperations = operations.filter((operation) => String(operation.room_id) === String(room.id));
            const block = blocks[String(room.id)];
            const cards = roomOperations.length ? roomOperations.map((operation) => {
                const completed = operation.status === "completed";
                return `<button type="button" class="operation-card${completed ? " completed" : ""}" data-operation="${operation.id}"><strong>${escapeHtml(operation.patient_name)}</strong><span>${escapeHtml(operation.operation_type || "Nespecificat")} · ${escapeHtml(operation.doctor_name)}</span>${operation.is_extra == 1 ? "<em>EXTRA</em>" : ""}</button>`;
            }).join("") : '<div class="empty-room">Nu exista interventii.</div>';
            const blockAction = state.data.canManageAll
                ? `<button type="button" class="ghost-button room-block-action" data-room="${room.id}" data-block-id="${block?.id || ""}">${block ? "Deblocheaza" : "Blocheaza"}</button>` : "";
            return `<article class="room-card${block ? " blocked" : ""}"><header class="room-header"><div><h3>${escapeHtml(room.name)}</h3><span>${block ? escapeHtml(block.reason || "Sala blocata") : `${roomOperations.length} programari`}</span></div>${blockAction}</header><div class="operation-list">${cards}</div></article>`;
        }).join("");

        el.rooms.querySelectorAll("[data-operation]").forEach((button) => button.addEventListener("click", () => {
            const operation = state.data.operations.find((item) => String(item.id) === button.dataset.operation);
            openOperation(operation);
        }));
        el.rooms.querySelectorAll("[data-room]").forEach((button) => button.addEventListener("click", () => toggleRoomBlock(button)));
    }

    function openOperation(operation) {
        if (!operation && !state.data.canCreate) return;
        if (operation && !canEdit(operation)) {
            window.alert("Aceasta interventie poate fi doar vizualizata cu drepturile tale actuale.");
        }
        state.editing = operation;
        const editable = !operation || canEdit(operation);
        el.drawerTitle.textContent = operation ? "Modifica interventia" : "Interventie noua";
        el.id.value = operation?.id || "";
        el.date.value = operation?.scheduled_date || state.selectedDate;
        fillSelect(el.room, state.data.rooms, "id", "name", operation?.room_id);
        fillSelect(el.type, state.data.operationTypes, "id", "name", operation?.operation_type_id);
        fillSelect(el.doctor, state.data.doctors, "id", "display_name", operation?.scheduled_doctor_id);
        el.patient.value = operation?.patient_name || "";
        el.diagnostic.value = operation?.diagnostic || "";
        el.notes.value = operation?.custom_notes || "";
        el.extra.checked = Number(operation?.is_extra || 0) === 1;
        el.completed.checked = operation?.status === "completed";
        [...el.form.elements].forEach((field) => { if (field.type !== "hidden" && field.type !== "button") field.disabled = !editable; });
        el.remove.hidden = !operation || !editable;
        el.drawer.classList.add("open");
        el.drawer.setAttribute("aria-hidden", "false");
    }

    async function saveOperation(event) {
        event.preventDefault();
        const wasCompleted = state.editing?.status === "completed";
        const payload = {
            action: state.editing ? "update_operation" : "add_operation", id: state.editing?.id || undefined,
            date: el.date.value, room_id: Number(el.room.value), patient_name: el.patient.value,
            diagnostic: el.diagnostic.value, operation_type_id: Number(el.type.value), doctor_id: Number(el.doctor.value),
            notes: el.notes.value, is_extra: el.extra.checked, extra_reason: el.extra.checked ? "Adaugata ulterior" : "",
        };
        try {
            const result = await mutate(payload, false);
            const operationId = state.editing?.id || result.id;
            if (el.completed.checked !== wasCompleted) {
                await mutate({ action: "toggle_completed", id: Number(operationId), completed: el.completed.checked }, false);
            }
            state.selectedDate = el.date.value;
            closeDrawer();
            await load();
        } catch (error) { window.alert(error.message); }
    }

    async function deleteOperation() {
        if (!state.editing || !window.confirm("Stergi aceasta interventie din program? Ea va ramane in audit.")) return;
        try { await mutate({ action: "delete_operation", id: Number(state.editing.id) }, false); closeDrawer(); await load(); }
        catch (error) { window.alert(error.message); }
    }

    async function toggleRoomBlock(button) {
        try {
            if (button.dataset.blockId) {
                await mutate({ action: "unblock_room", block_id: Number(button.dataset.blockId) }, false);
            } else {
                const reason = window.prompt("Motivul blocarii salii (optional):", "") ?? null;
                if (reason === null) return;
                await mutate({ action: "block_room", date: state.selectedDate, room_id: Number(button.dataset.room), reason }, false);
            }
            await load();
        } catch (error) { window.alert(error.message); }
    }

    async function mutate(payload) {
        const response = await fetch("api/progop.php", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(payload) });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || "Modificarea nu a putut fi salvata.");
        return data;
    }

    function canEdit(operation) { return state.data.canManageAll || (state.data.canEditOwn && String(operation.created_by) === String(state.data.currentUserId)); }
    function closeDrawer() { el.drawer.classList.remove("open"); el.drawer.setAttribute("aria-hidden", "true"); }
    function fillSelect(select, items, valueKey, labelKey, selected) { select.innerHTML = items.map((item) => `<option value="${item[valueKey]}"${String(item[valueKey]) === String(selected) ? " selected" : ""}>${escapeHtml(item[labelKey])}</option>`).join(""); }
    function shiftMonth(delta) { const [y, m] = el.month.value.split("-").map(Number); const date = new Date(y, m - 1 + delta, 1); el.month.value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`; state.selectedDate = null; load(); }
    function setStatus(message, error = false) { el.status.textContent = message; el.status.classList.toggle("error", error); }
    function groupBy(items, key) { return items.reduce((result, item) => { (result[item[key]] ||= []).push(item); return result; }, {}); }
    function monthLabel(value) { return new Intl.DateTimeFormat("ro-RO", { month: "long", year: "numeric" }).format(new Date(`${value}-01T12:00:00`)); }
    function formatDate(value) { return new Intl.DateTimeFormat("ro-RO", { weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(new Date(`${value}T12:00:00`)); }
    function escapeHtml(value) { return String(value ?? "").replace(/[&<>'"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" })[char]); }

    load();
})();
