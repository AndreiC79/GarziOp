document.addEventListener("DOMContentLoaded", () => {
    const shell = document.getElementById("appShell");
    const sidebarToggle = document.getElementById("sidebarToggle");
    const storageKey = "intern.sidebar.collapsed";

    if (shell && sidebarToggle) {
        const applySidebarState = (collapsed) => {
            shell.classList.toggle("sidebar-collapsed", collapsed);
            sidebarToggle.setAttribute("aria-expanded", String(!collapsed));
            sidebarToggle.setAttribute("aria-label", collapsed ? "Extinde meniul" : "Restrange meniul");
        };

        applySidebarState(window.localStorage.getItem(storageKey) === "1");
        sidebarToggle.addEventListener("click", () => {
            const collapsed = !shell.classList.contains("sidebar-collapsed");
            applySidebarState(collapsed);
            window.localStorage.setItem(storageKey, collapsed ? "1" : "0");
        });
    }

    const notificationToggle = document.getElementById("notificationToggle");
    const notificationDrawer = document.getElementById("notificationDrawer");
    const notificationBadge = document.getElementById("notificationBadge");
    const markNotificationsRead = document.getElementById("markNotificationsRead");
    const deleteReadNotifications = document.getElementById("deleteReadNotifications");
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";

    const setNotificationDrawer = (open) => {
        if (!notificationToggle || !notificationDrawer) return;
        notificationToggle.setAttribute("aria-expanded", String(open));
        notificationDrawer.setAttribute("aria-hidden", String(!open));
        notificationDrawer.classList.toggle("open", open);
    };

    const openNotifications = async () => {
        setNotificationDrawer(true);
        if (Number(notificationBadge?.textContent || 0) > 0) await markAllRead();
    };

    const markAllRead = async () => {
        if (!notificationDrawer) return;
        const response = await fetch("api/notifications.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
            body: JSON.stringify({ action: "mark_all_read" })
        });
        if (!response.ok) return;
        notificationDrawer.querySelectorAll(".notification-item.unread").forEach((item) => item.classList.remove("unread"));
        notificationDrawer.querySelectorAll('.notification-item[data-actionable="0"] [data-delete-notification]').forEach((button) => { button.hidden = false; });
        if (deleteReadNotifications && notificationDrawer.querySelector('.notification-item[data-actionable="0"]')) deleteReadNotifications.disabled = false;
        if (notificationBadge) notificationBadge.textContent = "0";
        if (markNotificationsRead) markNotificationsRead.disabled = true;
    };

    notificationToggle?.addEventListener("click", async () => {
        const open = notificationToggle.getAttribute("aria-expanded") !== "true";
        setNotificationDrawer(open);
        if (open && Number(notificationBadge?.textContent || 0) > 0) await markAllRead();
    });
    document.querySelectorAll("[data-open-notifications]").forEach((button) => button.addEventListener("click", openNotifications));
    markNotificationsRead?.addEventListener("click", markAllRead);
    deleteReadNotifications?.addEventListener("click", async () => {
        const deletable = [...notificationDrawer.querySelectorAll('.notification-item:not(.unread)[data-actionable="0"]')];
        if (!deletable.length || !window.confirm(`Ștergi toate cele ${deletable.length} notificări citite?`)) return;
        deleteReadNotifications.disabled = true;
        try {
            const response = await fetch("api/notifications.php", { method:"POST", headers:{"Content-Type":"application/json","X-CSRF-Token":csrf}, body:JSON.stringify({action:"delete_all_read"}) });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || "Notificările nu au putut fi șterse.");
            deletable.forEach((item) => item.remove());
            if (!notificationDrawer.querySelector(".notification-item")) document.getElementById("notificationList").innerHTML = '<div class="notification-empty">Nu ai notificări.</div>';
        } catch (error) { deleteReadNotifications.disabled = false; window.alert(error.message); }
    });
    document.querySelectorAll("[data-close-notifications]").forEach((button) => button.addEventListener("click", () => setNotificationDrawer(false)));
    notificationDrawer?.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-delete-notification]");
        if (!button) return;
        const item = button.closest("[data-notification-id]");
        if (!item || !window.confirm("Ștergi această notificare citită?")) return;
        button.disabled = true;
        try {
            const response = await fetch("api/notifications.php", { method:"POST", headers:{"Content-Type":"application/json","X-CSRF-Token":csrf}, body:JSON.stringify({action:"delete_read",notification_id:Number(item.dataset.notificationId)}) });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || "Notificarea nu a putut fi ștearsă.");
            item.remove();
            if (!notificationDrawer.querySelector(".notification-item")) document.getElementById("notificationList").innerHTML = '<div class="notification-empty">Nu ai notificări.</div>';
            if (deleteReadNotifications && !notificationDrawer.querySelector('.notification-item:not(.unread)[data-actionable="0"]')) deleteReadNotifications.disabled = true;
        } catch (error) { button.disabled = false; window.alert(error.message); }
    });
    document.querySelectorAll("[data-extra-review]").forEach((actions) => {
        actions.querySelectorAll("[data-extra-approved]").forEach((button) => button.addEventListener("click", async () => {
            const approved = button.dataset.extraApproved === "1";
            let rejectionReason = "";
            if (approved) {
                if (!window.confirm("Aprobi această operație EXTRA?")) return;
            } else {
                rejectionReason = window.prompt("Motivul refuzului (obligatoriu):", "")?.trim() || "";
                if (!rejectionReason) { window.alert("Completează motivul refuzului."); return; }
            }
            actions.querySelectorAll("button").forEach((item) => { item.disabled = true; });
            try {
                const response = await fetch("api/progop-legacy.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                    body: JSON.stringify({ action: "reviewExtraOperation", operationId: Number(actions.dataset.extraReview), approved, rejectionReason })
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || "Decizia nu a putut fi salvata.");
                actions.innerHTML = `<strong>${approved ? "Aprobata" : "Neaprobata"}</strong>`;
                window.setTimeout(() => window.location.reload(), 500);
            } catch (error) {
                actions.querySelectorAll("button").forEach((item) => { item.disabled = false; });
                window.alert(error.message);
            }
        }));
    });

    const passwordDrawer = document.getElementById("accountPasswordDrawer");
    const setPasswordDrawer = (open) => {
        if (!passwordDrawer) return;
        passwordDrawer.setAttribute("aria-hidden", String(!open));
        passwordDrawer.classList.toggle("open", open);
        if (open) passwordDrawer.querySelector('input[name="current_password"]')?.focus();
    };
    document.querySelectorAll("[data-open-password]").forEach((button) => button.addEventListener("click", () => setPasswordDrawer(true)));
    document.querySelectorAll("[data-close-password]").forEach((button) => button.addEventListener("click", () => setPasswordDrawer(false)));
    passwordDrawer?.addEventListener("keydown", (event) => { if (event.key === "Escape") setPasswordDrawer(false); });

    const futureGuardsToggle = document.getElementById("futureGuardsToggle");
    const futureGuardsList = document.getElementById("futureGuardsList");
    futureGuardsToggle?.addEventListener("click", () => {
        const open = futureGuardsToggle.getAttribute("aria-expanded") !== "true";
        futureGuardsToggle.setAttribute("aria-expanded", String(open));
        if (futureGuardsList) futureGuardsList.hidden = !open;
        if (open) futureGuardsList?.scrollIntoView({ behavior: "smooth", block: "nearest" });
    });
});
