/**
 * NOTIFICATION BELL - Campanita de notificaciones en navbar.
 *
 * Funcionalidad:
 *  - Muestra contador de no leídas (badge rojo)
 *  - Dropdown con lista de notificaciones
 *  - Click en notificación: marca como leída y navega
 *  - "Marcar todas" lee todas
 *  - Auto-refresh al recibir evento 'notify' (toast)
 */
export default () => ({
    open: false,
    unreadCount: 0,
    notifications: [],
    loading: false,

    async init() {
        await this.refreshCount();
        await this.loadNotifications();
    },

    async refreshCount() {
        try {
            const response = await fetch('/notificaciones/api/count', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (response.ok) {
                const data = await response.json();
                this.unreadCount = data.count ?? 0;
            } else {
                console.warn('[NotificationBell] count API error:', response.status);
            }
        } catch (e) {
            console.error('[NotificationBell] Error refreshCount:', e);
        }
    },

    async loadNotifications() {
        this.loading = true;
        try {
            const response = await fetch('/notificaciones/api/list', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (response.ok) {
                const data = await response.json();
                this.notifications = data.notifications ?? [];
            } else {
                console.warn('[NotificationBell] list API error:', response.status);
            }
        } catch (e) {
            console.error('[NotificationBell] Error loadNotifications:', e);
        } finally {
            this.loading = false;
        }
    },

    toggle() {
        this.open = !this.open;
        if (this.open && this.notifications.length === 0) {
            this.loadNotifications();
        }
    },

    async markRead(id) {
        try {
            const response = await fetch(`/notificaciones/api/${id}/read`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (response.ok) {
                const notif = this.notifications.find(n => n.id === id);
                if (notif) {
                    notif.read = true;
                    this.unreadCount = Math.max(0, this.unreadCount - 1);
                }
            }
        } catch (e) {
            console.error('[NotificationBell] Error markRead:', e);
        }
    },

    handleNotificationClick(notif) {
        // Marcar como leída si no lo está
        if (!notif.read) {
            this.markRead(notif.id);
        }
        // Navegar a la URL si existe (no prevenir default)
        // El enlace <a> maneja la navegación naturalmente
        if (notif.data?.url) {
            // Pequeño delay para que se procese el markRead
            setTimeout(() => {
                window.location.href = notif.data.url;
            }, 100);
        }
    },

    async markAllRead() {
        try {
            const response = await fetch('/notificaciones/api/read-all', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (response.ok) {
                this.notifications.forEach(n => n.read = true);
                this.unreadCount = 0;
            }
        } catch (e) {
            console.error('[NotificationBell] Error markAllRead:', e);
        }
    },

    formatTime(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Ahora mismo';
        if (diffMins < 60) return `Hace ${diffMins} min`;
        if (diffHours < 24) return `Hace ${diffHours} h`;
        if (diffDays < 7) return `Hace ${diffDays} d`;
        return date.toLocaleDateString('es-NI', { day: '2-digit', month: '2-digit', year: '2-digit' });
    }
});