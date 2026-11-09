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
    },

    // ================================================================
    // HELPERS PARA UI DINÁMICA (clases CSS e iconos por tipo)
    // ================================================================

    getNotificationClasses(type) {
        const base = 'flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center ';
        const classes = {
            // Ventas/Compras (verde)
            'venta_pendiente_aprobacion': 'bg-emerald-100 text-emerald-600',
            'venta_aprobada': 'bg-emerald-100 text-emerald-600',
            'compra_pendiente_aprobacion': 'bg-emerald-100 text-emerald-600',
            'compra_pendiente_aprobacion_admin': 'bg-emerald-100 text-emerald-600',
            
            // Rechazos (rojo)
            'venta_rechazada': 'bg-red-100 text-red-600',
            'venta_rechazada_alvaro': 'bg-red-100 text-red-600',
            'compra_rechazada_alvaro': 'bg-red-100 text-red-600',
            
            // Caja (ámbar)
            'caja_sin_cerrar': 'bg-amber-100 text-amber-600',
            'caja_diferencia': 'bg-red-100 text-red-600',
            
            // Stock (azul/rojo)
            'stock_critico': 'bg-blue-100 text-blue-600',
            'stock_agotado': 'bg-red-100 text-red-600',
            'merma_registrada': 'bg-red-100 text-red-600',
            'proveedor_nuevo': 'bg-blue-100 text-blue-600',
            
            // Finanzas CxP/CxC (rojo/azul)
            'cxp_vencida': 'bg-red-100 text-red-600',
            'cxp_por_vencer': 'bg-amber-100 text-amber-600',
            'cxc_vencida': 'bg-red-100 text-red-600',
            'cxc_por_vencer': 'bg-amber-100 text-amber-600',
            'conciliacion_pendiente': 'bg-blue-100 text-blue-600',
            'cierre_contable': 'bg-purple-100 text-purple-600',
            'asiento_desbalanceado': 'bg-red-100 text-red-600',
            'banco_movimiento': 'bg-blue-100 text-blue-600',
            'iva_declaracion': 'bg-purple-100 text-purple-600',
            
            // Caja
            'caja_diferencia': 'bg-red-100 text-red-600',
            
            // RRHH (púrpura)
            'empleado_nuevo': 'bg-purple-100 text-purple-600',
            'ausencia_pendiente': 'bg-amber-100 text-amber-600',
            'nomina_pendiente': 'bg-purple-100 text-purple-600',
            'comision_ganada': 'bg-emerald-100 text-emerald-600',
            'comision_pagada': 'bg-emerald-100 text-emerald-600',
            'propina_recibida': 'bg-amber-100 text-amber-600',
            
            // Agenda (azul)
            'cita_nueva': 'bg-blue-100 text-blue-600',
            'cita_asignada': 'bg-blue-100 text-blue-600',
            'cita_cancelada': 'bg-red-100 text-red-600',
            'cita_reagendada': 'bg-blue-100 text-blue-600',
            'cita_modificada': 'bg-blue-100 text-blue-600',
            
            // Clientes
            'cliente_nuevo': 'bg-emerald-100 text-emerald-600',
            'cliente_cxc_vencida': 'bg-red-100 text-red-600',
            'cotizacion_solicitada': 'bg-gray-100 text-gray-600',
            
            // Comisiones/Propinas (verde/ámbar)
            'comision_ganada': 'bg-emerald-100 text-emerald-600',
            'comision_pagada': 'bg-emerald-100 text-emerald-600',
            'propina_recibida': 'bg-amber-100 text-amber-600',
            
            // RRHH
            'empleado_nuevo': 'bg-purple-100 text-purple-600',
            'ausencia_pendiente': 'bg-amber-100 text-amber-600',
            'nomina_pendiente': 'bg-purple-100 text-purple-600',
            
            // Sistema
            'alerta_critica': 'bg-red-100 text-red-600',
            'reporte_diario': 'bg-blue-100 text-blue-600',
            'respaldo_faltante': 'bg-gray-100 text-gray-600',
            'respaldo_completado': 'bg-emerald-100 text-emerald-600',
            'alerta_critica': 'bg-red-100 text-red-600',
            
            // Default
            'default': 'bg-gray-100 text-gray-600'
        };
        return base + (classes[type] || classes.default);
    },

    // Helpers para iconos SVG (usados en template x-if)
    isVentaType(type) {
        return ['venta_pendiente_aprobacion', 'venta_aprobada', 'compra_pendiente_aprobacion', 'compra_pendiente_aprobacion_admin'].includes(type);
    },
    
    isRechazoType(type) {
        return ['venta_rechazada', 'venta_rechazada_alvaro', 'compra_rechazada_alvaro'].includes(type);
    },
    
    isStockType(type) {
        return ['stock_critico', 'stock_agotado', 'merma_registrada'].includes(type);
    },
    
    isCxType(type) {
        return ['cxp_vencida', 'cxp_por_vencer', 'cxc_vencida', 'cxc_por_vencer', 'conciliacion_pendiente', 'cierre_contable', 'asiento_desbalanceado', 'banco_movimiento', 'iva_declaracion'].includes(type);
    },
    
    isCitaType(type) {
        return ['cita_nueva', 'cita_asignada', 'cita_cancelada', 'cita_reagendada', 'cita_modificada'].includes(type);
    },
    
    isComisionType(type) {
        return ['comision_ganada', 'comision_pagada', 'propina_recibida'].includes(type);
    },
    
    isRRHHType(type) {
        return ['empleado_nuevo', 'ausencia_pendiente', 'nomina_pendiente'].includes(type);
    },
    
    isBancoType(type) {
        return ['banco_movimiento', 'conciliacion_pendiente'].includes(type);
    },
    
    isAlertaType(type) {
        return ['alerta_critica', 'stock_agotado', 'asiento_desbalanceado', 'caja_diferencia'].includes(type);
    },
    
    isDefaultType(type) {
        const known = [
            'venta_pendiente_aprobacion', 'venta_aprobada', 'compra_pendiente_aprobacion', 'compra_pendiente_aprobacion_admin',
            'venta_rechazada', 'venta_rechazada_alvaro', 'compra_rechazada_alvaro',
            'caja_sin_cerrar', 'caja_diferencia',
            'stock_critico', 'stock_agotado', 'merma_registrada', 'proveedor_nuevo',
            'cxp_vencida', 'cxp_por_vencer', 'cxc_vencida', 'cxc_por_vencer', 'conciliacion_pendiente', 'cierre_contable', 'asiento_desbalanceado', 'banco_movimiento', 'iva_declaracion',
            'cita_nueva', 'cita_asignada', 'cita_cancelada', 'cita_reagendada', 'cita_modificada',
            'cliente_nuevo', 'cliente_cxc_vencida', 'cotizacion_solicitada',
            'comision_ganada', 'comision_pagada', 'propina_recibida',
            'empleado_nuevo', 'ausencia_pendiente', 'nomina_pendiente',
            'alerta_critica', 'reporte_diario', 'respaldo_faltante', 'respaldo_completado'
        ];
        return !known.includes(type);
    }
});