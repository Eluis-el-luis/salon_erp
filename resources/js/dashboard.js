/**
 * ============================================================================
 * DASHBOARD GERENCIAL (Centro de Mando) — Componente Alpine.js
 * ============================================================================
 * SPA asíncrona: los filtros Hoy/Semana/Mes/Año hacen fetch() al endpoint BI
 * (/panel/api/datos) y actualizan KPIs, gráficos, meta y ranking SIN recargar
 * la página. Los gráficos Chart.js se crean una sola vez y luego solo mutan
 * sus datos (chart.update()) para evitar parpadeos.
 *
 * Fuente de datos: Libro Mayor (detalle_asientos) vía DashboardService.
 */
import Chart from 'chart.js/auto';

const fmt = new Intl.NumberFormat('es-NI', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const ESTADOS_VENTA = {
    pagada: { texto: 'Pagada', clases: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    emitida: { texto: 'Por cobrar', clases: 'bg-amber-50 text-amber-700 border-amber-200' },
    borrador: { texto: 'Cotización', clases: 'bg-gray-100 text-gray-600 border-gray-200' },
    anulada: { texto: 'Anulada', clases: 'bg-red-50 text-red-700 border-red-200' },
};

// Instancias de Chart.js FUERA del estado reactivo de Alpine: si se guardan
// en `this`, el Proxy reactivo de Alpine intercepta los objetos internos de
// Chart.js y chart.update() explota con "Maximum call stack size exceeded"
// o "Cannot set properties of undefined (setting 'fullSize')".
let chartVentas = null;
let chartPagos = null;

export default function dashboard(estadoInicial, flags = {}) {
    return {
        // ---------- Estado reactivo ----------
        rango: estadoInicial?.rango ?? 'hoy',
        datos: estadoInicial,
        cargando: false,
        error: null,
        flags: {
            puedeFinanzas: flags.puedeFinanzas ?? false,
            puedeCaja: flags.puedeCaja ?? false,
            puedeEditarMeta: flags.puedeEditarMeta ?? false,
        },

        // ---------- Quick View (modal) ----------
        modal: { abierto: false, cargando: false, error: null, movimiento: null },

        // ---------- Edición de meta (admin) ----------
        editandoMeta: false,
        metaBorrador: 0,
        guardandoMeta: false,
        metaExito: false,

        init() {
            this.$nextTick(() => this.renderizarGraficos());
        },

        // =================================================================
        // FORMATO
        // =================================================================
        money(v) {
            return fmt.format(Number(v ?? 0));
        },

        porcentaje(item) {
            const max = this.datos?.ranking?.[0]?.monto ?? 0;
            if (max <= 0) return 0;
            return Math.max(4, Math.round(((item?.monto ?? 0) / max) * 100));
        },

        estadoVenta(estado) {
            return ESTADOS_VENTA[estado] ?? ESTADOS_VENTA.borrador;
        },

        get hayCobros() {
            const m = this.datos?.metodo ?? {};
            return (m.efectivo ?? 0) + (m.bac ?? 0) + (m.lafise ?? 0) > 0;
        },

        // =================================================================
        // FILTROS ASÍNCRONOS (fetch + deep-link)
        // =================================================================
        async setRango(rango) {
            if (this.cargando || rango === this.rango) return;

            this.rango = rango;
            this.cargando = true;
            this.error = null;

            // Deep-link sin recarga: ?rango= comparte el estado del panel
            const url = new URL(window.location.href);
            url.searchParams.set('rango', rango);
            window.history.replaceState({}, '', url);

            try {
                const resp = await fetch(`/panel/api/datos?rango=${encodeURIComponent(rango)}`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!resp.ok) {
                    // 401 = sesión expirada: el mensaje debe guiar, no culpar
                    // a la conexión. Otros estados se muestran para diagnosticar.
                    if (resp.status === 401) {
                        throw new Error('SESION_EXPIRADA');
                    }
                    throw new Error(`HTTP ${resp.status}`);
                }
                this.datos = await resp.json();
                this.renderizarGraficos();
            } catch (e) {
                console.error('[Dashboard BI] Falló /panel/api/datos:', e);
                this.error = e?.message === 'SESION_EXPIRADA'
                    ? 'Tu sesión expiró. Recarga la página para iniciar sesión de nuevo.'
                    : `No se pudo actualizar el panel (${e?.message ?? 'sin respuesta del servidor'}).`;
            } finally {
                this.cargando = false;
            }
        },

        // =================================================================
        // GRÁFICOS (mutación de datos, sin recrear instancias)
        // =================================================================
        renderizarGraficos() {
            if (typeof Chart === 'undefined') return;

            const serie = this.datos?.serie ?? { labels: [], ingresos: [], costos: [] };

            // Si el canvas fue reemplazado en el DOM, la instancia queda huérfana:
            // se destruye y se recrea (evita "Canvas is already in use").
            if (chartVentas && !chartVentas.canvas?.isConnected) {
                chartVentas.destroy();
                chartVentas = null;
            }
            if (chartPagos && !chartPagos.canvas?.isConnected) {
                chartPagos.destroy();
                chartPagos = null;
            }

            // Línea: ingresos vs costos (del mayor)
            if (chartVentas) {
                chartVentas.data.labels = serie.labels;
                chartVentas.data.datasets[0].data = serie.ingresos;
                chartVentas.data.datasets[1].data = serie.costos;
                chartVentas.update();
            } else {
                const ctx = document.getElementById('chartVentas');
                if (!ctx) return;
                chartVentas = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: serie.labels,
                        datasets: [
                            {
                                label: 'Ingresos (C$)',
                                data: serie.ingresos,
                                borderColor: '#047857',
                                backgroundColor: 'rgba(4,120,87,0.12)',
                                fill: true,
                                tension: 0.35,
                                pointRadius: 3,
                                borderWidth: 2,
                            },
                            {
                                label: 'Costo ventas (C$)',
                                data: serie.costos,
                                borderColor: '#dc7626',
                                backgroundColor: 'transparent',
                                borderDash: [5, 4],
                                tension: 0.35,
                                pointRadius: 2,
                                borderWidth: 1.5,
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                            tooltip: {
                                callbacks: {
                                    label: (c) => ` ${c.dataset.label}: C$ ${fmt.format(c.raw)}`,
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: (v) => 'C$ ' + v.toLocaleString('es-NI') },
                            },
                        },
                    },
                });
            }

            // Donut: cobros por método (del ledger)
            const metodo = this.datos?.metodo ?? {};
            const donutData = [metodo.efectivo ?? 0, metodo.bac ?? 0, metodo.lafise ?? 0];

            if (chartPagos) {
                chartPagos.data.datasets[0].data = donutData;
                chartPagos.update();
            } else {
                const ctx = document.getElementById('chartPagos');
                if (!ctx) return;
                chartPagos = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Efectivo', 'BAC', 'LAFISE'],
                        datasets: [{
                            data: donutData,
                            backgroundColor: ['#10b981', '#3b82f6', '#0d9488'],
                            borderWidth: 2,
                        }],
                    },
                    options: {
                        responsive: true,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: {
                                callbacks: {
                                    label: (c) => ` ${c.label}: C$ ${fmt.format(c.raw)}`,
                                },
                            },
                        },
                    },
                });
            }
        },

        // =================================================================
        // DRILL-DOWN: QUICK VIEW DE UN MOVIMIENTO
        // =================================================================
        async abrirMovimiento(id) {
            this.modal = { abierto: true, cargando: true, error: null, movimiento: null };
            document.body.style.overflow = 'hidden';

            try {
                const resp = await fetch(`/panel/api/movimientos/${id}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                this.modal.movimiento = await resp.json();
            } catch (e) {
                console.error('[Dashboard BI] Falló el Quick View:', e);
                this.modal.error = e?.message === 'HTTP 401'
                    ? 'Tu sesión expiró. Recarga la página para iniciar sesión de nuevo.'
                    : 'No se pudo cargar el detalle del movimiento.';
            } finally {
                this.modal.cargando = false;
            }
        },

        cerrarModal() {
            this.modal.abierto = false;
            this.modal.movimiento = null;
            document.body.style.overflow = '';
        },

        // =================================================================
        // META MENSUAL DINÁMICA (admin)
        // =================================================================
        iniciarEdicionMeta() {
            this.metaBorrador = this.datos?.meta?.monto ?? 0;
            this.metaExito = false;
            this.editandoMeta = true;
            this.$nextTick(() => this.$refs.metaInput?.focus());
        },

        cancelarEdicionMeta() {
            this.editandoMeta = false;
        },

        async guardarMeta() {
            if (this.guardandoMeta) return;
            this.guardandoMeta = true;

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            try {
                const resp = await fetch('/panel/api/meta', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ monto: this.metaBorrador }),
                });
                if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                const payload = await resp.json();
                if (payload.meta) {
                    this.datos.meta = payload.meta;
                    this.editandoMeta = false;
                    this.metaExito = true;
                    setTimeout(() => (this.metaExito = false), 2500);
                }
            } catch (e) {
                alert('No se pudo guardar la meta mensual.');
            } finally {
                this.guardandoMeta = false;
            }
        },
    };
}
