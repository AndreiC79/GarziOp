(() => {
    const root = document.getElementById("adminApp");
    if (!root) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const el = {
        lineForm: document.getElementById("guardLineConfigurationForm"), status: document.getElementById("adminSaveStatus"),
        guardLinesMonth: document.getElementById("adminGuardLinesMonth"), ogLine1Protected: document.getElementById("adminOgLine1Protected"), protectionHelp: document.getElementById("adminProtectionHelp"), generatorCard: document.getElementById("adminOgGenerator"),
        generationForm: document.getElementById("adminGenerationForm"), generationStart: document.getElementById("adminGenerationStart"), generationDoctor: document.getElementById("adminGenerationDoctor"), generationMonths: document.getElementById("adminGenerationMonths"), generationStatus: document.getElementById("adminGenerationStatus"), publishGeneration: document.getElementById("adminPublishGeneration"), rotationList: document.getElementById("adminRotationList"), rotationCandidate: document.getElementById("adminRotationCandidate"), rotationAdd: document.getElementById("adminRotationAdd"),
        guardParticipationCard: document.getElementById("adminGuardParticipationCard"), guardParticipationSpecialty: document.getElementById("adminGuardParticipationSpecialty"), guardParticipationCategory: document.getElementById("adminGuardParticipationCategory"), guardParticipationSearch: document.getElementById("adminGuardParticipationSearch"), guardParticipationList: document.getElementById("adminGuardParticipationList"),
        list: document.getElementById("adminUsersList"), search: document.getElementById("adminPeopleSearch"), specialtyFilter: document.getElementById("adminPeopleSpecialty"), peopleSort: document.getElementById("adminPeopleSort"), userReport: document.getElementById("adminUserReport"), dashboardAccount: document.getElementById("adminDashboardAccount"), addPerson: document.getElementById("adminAddPerson"),
        personDrawer: document.getElementById("adminPersonDrawer"), personForm: document.getElementById("adminPersonForm"), personTitle: document.getElementById("adminPersonTitle"),
        personId: document.getElementById("adminPersonId"), firstName: document.getElementById("adminFirstName"), lastName: document.getElementById("adminLastName"), displayName: document.getElementById("adminDisplayName"), title: document.getElementById("adminTitle"), category: document.getElementById("adminCategory"), specialty: document.getElementById("adminSpecialty"), rotation: document.getElementById("adminRotation"), active: document.getElementById("adminPersonActive"), guardEligible: document.getElementById("adminGuardEligible"), notes: document.getElementById("adminPersonNotes"),
        accountDrawer: document.getElementById("adminAccountDrawer"), accountForm: document.getElementById("adminAccountForm"), accountTitle: document.getElementById("adminAccountTitle"), accountPersonId: document.getElementById("adminAccountPersonId"), userId: document.getElementById("adminUserId"), accountPreset: document.getElementById("adminAccountPreset"), username: document.getElementById("adminUsername"), userStatus: document.getElementById("adminUserStatus"), userApiAccess: document.getElementById("adminUserApiAccess"), temporaryPassword: document.getElementById("adminTemporaryPassword"), roleOptions: document.getElementById("adminRoleOptions"), permissionsSection: document.getElementById("adminPermissionsSection"), permissionsForm: document.getElementById("adminPermissionsForm"), permissionOptions: document.getElementById("adminPermissionOptions"), resetPassword: document.getElementById("adminResetPassword"), temporaryResult: document.getElementById("adminTemporaryResult"), accountTokens: document.getElementById("adminAccountTokens"), accountTokenList: document.getElementById("adminAccountTokenList"),
        auditCard: document.getElementById("adminAuditCard"), auditRefresh: document.getElementById("adminAuditRefresh"), auditSearch: document.getElementById("adminAuditSearch"), auditModule: document.getElementById("adminAuditModule"), auditList: document.getElementById("adminAuditList"), connectedList: document.getElementById("adminConnectedList"), connectedUsers: document.getElementById("adminConnectedUsers"), connectedSessions: document.getElementById("adminConnectedSessions"), actionsToday: document.getElementById("adminActionsToday"), activeAccounts: document.getElementById("adminActiveAccounts"),
        backupCard: document.getElementById("adminBackupCard"), guardVersionList: document.getElementById("adminGuardVersionList"), backupList: document.getElementById("adminBackupList"), createProgOpBackup: document.getElementById("adminCreateProgOpBackup"), backupTokenForm: document.getElementById("adminBackupTokenForm"), backupTokenOwner: document.getElementById("adminBackupTokenOwner"), backupTokenExpiry: document.getElementById("adminBackupTokenExpiry"), backupTokenResult: document.getElementById("adminBackupTokenResult"),
        reportsCard: document.getElementById("adminReportsCard"), reportsForm: document.getElementById("adminReportsForm"), reportKind: document.getElementById("adminReportKind"), reportPeriod: document.getElementById("adminReportPeriod"), reportMonth: document.getElementById("adminReportMonth"), reportMonthLabel: document.getElementById("adminReportMonthLabel"), reportYear: document.getElementById("adminReportYear"), reportYearLabel: document.getElementById("adminReportYearLabel"), reportSpecialty: document.getElementById("adminReportSpecialty"), reportSpecialtyLabel: document.getElementById("adminReportSpecialtyLabel"), reportDoctor: document.getElementById("adminReportDoctor"), reportPdf: document.getElementById("adminReportPdf"), reportXlsx: document.getElementById("adminReportXlsx"), reportStatus: document.getElementById("adminReportStatus"), reportPreview: document.getElementById("adminReportPreview"),
        apiAccess: document.getElementById("adminApiAccess"), apiBaseUrl: document.getElementById("adminApiBaseUrl"), apiTokenForm: document.getElementById("adminApiTokenForm"), apiTokenOwner: document.getElementById("adminApiTokenOwner"), apiTokenName: document.getElementById("adminApiTokenName"), apiTokenExpiry: document.getElementById("adminApiTokenExpiry"), apiAccessPreset: document.getElementById("adminApiAccessPreset"), apiPermissionOptions: document.getElementById("adminApiPermissionOptions"), apiTokenResult: document.getElementById("adminApiTokenResult"), apiTokenList: document.getElementById("adminApiTokenList"),
    };
    const lineSelects = { OG: document.getElementById("adminLinesOG"), ATI: document.getElementById("adminLinesATI"), NN: document.getElementById("adminLinesNN") };
    const extraLineToggles = { OG: document.getElementById("adminExtraLineOG"), ATI: document.getElementById("adminExtraLineATI"), NN: document.getElementById("adminExtraLineNN") };
    const state = { data: null, search: "", specialty: "", peopleSort: "account_type", guardParticipationSearch: "", auditSearch: "", auditModule: "", person: null };
    const lineOptions = Array.from({ length: 8 }, (_, index) => `<option value="${index + 1}">${index + 1}</option>`).join("");
    Object.values(lineSelects).forEach((select) => { select.innerHTML = lineOptions; });
    el.guardLinesMonth.value = new Date().toISOString().slice(0, 7);
    createAdminGroup(el.lineForm,"Configurare linii de garda","OG, ATI si NN");
    createAdminGroup(el.generatorCard,"Generare OG Linia 1","Ordine, draft si publicare");
    createAdminGroup(el.guardParticipationCard,"Participare la garzi","OG implicit; pregatit pentru ATI si NN");
createAdminGroup(el.list.closest(".admin-users-card"),"Personal, conturi si drepturi","Medici, rezidenti si asistente");
createAdminGroup(el.reportsCard,"Statistici si rapoarte","Lunar si anual, PDF si Excel");
    createAdminGroup(el.backupCard,"Versiuni si backup","Restaurare garzi si copii Program operator");
    createAdminGroup(el.auditCard,"Jurnal si conexiuni","Vizibil numai administratorilor");
    createAdminGroup(el.apiAccess,"Acces API / AI","Tokenuri pe fiecare cont");

    el.lineForm.addEventListener("submit", saveLines);
    el.guardLinesMonth.addEventListener("change", load);
    Object.values(extraLineToggles).forEach((toggle) => toggle.addEventListener("change", renderRotation));
    el.generationForm.addEventListener("submit", generateOgLine1);
    el.publishGeneration.addEventListener("click", publishOgLine1);
    el.rotationAdd.addEventListener("click", addRotationPerson);
    el.guardParticipationSpecialty.addEventListener("change", renderGuardParticipationList);
    el.guardParticipationCategory.addEventListener("change", renderGuardParticipationList);
    el.guardParticipationSearch.addEventListener("input", () => { state.guardParticipationSearch = el.guardParticipationSearch.value.trim().toLocaleLowerCase("ro-RO"); renderGuardParticipationList(); });
    el.search.addEventListener("input", () => { state.search = el.search.value.trim().toLocaleLowerCase("ro-RO"); renderPeople(); });
    el.specialtyFilter.addEventListener("change", () => { state.specialty = el.specialtyFilter.value; renderPeople(); });
    el.peopleSort.addEventListener("change", () => { state.peopleSort = el.peopleSort.value; renderPeople(); });
    el.userReport.addEventListener("click", () => { window.location.href = "api/admin-user-report.php"; });
    el.dashboardAccount.addEventListener("click", openDashboardAccount);
    el.addPerson.addEventListener("click", () => openPerson(null));
    el.firstName.addEventListener("input", syncDisplayName);
    el.lastName.addEventListener("input", syncDisplayName);
    el.specialty.addEventListener("change", () => {
        if (el.specialty.value === "ASISTENTA") {
            el.category.value = "Asistenta";
            el.guardEligible.checked = false;
        }
    });
    el.personForm.addEventListener("submit", savePerson);
    el.accountForm.addEventListener("submit", saveAccount);
    el.accountPreset.addEventListener("change", applyAccountPreset);
    el.permissionsForm.addEventListener("submit", savePermissions);
    el.resetPassword.addEventListener("click", resetPassword);
    el.apiTokenForm.addEventListener("submit", createApiToken);
    el.apiAccessPreset.addEventListener("change", applyApiAccessPreset);
    el.apiTokenOwner.addEventListener("change",applyApiAccessPreset);
    el.auditRefresh.addEventListener("click", load);
    el.createProgOpBackup.addEventListener("click", createProgOpBackup);
    el.backupTokenForm.addEventListener("submit", createBackupToken);
    el.reportsForm.addEventListener("submit", loadStatisticsReport);
    el.reportKind.addEventListener("change", syncReportFilters);
    el.reportPeriod.addEventListener("change", syncReportFilters);
    el.reportPdf.addEventListener("click", () => downloadStatisticsReport("pdf"));
    el.reportXlsx.addEventListener("click", () => downloadStatisticsReport("xlsx"));
    let auditSearchTimer = null;
    el.auditSearch.addEventListener("input", () => { state.auditSearch = el.auditSearch.value.trim().toLocaleLowerCase("ro-RO"); clearTimeout(auditSearchTimer); auditSearchTimer = setTimeout(load, 300); });
    el.auditModule.addEventListener("change", () => { state.auditModule = el.auditModule.value; load(); });
    document.querySelectorAll("[data-close-admin-person]").forEach((button) => button.addEventListener("click", () => closeDrawer(el.personDrawer)));
    document.querySelectorAll("[data-close-admin-account]").forEach((button) => button.addEventListener("click", () => closeDrawer(el.accountDrawer)));

    async function load() {
        setStatus("Se incarca...");
        try {
            const query = new URLSearchParams();
            query.set("line_month", el.guardLinesMonth.value);
            if (state.auditSearch) query.set("audit_search", state.auditSearch);
            if (state.auditModule) query.set("audit_module", state.auditModule);
            const response = await fetch(`api/admin.php${query.toString() ? `?${query}` : ""}`, { cache: "no-store" });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || "Configuratia nu a putut fi incarcata.");
            state.data = data;
            el.userReport.hidden = !data.canManageUsers;
            el.dashboardAccount.hidden = !data.canManageUsers;
            el.reportsCard.hidden = !data.canGenerateReports;
            if (el.reportsCard.closest("details")) el.reportsCard.closest("details").hidden = !data.canGenerateReports;
            if (data.canGenerateReports) initializeReportFilters();
            Object.entries(lineSelects).forEach(([specialty, select]) => { select.value = String(data.guardLineConfiguration[specialty]?.line_count || 1); });
            Object.entries(extraLineToggles).forEach(([specialty, toggle]) => { toggle.checked = Boolean(data.guardLineConfiguration[specialty]?.extra_enabled); });
            el.ogLine1Protected.checked = Boolean(data.guardLineConfiguration.OG?.line1_protected);
            renderPeople(); renderRotation(); renderGuardParticipationList(); renderBackups(); renderAudit(); renderApiTokens(); renderBackupTokenForm(); setStatus("Configuratie activa");
        } catch (error) { setStatus(error.message, true); }
    }

    function renderRotation() {
        const rotation = state.data.ogRotation || [];
        const protectedLine = Boolean(state.data.guardLineConfiguration.OG?.line1_protected);
        el.generatorCard.classList.toggle("generator-disabled", !protectedLine);
        const month = el.guardLinesMonth.value || state.data.guardLineMonth;
        const extraHelp = extraLineToggles.OG.checked
            ? " Pentru OG, linia suplimentară este activată și se va deschide după completarea integrală a liniilor de bază."
            : " Pentru OG, linia suplimentară este dezactivată.";
        el.protectionHelp.textContent = (protectedLine
            ? "OG Linia 1 este protejată: se modifică numai prin schimb și preluare."
            : "OG Linia 1 este liberă și se completează direct.") + ` Luna selectată: ${month}.` + extraHelp;
        el.rotationList.innerHTML = rotation.length ? rotation.map((person, index) => `<article class="rotation-row"><b>${index + 1}</b><span>${escapeHtml(person.display_name)}</span><div><button type="button" class="ghost-button" data-rotation-up="${person.id}"${index === 0 ? " disabled" : ""}>Sus</button><button type="button" class="ghost-button" data-rotation-down="${person.id}"${index === rotation.length - 1 ? " disabled" : ""}>Jos</button><button type="button" class="danger-button" data-rotation-remove="${person.id}">Scoate</button></div></article>`).join("") : '<div class="admin-users-empty">Lista de generare este goala.</div>';
        el.generationDoctor.innerHTML = rotation.map((person) => `<option value="${person.id}">${escapeHtml(person.display_name)}</option>`).join("");
        const candidates = state.data.ogRotationCandidates || [];
        el.rotationCandidate.innerHTML = candidates.length ? candidates.map((person) => `<option value="${person.id}">${escapeHtml(person.display_name)}</option>`).join("") : '<option value="">Toti medicii eligibili sunt inclusi</option>';
        el.rotationAdd.disabled = !candidates.length;
        el.rotationList.querySelectorAll("[data-rotation-up]").forEach((button) => button.addEventListener("click", () => moveRotation(button.dataset.rotationUp, -1)));
        el.rotationList.querySelectorAll("[data-rotation-down]").forEach((button) => button.addEventListener("click", () => moveRotation(button.dataset.rotationDown, 1)));
        el.rotationList.querySelectorAll("[data-rotation-remove]").forEach((button) => button.addEventListener("click", () => removeRotation(button.dataset.rotationRemove)));
        const batch = state.data.latestOgGeneration;
        el.generationStatus.textContent = !protectedLine ? "Generare dezactivata" : (batch ? `${batch.status === "draft" ? "Draft" : "Publicat"}: ${formatDate(batch.start_date)} - ${formatDate(batch.end_date)}` : "Niciun draft");
        el.publishGeneration.hidden = !protectedLine || !batch || batch.status !== "draft" || !state.data.canPublishGuards;
        el.generationForm.querySelector('[type="submit"]').disabled = !protectedLine || !state.data.canGenerateGuards || !rotation.length;
        if (!el.generationStart.value) el.generationStart.value = today();
    }

    function guardParticipationPeople() {
        const specialty = el.guardParticipationSpecialty.value || "OG";
        const category = el.guardParticipationCategory.value || "all";
        return (state.data.people || []).filter((person) => {
            if (!person.user_id || Number(person.active) !== 1 || person.specialty !== specialty) return false;
            const resident = /rezident/i.test(person.professional_category || "");
            if (category === "resident" && !resident) return false;
            if (category === "specialist" && resident) return false;
            const searchable = `${person.display_name || ""} ${person.username || ""}`.toLocaleLowerCase("ro-RO");
            if (state.guardParticipationSearch && !searchable.includes(state.guardParticipationSearch)) return false;
            return true;
        }).sort((left, right) => Number(right.guard_eligible) - Number(left.guard_eligible)
            || String(left.last_name || "").localeCompare(String(right.last_name || ""), "ro")
            || String(left.first_name || "").localeCompare(String(right.first_name || ""), "ro"));
    }

    function renderGuardParticipationList() {
        const people = guardParticipationPeople();
        el.guardParticipationList.innerHTML = people.length ? people.map((person) => {
            const excluded = Number(person.guard_eligible) !== 1;
            return `<article class="guard-participation-row${excluded ? " excluded" : ""}">
                <span class="admin-user-avatar">${escapeHtml(initials(person.display_name))}</span>
                <div><strong>${escapeHtml(person.display_name)}</strong><small>${escapeHtml(person.professional_category || "Medic")} · ${escapeHtml(person.username)}</small></div>
                <span class="guard-participation-state">${excluded ? "Nu apare in Garzi" : "Participa"}</span>
                <label><input type="checkbox" data-no-guards="${person.id}"${excluded ? " checked" : ""}> Nu face garzi</label>
            </article>`;
        }).join("") : '<div class="admin-users-empty">Nu exista medici cu cont pentru filtrul selectat.</div>';
        el.guardParticipationList.querySelectorAll("[data-no-guards]").forEach((checkbox) => checkbox.addEventListener("change", () => saveGuardParticipationRow(checkbox)));
    }

    async function saveGuardParticipationRow(checkbox) {
        const personId = Number(checkbox.dataset.noGuards);
        checkbox.disabled = true;
        try {
            await mutate({ action: "save_guard_eligibility", person_id: personId, guard_eligible: !checkbox.checked });
            await load();
        } catch (error) {
            checkbox.checked = !checkbox.checked;
            checkbox.disabled = false;
        }
    }

    async function saveRotation(ids) {
        await mutate({ action: "save_og_rotation", person_ids: ids });
        await load();
    }

    function moveRotation(personId, direction) {
        const ids = state.data.ogRotation.map((person) => Number(person.id));
        const index = ids.indexOf(Number(personId));
        const target = index + direction;
        if (index < 0 || target < 0 || target >= ids.length) return;
        [ids[index], ids[target]] = [ids[target], ids[index]];
        saveRotation(ids);
    }

    function removeRotation(personId) {
        const ids = state.data.ogRotation.map((person) => Number(person.id)).filter((id) => id !== Number(personId));
        saveRotation(ids);
    }

    function addRotationPerson() {
        if (!el.rotationCandidate.value) return;
        const ids = state.data.ogRotation.map((person) => Number(person.id));
        ids.push(Number(el.rotationCandidate.value));
        saveRotation(ids);
    }

    async function generateOgLine1(event) {
        event.preventDefault();
        await mutate({ action: "generate_og_line1", start_date: el.generationStart.value, start_person_id: Number(el.generationDoctor.value), months: Number(el.generationMonths.value) });
        await load();
    }

    async function publishOgLine1() {
        const batch = state.data.latestOgGeneration;
        if (!batch || batch.status !== "draft") return;
        if (!window.confirm(`Publici programul OG Linia 1 pentru ${formatDate(batch.start_date)} - ${formatDate(batch.end_date)}?`)) return;
        await mutate({ action: "publish_og_line1", batch_id: Number(batch.id) });
        await load();
    }

    function renderPeopleLegacy() {
        const people = state.data.people.filter((person) => {
            const categoryMatches = !state.specialty || (state.specialty === "RESIDENTS" ? /rezident/i.test(person.professional_category || "") : person.specialty === state.specialty);
            return categoryMatches && (!state.search || `${person.display_name} ${person.username || ""} ${person.title || ""}`.toLocaleLowerCase("ro-RO").includes(state.search));
        });
        el.list.innerHTML = people.length ? people.map((person) => {
            const roles = state.data.userRoles[person.user_id] || [];
            return `<article class="admin-user-row${Number(person.active) ? "" : " inactive"}"><span class="admin-user-avatar">${escapeHtml(initials(person.display_name))}</span><div class="admin-user-identity"><strong>${escapeHtml([person.title, person.display_name].filter(Boolean).join(" "))}</strong><span>${escapeHtml(person.professional_category || "Medic")} · ${escapeHtml(person.specialty)}${Number(person.guard_eligible) ? " · garzi" : " · fara garzi"}</span></div><div class="admin-user-account"><b>${escapeHtml(person.username || "Fara cont")}</b><span>${person.user_id ? escapeHtml(person.user_status) : "doar persoana"}</span></div><div class="admin-user-roles">${roles.length ? roles.map((role) => `<i>${escapeHtml(role)}</i>`).join("") : "-"}</div><div class="admin-user-actions"><button type="button" class="ghost-button" data-edit-person="${person.id}">Date</button><button type="button" class="primary-button" data-edit-account="${person.id}">${person.user_id ? "Cont si drepturi" : "Creeaza cont"}</button>${Number(person.active) && Number(person.id) !== Number(state.data.currentPersonId) ? `<button type="button" class="danger-button" data-delete-person="${person.id}">Sterge</button>` : ""}</div></article>`;
        }).join("") : '<div class="admin-users-empty">Nu exista persoane pentru filtrul selectat.</div>';
        el.list.querySelectorAll("[data-edit-person]").forEach((button) => button.addEventListener("click", () => openPerson(findPerson(button.dataset.editPerson))));
        el.list.querySelectorAll("[data-edit-account]").forEach((button) => button.addEventListener("click", () => openAccount(findPerson(button.dataset.editAccount))));
        el.list.querySelectorAll("[data-delete-person]").forEach((button) => button.addEventListener("click", () => deletePerson(findPerson(button.dataset.deletePerson))));
    }

    const accountRolePriority = ["platform_admin", "super_admin", "operating_room_dashboard", "doctor_admin", "guards_admin", "doctor_ati", "doctor_nn", "doctor_standard", "doctor_og", "assistant_editor", "assistant_viewer"];

    function roleLabel(code) {
        return (state.data.roles || []).find((role) => role.code === code)?.name || String(code || "Rol personalizat").replaceAll("_", " ");
    }

    function accountType(person) {
        if (!person.user_id) return { code: "no_account", label: "Fara cont", order: 99 };
        const roles = state.data.userRoles[person.user_id] || [];
        const code = accountRolePriority.find((candidate) => roles.includes(candidate)) || roles[0] || "custom";
        const priority = accountRolePriority.indexOf(code);
        return { code, label: roleLabel(code), order: priority < 0 ? 90 : priority };
    }

    function comparePeople(left, right) {
        const byName = String(left.last_name || left.display_name || "").localeCompare(String(right.last_name || right.display_name || ""), "ro")
            || String(left.first_name || "").localeCompare(String(right.first_name || ""), "ro");
        if (state.peopleSort === "name") return byName;
        if (state.peopleSort === "specialty") return String(left.specialty || "").localeCompare(String(right.specialty || ""), "ro") || byName;
        const leftType = accountType(left);
        const rightType = accountType(right);
        return leftType.order - rightType.order || leftType.label.localeCompare(rightType.label, "ro") || byName;
    }

    function renderPersonRow(person) {
        const roles = state.data.userRoles[person.user_id] || [];
        const systemAccount = Number(person.system_account) === 1;
        const participation = systemAccount ? " &middot; acces dedicat panoului" : Number(person.guard_eligible) ? " &middot; garzi" : " &middot; fara garzi";
        return `<article class="admin-user-row${Number(person.active) ? "" : " inactive"}"><span class="admin-user-avatar">${escapeHtml(initials(person.display_name))}</span><div class="admin-user-identity"><strong>${escapeHtml([person.title, person.display_name].filter(Boolean).join(" "))}</strong><span>${escapeHtml(person.professional_category || "Medic")} &middot; ${escapeHtml(person.specialty)}${participation}</span></div><div class="admin-user-account"><b>${escapeHtml(person.username || "Fara cont")}</b><span>${person.user_id ? escapeHtml(person.user_status) : "doar persoana"}</span></div><div class="admin-user-roles">${roles.length ? roles.map((role) => `<i>${escapeHtml(roleLabel(role))}</i>`).join("") : "-"}</div><div class="admin-user-actions">${systemAccount ? "" : `<button type="button" class="ghost-button" data-edit-person="${person.id}">Date</button>`}<button type="button" class="primary-button" data-edit-account="${person.id}">${person.user_id ? "Cont si drepturi" : "Creeaza cont"}</button>${!systemAccount && Number(person.active) && Number(person.id) !== Number(state.data.currentPersonId) ? `<button type="button" class="danger-button" data-delete-person="${person.id}">Sterge</button>` : ""}</div></article>`;
    }

    function renderPeople() {
        const people = state.data.people.filter((person) => {
            const categoryMatches = !state.specialty || (state.specialty === "RESIDENTS" ? /rezident/i.test(person.professional_category || "") : person.specialty === state.specialty);
            return categoryMatches && (!state.search || `${person.display_name} ${person.username || ""} ${person.title || ""}`.toLocaleLowerCase("ro-RO").includes(state.search));
        }).sort(comparePeople);
        if (!people.length) {
            el.list.innerHTML = '<div class="admin-users-empty">Nu exista persoane pentru filtrul selectat.</div>';
        } else if (state.peopleSort === "account_type") {
            const groups = [];
            people.forEach((person) => {
                const type = accountType(person);
                let group = groups.find((item) => item.code === type.code);
                if (!group) {
                    group = { code: type.code, label: type.label, people: [] };
                    groups.push(group);
                }
                group.people.push(person);
            });
            el.list.innerHTML = groups.map((group) => `<section class="admin-account-group"><header class="admin-account-group-header"><strong>${escapeHtml(group.label)}</strong><span>${group.people.length} ${group.people.length === 1 ? "persoana" : "persoane"}</span></header>${group.people.map(renderPersonRow).join("")}</section>`).join("");
        } else {
            el.list.innerHTML = people.map(renderPersonRow).join("");
        }
        el.list.querySelectorAll("[data-edit-person]").forEach((button) => button.addEventListener("click", () => openPerson(findPerson(button.dataset.editPerson))));
        el.list.querySelectorAll("[data-edit-account]").forEach((button) => button.addEventListener("click", () => openAccount(findPerson(button.dataset.editAccount))));
        el.list.querySelectorAll("[data-delete-person]").forEach((button) => button.addEventListener("click", () => deletePerson(findPerson(button.dataset.deletePerson))));
    }

    function openPerson(person) {
        state.person = person; el.personTitle.textContent = person ? "Modifica persoana" : "Persoana noua";
        el.personId.value = person?.id || ""; el.firstName.value = person?.first_name || ""; el.lastName.value = person?.last_name || ""; el.displayName.value = person?.display_name || ""; el.title.value = person?.title || ""; el.category.value = person?.professional_category || "Medic"; el.specialty.value = person?.specialty || "OG"; el.rotation.value = person?.rotation_position || ""; el.active.checked = person ? Number(person.active) === 1 : true; el.guardEligible.checked = person ? Number(person.guard_eligible) === 1 : true; el.notes.value = person?.notes || "";
        openDrawer(el.personDrawer);
    }

    function syncDisplayName() { el.displayName.value = `${el.lastName.value.trim()} ${el.firstName.value.trim()}`.trim(); }

    async function savePerson(event) {
        event.preventDefault();
        await mutate({ action: "save_person", person_id: Number(el.personId.value) || 0, first_name: el.firstName.value, last_name: el.lastName.value, display_name: el.displayName.value, title: el.title.value, professional_category: el.category.value, specialty: el.specialty.value, rotation_position: el.rotation.value, active: el.active.checked, guard_eligible: el.guardEligible.checked, notes: el.notes.value });
        closeDrawer(el.personDrawer); await load();
    }

    async function deletePerson(person) {
        if (!person || !window.confirm(`Stergi ${person.display_name}? Daca are istoric, persoana va fi arhivata si contul dezactivat.`)) return;
        await mutate({ action: "delete_person", person_id: Number(person.id) });
        await load();
    }

    function openAccount(person) {
        state.person = person; el.accountTitle.textContent = person.display_name; el.accountPersonId.value = person.id; el.userId.value = person.user_id || ""; el.username.value = person.username || usernameSuggestion(person); el.userStatus.value = person.user_status || "active"; el.userApiAccess.value=person.api_access_level||"none"; el.temporaryPassword.value = ""; el.temporaryResult.hidden = true;
        const assignedRoles = new Set(state.data.userRoles[person.user_id] || []);
        const presetRoles=["platform_admin","super_admin","operating_room_dashboard","doctor_admin","doctor_standard","doctor_ati","doctor_nn","guards_admin","assistant_editor","assistant_viewer"];
        el.accountPreset.value=assignedRoles.size===1&&presetRoles.includes([...assignedRoles][0])?[...assignedRoles][0]:"custom";
        if (el.accountPreset.value === "doctor_standard" && String(person.professional_category || "").toLocaleLowerCase("ro").includes("rezident")) el.accountPreset.value = "doctor_resident";
        const recommended = state.data.roles.filter((role) => role.code !== "doctor_og");
        const legacy = state.data.roles.filter((role) => role.code === "doctor_og");
        el.roleOptions.innerHTML = `<p class="admin-role-intro">Alege, de regula, un singur rol. Participarea la garzi si accesul API se stabilesc separat.</p><div class="admin-role-presets-grid">${recommended.map((role) => roleCard(role, assignedRoles)).join("")}</div>${legacy.length ? `<details class="admin-legacy-roles"><summary>Rol vechi pastrat pentru compatibilitate</summary><div class="admin-role-presets-grid">${legacy.map((role) => roleCard(role, assignedRoles, true)).join("")}</div></details>` : ""}`;
        renderPermissions(person);renderAccountTokens(person); openDrawer(el.accountDrawer);
    }

    function openDashboardAccount() {
        const existing = (state.data.people || []).find((person) => (state.data.userRoles[person.user_id] || []).includes("operating_room_dashboard"));
        if (existing) {
            openAccount(existing);
            return;
        }
        openAccount({ id: 0, display_name: "Dashboard bloc operator", username: "dashboard", specialty: "SISTEM", professional_category: "Cont tehnic", active: 1, guard_eligible: 0, system_account: 1 });
        el.accountPreset.value = "operating_room_dashboard";
        applyAccountPreset();
    }

    function roleCard(role, assignedRoles, legacy = false) {
        const permissions = state.data.rolePermissions?.[role.code] || [];
        const modules = [...new Set(permissions.map((permission) => permission.split(".")[0]))];
        const moduleLabels = { guards: "Garzi", progop: "Program operator", admin: "Administrare", reports: "Rapoarte" };
        return `<label class="admin-role-card${legacy ? " legacy" : ""}"><input type="checkbox" value="${escapeHtml(role.code)}"${assignedRoles.has(role.code) ? " checked" : ""}><span><strong>${escapeHtml(role.name)}</strong><small>${escapeHtml(role.description || "Fara descriere configurata.")}</small><span class="admin-role-scopes">${modules.map((module) => `<i>${escapeHtml(moduleLabels[module] || module)}</i>`).join("")}</span><code>${escapeHtml(role.code)}</code></span></label>`;
    }

    function applyAccountPreset(){if(el.accountPreset.value==="custom")return;const role=el.accountPreset.value==="doctor_resident"?"doctor_standard":el.accountPreset.value;el.roleOptions.querySelectorAll('input[type="checkbox"]').forEach(input=>{input.checked=input.value===role;});if(el.accountPreset.value==="platform_admin"||el.accountPreset.value==="super_admin")el.userApiAccess.value="full";}

    function renderAccountTokens(person){const tokens=(state.data.apiTokens||[]).filter(token=>String(token.user_id)===String(person.user_id));el.accountTokens.hidden=!person.user_id;el.accountTokenList.innerHTML=tokens.length?tokens.map(token=>`<article class="account-token-row"><strong>${escapeHtml(token.name)}</strong><code>${escapeHtml(token.token_prefix)}...</code><span>${token.revoked_at?"Revocat":`Expira ${escapeHtml(formatDateTime(token.expires_at))}`}</span></article>`).join(""):'<div class="admin-users-empty">Acest cont nu are tokenuri.</div>';}

    function renderPermissions(person) {
        const userId = person.user_id; el.permissionsSection.hidden = !userId || !state.data.canManagePermissions;
        if (!userId) { el.permissionOptions.innerHTML = ""; return; }
        const effective = new Set(state.data.effectivePermissions[userId] || []);
        const groups = groupBy(state.data.permissions, "module");
        const moduleLabels = { guards: "Garzi", progop: "Program operator", admin: "Administrare", reports: "Rapoarte" };
        el.permissionOptions.innerHTML = Object.entries(groups).map(([module, permissions]) => `<fieldset><legend>${escapeHtml(moduleLabels[module] || module)}</legend>${permissions.map((permission) => `<label><input type="checkbox" value="${escapeHtml(permission.code)}"${effective.has(permission.code) ? " checked" : ""}> <span><strong>${escapeHtml(permission.name || permission.code)}</strong><small>${escapeHtml(permission.description || "Drept individual fara descriere.")}</small><code>${escapeHtml(permission.code)}</code></span></label>`).join("")}</fieldset>`).join("");
    }

    async function saveAccount(event) {
        event.preventDefault();
        const roles = [...el.roleOptions.querySelectorAll('input:checked')].map((input) => input.value);
        const result = await mutate({ action: "save_user", user_id: Number(el.userId.value) || 0, person_id: Number(el.accountPersonId.value), username: el.username.value, status: el.userStatus.value, api_access_level:el.userApiAccess.value, temporary_password: el.temporaryPassword.value, roles });
        const temporaryPassword = result.temporary_password; closeDrawer(el.accountDrawer); await load();
        if (temporaryPassword) window.alert(`Parola temporară generată: ${temporaryPassword}`);
    }

    async function savePermissions(event) {
        event.preventDefault(); const permissions = [...el.permissionOptions.querySelectorAll('input:checked')].map((input) => input.value);
        await mutate({ action: "save_user_permissions", user_id: Number(el.userId.value), permissions }); closeDrawer(el.accountDrawer); await load();
    }

    async function resetPassword() {
        if (!el.userId.value) return; const result = await mutate({ action: "reset_user_password", user_id: Number(el.userId.value), temporary_password: el.temporaryPassword.value }); showTemporaryPassword(result.temporary_password);
    }

    async function saveLines(event) {
        event.preventDefault();
        const lineCounts = Object.fromEntries(Object.entries(lineSelects).map(([specialty, select]) => [specialty, Number(select.value)]));
        const extraLines = Object.fromEntries(Object.entries(extraLineToggles).map(([specialty, toggle]) => [specialty, toggle.checked]));
        await mutate({ action: "save_guard_line_configuration", month: el.guardLinesMonth.value, line_counts: lineCounts, extra_lines: extraLines, og_line1_protected: el.ogLine1Protected.checked });
        await load();
    }

    function renderAudit() {
        const visible = Boolean(state.data.canViewAudit);
        el.auditCard.hidden = !visible;
        if(el.auditCard.closest("details"))el.auditCard.closest("details").hidden=!visible;
        if (!visible) return;

        const stats = state.data.auditStats || {};
        el.connectedUsers.textContent = String(stats.connected_users || 0);
        el.connectedSessions.textContent = String(stats.connected_sessions || 0);
        el.actionsToday.textContent = String(stats.actions_last_24h || 0);
        el.activeAccounts.textContent = String(stats.active_accounts || 0);

        const connected = state.data.connectedUsers || [];
        el.connectedList.innerHTML = connected.length
            ? connected.map((session) => `<article><span class="connection-dot"></span><div><strong>${escapeHtml(session.display_name || session.username)}</strong><small>${escapeHtml(session.username)} · ${Number(session.session_count)} ${Number(session.session_count) === 1 ? "sesiune" : "sesiuni"} · activ ${escapeHtml(formatDateTime(session.last_activity_at))}</small></div></article>`).join("")
            : '<div class="admin-users-empty">Nu exista utilizatori activi in ultimele 5 minute.</div>';

        const logs = (state.data.auditLogs || []).filter((log) => {
            const module = String(log.action || "").split(".")[0];
            const searchable = `${log.display_name || ""} ${log.username || ""} ${log.action || ""} ${log.entity_type || ""} ${log.detail || ""}`.toLocaleLowerCase("ro-RO");
            return (!state.auditModule || module === state.auditModule) && (!state.auditSearch || searchable.includes(state.auditSearch));
        });
        el.auditList.innerHTML = logs.length ? logs.map((log) => `
            <article class="admin-audit-row">
                <time>${escapeHtml(formatDateTime(log.created_at))}</time>
                <div><strong>${escapeHtml(log.display_name || log.username || "Sistem")}</strong><span>${escapeHtml(log.detail || auditActionLabel(log.action))}</span><small>${escapeHtml(auditActionLabel(log.action))}</small></div>
                <div><b>${escapeHtml(log.entity_type || "-")}${log.entity_id ? ` #${Number(log.entity_id)}` : ""}</b><small>${escapeHtml(log.ip_address || "IP indisponibil")}</small></div>
            </article>`).join("") : '<div class="admin-users-empty">Nu exista actiuni pentru filtrul selectat.</div>';
    }

    function renderBackups() {
        const visible = Boolean(state.data.canViewAudit || state.data.canRestoreGuards);
        el.backupCard.hidden = !visible;
        if (el.backupCard.closest("details")) el.backupCard.closest("details").hidden = !visible;
        if (!visible) return;
        const versions = state.data.guardVersions || [];
        el.guardVersionList.innerHTML = versions.length ? versions.map((version) => {
            const current = Number(version.current_version_id) === Number(version.id);
            return `<article class="admin-version-row${current ? " current" : ""}"><div><strong>${escapeHtml(version.specialty)} L${Number(version.line_no)} · ${escapeHtml(formatDate(version.date_from))} - ${escapeHtml(formatDate(version.date_to))}</strong><span>Versiunea ${Number(version.version_no)} · ${Number(version.assignment_count)} garzi · creata ${escapeHtml(formatDateTime(version.created_at))}${version.created_by_name ? ` de ${escapeHtml(version.created_by_name)}` : ""}</span></div><b>${current ? "ACTIVA" : (version.restored_from_version_id ? "RESTAURATA" : escapeHtml(version.status))}</b>${!current && state.data.canRestoreGuards ? `<button type="button" class="ghost-button" data-restore-guard-version="${Number(version.id)}">Restaureaza</button>` : ""}</article>`;
        }).join("") : '<div class="admin-users-empty">Nu exista versiuni de garzi.</div>';
        el.guardVersionList.querySelectorAll("[data-restore-guard-version]").forEach((button) => button.addEventListener("click", () => restoreGuardVersion(button.dataset.restoreGuardVersion)));
        const files = state.data.backupFiles || [];
        el.backupList.innerHTML = files.length ? files.map((file) => `<article class="admin-backup-row"><div><strong>${escapeHtml(file.name)}</strong><span>${escapeHtml(formatDateTime(file.created_at))} · ${formatFileSize(file.size)}</span></div><a class="ghost-button" href="api/admin-backup.php?file=${encodeURIComponent(file.name)}">Descarca</a><button type="button" class="danger-button" data-delete-backup="${escapeHtml(file.name)}">Sterge</button></article>`).join("") : '<div class="admin-users-empty">Nu exista copii JSON.</div>';
        el.backupList.querySelectorAll("[data-delete-backup]").forEach((button) => button.addEventListener("click", () => deleteProgOpBackup(button.dataset.deleteBackup)));
        el.createProgOpBackup.hidden = !state.data.canViewAudit;
    }

    async function restoreGuardVersion(versionId) {
        const version = (state.data.guardVersions || []).find((item) => String(item.id) === String(versionId));
        if (!version || !window.confirm(`Restaurezi versiunea ${version.version_no} pentru ${version.specialty} L${version.line_no}, perioada ${formatDate(version.date_from)} - ${formatDate(version.date_to)}? Versiunea activa ramane in istoric.`)) return;
        await mutate({ action: "restore_guard_version", version_id: Number(versionId) });
        await load();
    }

    async function createProgOpBackup() {
        await mutate({ action: "create_progop_backup" });
        await load();
    }

    async function deleteProgOpBackup(file) {
        if (!window.confirm(`Stergi definitiv copia JSON ${file}? Aceasta actiune nu poate fi anulata.`)) return;
        await mutate({ action: "delete_progop_backup", file });
        await load();
    }

    function initializeReportFilters() {
        const current = new Date();
        if (!el.reportMonth.value) el.reportMonth.value = `${current.getFullYear()}-${String(current.getMonth() + 1).padStart(2, "0")}`;
        if (!el.reportYear.value) el.reportYear.value = String(current.getFullYear());
        const selectedDoctor = el.reportDoctor.value;
        const doctors = (state.data.people || []).filter((person) => Number(person.active) === 1 && ["OG", "ATI", "NN"].includes(person.specialty) && !/placeholder/i.test(person.professional_category || ""));
        el.reportDoctor.innerHTML = '<option value="">Toti medicii</option>' + doctors.map((person) => `<option value="${person.id}">${escapeHtml(person.display_name)} (${escapeHtml(person.specialty)})</option>`).join("");
        el.reportDoctor.value = selectedDoctor;
        syncReportFilters();
    }

    function syncReportFilters() {
        const daily = el.reportKind.value === "daily_activity";
        const annual = el.reportPeriod.value === "annual";
        el.reportPeriod.closest("label").hidden = daily;
        el.reportMonthLabel.hidden = daily || annual;
        el.reportYearLabel.hidden = daily || !annual;
        el.reportSpecialtyLabel.hidden = daily || el.reportKind.value !== "guards";
        el.reportDoctor.closest("label").hidden = daily;
    }

    function statisticsReportQuery(format = "preview") {
        const monthlyValue = el.reportMonth.value || today().slice(0, 7);
        const [monthlyYear, monthlyMonth] = monthlyValue.split("-");
        const annual = el.reportPeriod.value === "annual";
        return new URLSearchParams({
            report: el.reportKind.value,
            period: el.reportPeriod.value,
            year: annual ? el.reportYear.value : monthlyYear,
            month: annual ? "" : String(Number(monthlyMonth)),
            specialty: el.reportKind.value === "guards" ? el.reportSpecialty.value : "",
            person_id: el.reportDoctor.value,
            format,
        });
    }

    async function loadStatisticsReport(event) {
        event.preventDefault();
        el.reportStatus.textContent = "Se calculeaza statistica...";
        el.reportPreview.classList.add("loading");
        try {
            const response = await fetch(`api/reports.php?${statisticsReportQuery().toString()}`, { cache: "no-store" });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.message || "Raportul nu a putut fi calculat.");
            renderStatisticsReport(data.report);
            el.reportStatus.textContent = `Statistica pentru ${data.report.period.label}. Fara numele pacientelor.`;
        } catch (error) {
            el.reportStatus.textContent = error.message;
            el.reportPreview.innerHTML = `<div class="admin-users-empty">${escapeHtml(error.message)}</div>`;
        } finally {
            el.reportPreview.classList.remove("loading");
        }
    }

    function downloadStatisticsReport(format) {
        window.location.href = `api/reports.php?${statisticsReportQuery(format).toString()}`;
    }

    function renderStatisticsReport(report) {
        const guards = report.kind === "guards";
        const daily = report.kind === "daily_activity";
        if (daily) {
            const metrics = [
                ["Operatii efectuate", report.summary.completed], ["Operatii EXTRA", report.summary.extra],
                ["Medici care au operat", report.summary.doctors], ["Tipuri de operatii", report.summary.types],
            ].map(([label,value]) => `<article><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong><small>${escapeHtml(report.period.label)}</small></article>`).join("");
            const people = report.by_person.map((person) => [person.name,person.completed,person.extra,person.type_breakdown]);
            const types = report.by_type.map((row) => [row.name,row.completed]);
            el.reportPreview.innerHTML = `<div class="admin-report-metrics">${metrics}</div><div class="admin-report-tables"><section><h4>Operatii efectuate pe medici</h4>${reportPreviewTable(["Medic","Total","Extra","Tipuri efectuate"],people)}</section><section><h4>Operatii efectuate pe categorii</h4>${reportPreviewTable(["Tip operatie","Numar"],types)}</section></div>`;
            return;
        }
        const labels = guards ? {
            performed: "Garzi efectuate", scheduled: "Garzi programate", total: "Total garzi", weekend: "Weekend", holidays: "Sarbatori", changed: "Modificate", gaps: "Linii lipsa", coverage: "Acoperire %", consecutive: "Consecutive",
        } : {
            completed: "Operatii efectuate", future: "Programate", unconfirmed: "Neconfirmate", cancelled: "Anulate", extra: "Extra", guard_day: "In zile de garda", confirmation_rate: "Confirmare %", average_lead_days: "Avans mediu",
        };
        const preferred = guards ? ["performed","scheduled","total","weekend","holidays","changed","gaps","coverage","consecutive"] : ["completed","future","unconfirmed","cancelled","extra","guard_day","confirmation_rate","average_lead_days"];
        const metrics = preferred.filter((key) => report.summary[key] !== null && report.summary[key] !== undefined).map((key) => `<article><span>${escapeHtml(labels[key])}</span><strong>${escapeHtml(report.summary[key])}${key === "coverage" || key === "confirmation_rate" ? "%" : ""}</strong><small>${key === "average_lead_days" ? "zile intre introducere si operatie" : report.period.label}</small></article>`).join("");
        const peopleHeaders = guards ? ["Medic","Spec.","Efectuate","Programate","Total","Weekend","Sarbatori","Schimburi","Cedate","Preluate"] : ["Medic","Efectuate","Programate","Neconfirmate","Anulate","Extra","In garda","Tip principal"];
        const peopleRows = report.by_person.map((person) => guards
            ? [person.name,person.specialty,person.performed,person.scheduled,person.total,person.weekend,person.holidays,person.exchanges,person.given,person.taken]
            : [person.name,person.completed,person.future,person.unconfirmed,person.cancelled,person.extra,person.guard_day,person.main_type]);
        const secondary = guards
            ? report.by_month.map((row) => [row.month,row.performed,row.scheduled,row.total])
            : report.by_type.slice(0, 20).map((row) => [row.name,row.completed,row.total,row.unconfirmed,row.cancelled]);
        const secondaryHeaders = guards ? ["Luna","Efectuate","Programate","Total"] : ["Tip operatie","Efectuate","Total","Neconfirmate","Anulate"];
        const warning = !guards && Number(report.summary.unconfirmed) > 0 ? `<div class="admin-report-warning"><strong>${report.summary.unconfirmed} operatii trecute au status neconfirmat.</strong><span>Acestea nu intra in totalul operatiilor efectuate pana cand sunt bifate.</span></div>` : "";
        el.reportPreview.innerHTML = `${warning}<div class="admin-report-metrics">${metrics}</div><div class="admin-report-tables"><section><h4>${guards ? "Garzi pe medici" : "Operatii pe medici"}</h4>${reportPreviewTable(peopleHeaders, peopleRows)}</section><section><h4>${guards ? "Evolutie lunara" : "Tipuri de operatii"}</h4>${reportPreviewTable(secondaryHeaders, secondary)}</section></div>`;
    }

    function reportPreviewTable(headers, rows) {
        return `<div class="admin-report-table-wrap"><table><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join("")}</tr></thead><tbody>${rows.length ? rows.map((row) => `<tr>${row.map((value) => `<td>${escapeHtml(value)}</td>`).join("")}</tr>`).join("") : `<tr><td colspan="${headers.length}">Nu exista date pentru perioada selectata.</td></tr>`}</tbody></table></div>`;
    }

    function formatFileSize(bytes) {
        const value = Number(bytes) || 0;
        return value >= 1048576 ? `${(value / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(value / 1024))} KB`;
    }

    function auditActionLabel(action) {
        const labels = {
            "auth.login": "Autentificare reusita", "auth.logout": "Deconectare", "auth.login_failed": "Autentificare esuata", "auth.password_changed": "Parola schimbata",
            "admin.person_created": "Persoana adaugata", "admin.person_updated": "Date persoana modificate", "admin.person_archived": "Persoana arhivata", "admin.person_deleted": "Persoana stearsa",
            "admin.user_created": "Cont creat", "admin.user_updated": "Cont modificat", "admin.permissions_updated": "Drepturi modificate", "admin.password_reset": "Parola resetata",
            "guards.assignment_added": "Garda adaugata", "guards.assignment_removed": "Garda stearsa", "guards.assignment_deleted": "Garda stearsa", "guards.assignment_replaced": "Garda inlocuita", "guards.admin_correction": "Corectie garda", "guards.exchange": "Schimb de garda", "guards.takeover": "Garda preluata", "guards.transfer": "Garda cedata", "guards.version_restored": "Versiune garzi restaurata",
            "progop.operation_created": "Operatie adaugata", "progop.operation_updated": "Operatie modificata", "progop.operation_deleted": "Operatie stearsa", "progop.operation_moved": "Operatie mutata", "progop.room_blocked": "Sala blocata", "progop.room_unblocked": "Sala deblocata",
            "progop.extra_approval_requested": "Operatie EXTRA trimisa spre aprobare", "progop.extra_approved": "Operatie EXTRA aprobata", "progop.extra_rejected": "Operatie EXTRA neaprobata", "admin.api_token_deleted": "Token API sters",
            "reports.statistics_generated": "Raport statistic generat",
        };
        return labels[action] || String(action || "Actiune").replaceAll(".", " / ").replaceAll("_", " ");
    }

    function renderApiTokens() {
        el.apiAccess.hidden = !state.data.canManageApiTokens;
        if(el.apiAccess.closest("details"))el.apiAccess.closest("details").hidden=!state.data.canManageApiTokens;
        if (!state.data.canManageApiTokens) return;
        el.apiBaseUrl.textContent = new URL("api/", window.location.href).href;
        if (!el.apiTokenExpiry.value) el.apiTokenExpiry.value = dateAfterDays(90);
        const apiUsers=state.data.people.filter(person=>person.user_id&&person.user_status==="active"&&(person.api_access_level||"none")!=="none");
        el.apiTokenOwner.innerHTML=apiUsers.length?apiUsers.map(person=>`<option value="${person.user_id}">${escapeHtml(person.display_name)} - ${escapeHtml(person.api_access_level)}</option>`).join(""):'<option value="">Niciun cont cu acces API</option>';
        el.apiPermissionOptions.innerHTML = state.data.permissions.map((permission) => `<label><input type="checkbox" value="${escapeHtml(permission.code)}"> <span><strong>${escapeHtml(permission.name || permission.code)}</strong><small>${escapeHtml(permission.code)}</small></span></label>`).join("");
        const tokens = state.data.apiTokens || [];
        el.apiTokenList.innerHTML = tokens.length ? tokens.map((token) => {
            const active = !token.revoked_at && new Date(token.expires_at.replace(" ", "T")) > new Date();
            return `<article class="api-token-row${active ? "" : " inactive"}"><div><strong>${escapeHtml(token.name)}</strong><code>${escapeHtml(token.token_prefix)}...</code></div><span>${escapeHtml(token.display_name || token.username)}<small>Expira: ${escapeHtml(formatDateTime(token.expires_at))}${token.last_used_at ? ` · Ultima folosire: ${escapeHtml(formatDateTime(token.last_used_at))}` : " · Nefolosit"}</small></span><div class="api-token-scopes">${token.permissions.map((permission) => `<i>${escapeHtml(permission)}</i>`).join("")}</div>${active ? `<button type="button" class="danger-button" data-revoke-token="${token.id}">Revoca</button>` : `<div class="token-inactive-actions"><b class="token-revoked">Inactiv</b><button type="button" class="danger-button" data-delete-token="${token.id}">Sterge</button></div>`}</article>`;
        }).join("") : '<div class="admin-users-empty">Nu exista tokenuri API.</div>';
        el.apiTokenList.querySelectorAll("[data-revoke-token]").forEach((button) => button.addEventListener("click", () => revokeApiToken(button.dataset.revokeToken)));
        el.apiTokenList.querySelectorAll("[data-delete-token]").forEach((button) => button.addEventListener("click", () => deleteApiToken(button.dataset.deleteToken)));
    }

    async function createApiToken(event) {
        event.preventDefault();
        const permissions = [...el.apiPermissionOptions.querySelectorAll('input:checked')].map((input) => input.value);
        const result = await mutate({ action: "create_api_token", user_id:Number(el.apiTokenOwner.value), name: el.apiTokenName.value, expires_on: el.apiTokenExpiry.value, permissions });
        el.apiTokenResult.hidden = false;
        el.apiTokenResult.innerHTML = `<strong>Token API - afisat o singura data</strong><code>${escapeHtml(result.api_token)}</code><span>Foloseste antetul Authorization: Bearer &lt;token&gt;. Pastreaza-l intr-un manager de parole.</span>`;
        el.apiTokenForm.reset();
        el.apiTokenExpiry.value = dateAfterDays(90);
        await load();
    }

    function renderBackupTokenForm() {
        const eligible=(state.data.people||[]).filter((person)=>person.user_id&&person.user_status==="active"&&person.api_access_level==="full"&&(state.data.effectivePermissions?.[person.user_id]||[]).includes("admin.reports"));
        el.backupTokenOwner.innerHTML=eligible.length?eligible.map((person)=>`<option value="${person.user_id}">${escapeHtml(person.display_name)} · ${escapeHtml(person.username)}</option>`).join(""):'<option value="">Configureaza mai intai un cont cu API Full si Rapoarte</option>';
        el.backupTokenExpiry.value=dateAfterDays(365);el.backupTokenForm.querySelector('button[type="submit"]').disabled=!eligible.length;
    }

    async function createBackupToken(event) {
        event.preventDefault();
        const result=await mutate({action:"create_api_token",user_id:Number(el.backupTokenOwner.value),name:"Backup server spital",expires_on:el.backupTokenExpiry.value,permissions:["admin.reports"]});
        const endpoint=`${window.location.origin}${window.location.pathname.replace(/\/public\/index\.php.*$/,"/public/")}api/backup-export.php`;
        const script=`$headers = @{ Authorization = "Bearer ${result.api_token}" }\n$date = Get-Date -Format "yyyy-MM-dd"\nInvoke-WebRequest "${endpoint}?period=daily&date=$date" -Headers $headers -OutFile "D:\\BackupIntern\\intern_daily_$date.json"`;
        el.backupTokenResult.hidden=false;el.backupTokenResult.innerHTML=`<strong>Token backup - afisat o singura data</strong><code>${escapeHtml(result.api_token)}</code><span>Script PowerShell pentru server:</span><pre>${escapeHtml(script)}</pre><span>Tokenul expira la data selectata. Genereaza unul nou inainte de expirare.</span>`;await load();
    }

    function applyApiAccessPreset() {
        const owner=state.data.people.find(person=>String(person.user_id)===String(el.apiTokenOwner.value));
        const effective=state.data.effectivePermissions[owner?.user_id]||[];
        const presets = {
            read: ["guards.view", "progop.view"],
            schedule: ["guards.view", "progop.view", "progop.create", "progop.edit_own"],
            full: effective,
        };
        if (el.apiAccessPreset.value === "custom") return;
        const level=owner?.api_access_level||"none";const requested=presets[el.apiAccessPreset.value]||[];const levelAllowed=level==="full"?effective:(presets[level]||[]);const selected=new Set(requested.filter(permission=>levelAllowed.includes(permission)&&effective.includes(permission)));
        el.apiPermissionOptions.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = selected.has(input.value); });
    }

    async function revokeApiToken(tokenId) {
        if (!window.confirm("Revoci acest token? Integrarea nu va mai putea accesa API-ul.")) return;
        await mutate({ action: "revoke_api_token", token_id: Number(tokenId) });
        await load();
    }

    async function deleteApiToken(tokenId) {
        if (!window.confirm("Stergi definitiv acest token inactiv? Actiunea nu poate fi anulata.")) return;
        await mutate({ action: "delete_api_token", token_id: Number(tokenId) });
        await load();
    }

    async function mutate(payload) {
        setStatus("Se salveaza..."); const response = await fetch("api/admin.php", { method: "POST", headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf }, body: JSON.stringify(payload) }); const data = await response.json(); if (!response.ok || !data.ok) { setStatus(data.message || "Salvarea a esuat.", true); throw new Error(data.message || "Salvarea a esuat."); } setStatus(data.message || "Salvat"); return data;
    }

    function showTemporaryPassword(password) { if (!password) return; el.temporaryResult.hidden = false; el.temporaryResult.innerHTML = `<strong>Parola temporara</strong><code>${escapeHtml(password)}</code><span>Utilizatorul va fi obligat sa o schimbe la prima autentificare.</span>`; }
    function findPerson(id) { return state.data.people.find((person) => String(person.id) === String(id)); }
    function openDrawer(drawer) { drawer.classList.add("open"); drawer.setAttribute("aria-hidden", "false"); }
    function closeDrawer(drawer) { drawer.classList.remove("open"); drawer.setAttribute("aria-hidden", "true"); }
    function setStatus(message, error = false) { el.status.textContent = message; el.status.classList.toggle("error", error); }
    function usernameSuggestion(person) { return `${person.first_name}.${person.last_name}`.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9.]/g, ""); }
    function initials(name) { return String(name).split(/\s+/).slice(0, 2).map((part) => part[0]).join("").toUpperCase(); }
    function groupBy(items, key) { return items.reduce((result, item) => { (result[item[key]] ||= []).push(item); return result; }, {}); }
    function createAdminGroup(node,title,subtitle,open=false){if(!node||node.closest("details.admin-collapsible-group"))return;const details=document.createElement("details");details.className="admin-collapsible-group";details.open=open;const summary=document.createElement("summary");summary.innerHTML=`<span><strong>${escapeHtml(title)}</strong><small>${escapeHtml(subtitle)}</small></span><b>Deschide</b>`;node.parentNode.insertBefore(details,node);details.append(summary,node);}
    function escapeHtml(value) { return String(value ?? "").replace(/[&<>'"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" })[char]); }
    function today() { const date = new Date(); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`; }
    function formatDate(value) { if (!value) return "-"; const [year, month, day] = String(value).split("-"); return `${day}.${month}.${year}`; }
    function formatDateTime(value) { if (!value) return "-"; return new Intl.DateTimeFormat("ro-RO", { dateStyle: "medium", timeStyle: "short" }).format(new Date(String(value).replace(" ", "T"))); }
    function dateAfterDays(days) { const date = new Date(); date.setDate(date.getDate() + days); return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`; }
    load();
})();
