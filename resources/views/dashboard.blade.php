@extends('layouts.app')

@section('title', 'Dashboard')

@php
// Drill-down condicional: el enlace del KPI solo se renderiza si el rol
// tiene acceso real a la vista destino (mismo criterio del menú; el admin
// tiene bypass global). Evita 403 desde el propio dashboard.
$tagIngresos = $puedeFinanzas ? 'a' : 'div';
$tagInventario = $puedeInventario ? 'a' : 'div';

// Badges de estado de las facturas (primer render de Últimos Movimientos)
$badgeEstados = [
    'pagada' => ['Pagada', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'emitida' => ['Por cobrar', 'bg-amber-50 text-amber-700 border-amber-200'],
    'borrador' => ['Cotización', 'bg-gray-100 text-gray-600 border-gray-200'],
    'anulada' => ['Anulada', 'bg-red-50 text-red-700 border-red-200'],
];

// Etiqueta de la serie temporal según el filtro activo
$etiquetasSerie = [
    'hoy' => 'Ingresos vs Costos — Últimos 7 días',
    'semana' => 'Ingresos vs Costos — Semana en curso',
    'mes' => 'Ingresos vs Costos — Mes en curso',
    'anio' => 'Ingresos vs Costos — Año en curso',
];
$etiquetaSerie = $etiquetasSerie[$rango] ?? $etiquetasSerie['hoy'];

$fuentesMeta = [
    'exacta' => 'Meta definida para este mes.',
    'heredada' => 'Meta heredada del último mes configurado.',
    'config' => 'Meta por defecto del sistema (configúrala aquí).',
];
@endphp

@section('content')
<div x-data="dashboard(@js($estado), @js(['puedeFinanzas' => $puedeFinanzas, 'puedeCaja' => $puedeCaja, 'puedeEditarMeta' => $puedeEditarMeta]))"
     class="space-y-6" @keydown.escape.window="cerrarModal()">

    <!-- Encabezado + Filtro de Rango (SPA) -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="page-title">Centro de Mando</h1>
            <p class="page-subtitle">
                Inteligencia de negocio sobre el Libro Mayor — misma fuente que el Estado de Resultados.
            </p>
        </div>

        <div class="flex items-center gap-1 bg-white p-1 rounded-xl border border-gray-200 shadow-sm" role="tablist" aria-label="Filtro de periodo">
            @foreach(['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'anio' => 'Año'] as $key => $label)
                <button type="button" @click="setRango('{{ $key }}')" :disabled="cargando"
                    class="px-4 py-2 rounded-lg text-sm font-bold transition cursor-pointer disabled:opacity-60"
                    :class="rango === '{{ $key }}' ? 'bg-emerald-700 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900'">
                    {{ $label }}
                </button>
            @endforeach
            <span x-cloak x-show="cargando" class="px-2 text-emerald-600">
                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            </span>
        </div>
    </div>

    <!-- Error de actualización asíncrona -->
    <div x-cloak x-show="error" x-transition class="bg-red-50 border border-red-200 text-red-700 text-sm font-bold rounded-xl px-4 py-3 flex items-center justify-between">
        <span x-text="error"></span>
        <button type="button" @click="error = null" class="text-red-500 hover:text-red-700 font-black cursor-pointer">&times;</button>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6" :class="cargando ? 'opacity-50 transition-opacity' : 'transition-opacity'">

        <!-- Ingresos del Periodo (clase 4 del mayor) -->
        <{{ $tagIngresos }} @if($puedeFinanzas) href="{{ url('/contabilidad/resultados?mes=' . now()->format('Y-m')) }}" title="Ver Estado de Resultados del mes" @endif
           class="card p-6 flex items-center space-x-4 @if($puedeFinanzas) hover:shadow-md hover:border-emerald-200 transition cursor-pointer @endif">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-700 shrink-0">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Ingresos del Periodo</p>
                <p class="text-2xl font-black text-gray-900">C$ <span x-text="money(datos.kpis.ingresos)"></span></p>
                <p class="text-[10px] text-gray-400 font-medium">HABER clase 4 del Libro Mayor</p>
            </div>
        </{{ $tagIngresos }}>

        <!-- Utilidad Bruta (ingresos - costos clase 5) -->
        <{{ $tagIngresos }} @if($puedeFinanzas) href="{{ url('/contabilidad/resultados?mes=' . now()->format('Y-m')) }}" title="Ver Estado de Resultados del mes" @endif
           class="card p-6 flex items-center space-x-4 border-l-4 border-l-emerald-600 @if($puedeFinanzas) hover:shadow-md hover:border-emerald-200 transition cursor-pointer @endif">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-700 shrink-0">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Utilidad Bruta</p>
                <p class="text-2xl font-black text-emerald-600">C$ <span x-text="money(datos.kpis.utilidad_bruta)"></span></p>
                <p class="text-[10px] text-gray-400 font-medium">Margen: <span x-text="datos.kpis.margen_bruto"></span>% — clase 4 vs clase 5</p>
            </div>
        </{{ $tagIngresos }}>

        <!-- Agenda (operativo, hoy) -->
        <a href="{{ url('/agenda') }}" class="card p-6 flex items-center space-x-4 hover:shadow-md transition cursor-pointer" title="Ir a la agenda del día">
            <div class="p-3 rounded-full bg-purple-100 text-purple-700 shrink-0">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Agenda (Hoy)</p>
                <p class="text-2xl font-black text-gray-900">{{ $citasPendientes }} <span class="text-sm font-medium text-gray-400">pendientes</span></p>
                <p class="text-xs text-gray-500 mt-1 font-medium">De {{ $citasTotal }} citas</p>
            </div>
        </a>

        <!-- Inventario crítico (drill-down al kárdex filtrado) -->
        <{{ $tagInventario }} @if($puedeInventario) href="{{ url('/inventario?stock=critico') }}" title="Ver artículos con stock crítico" @endif
           class="card p-6 flex items-center space-x-4 @if($puedeInventario) hover:shadow-md hover:border-red-200 transition cursor-pointer @endif">
            <div class="p-3 rounded-full {{ $stockCritico > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600' }} shrink-0">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    @if($stockCritico > 0)
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    @endif
                </svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Inventario</p>
                <p class="text-2xl font-black {{ $stockCritico > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ $stockCritico }} <span class="text-sm font-medium text-gray-400">críticos</span>
                </p>
                @if($puedeInventario)
                    <p class="text-[10px] text-gray-400 font-medium">Ver kárdex filtrado &rarr;</p>
                @endif
            </div>
        </{{ $tagInventario }}>

    </div>

    <!-- Gráficos -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6" :class="cargando ? 'opacity-50 transition-opacity' : 'transition-opacity'">

        <!-- Línea: serie del mayor según el filtro -->
        <div class="card p-6 lg:col-span-2">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-800">{{ $etiquetaSerie }}</h3>
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Ledger</span>
            </div>
            <canvas id="chartVentas" height="100"></canvas>
        </div>

        <!-- Donut: cobros por método (cuentas de fondos del asiento de venta) -->
        <div class="card p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Cobros por Método</h3>
            <canvas id="chartPagos" height="180"></canvas>
            <p x-cloak x-show="!hayCobros" class="text-xs text-gray-400 text-center mt-3 font-medium">
                Sin cobros de mostrador en el periodo (créditos pendientes no cuentan hasta cobrarse).
            </p>
        </div>

    </div>

    <!-- Meta + Ranking -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Widget Meta Mensual DINÁMICA (company_targets) -->
        <div class="card p-6" :class="cargando ? 'opacity-50' : ''">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-bold text-gray-800">Meta Mensual</h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded"><span x-text="datos.meta.progreso"></span>%</span>
                    @if($puedeEditarMeta)
                        <button type="button" @click="iniciarEdicionMeta()" title="Editar meta del mes"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-emerald-700 hover:bg-emerald-50 transition cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        </button>
                    @endif
                </div>
            </div>

            <!-- Barra de progreso -->
            <div class="h-3 bg-gray-100 rounded-full overflow-hidden mb-3">
                <div class="h-full bg-emerald-600 rounded-full transition-all" :style="'width:' + (datos.meta.progreso ?? 0) + '%'"></div>
            </div>
            <div class="flex justify-between text-sm">
                <span class="font-bold text-gray-900">C$ <span x-text="money(datos.meta.avance)"></span></span>
                <span class="text-gray-500">de C$ <span x-text="money(datos.meta.monto)"></span></span>
            </div>

            <!-- Edición inline (solo admin) -->
            @if($puedeEditarMeta)
                <div x-cloak x-show="editandoMeta" x-transition class="mt-4 pt-4 border-t border-gray-100">
                    <label class="text-xs font-bold text-gray-500 uppercase">Meta de facturación (C$)</label>
                    <div class="flex items-center gap-2 mt-2">
                        <input type="number" min="0" step="0.01" x-ref="metaInput" x-model.number="metaBorrador"
                            class="flex-1 border-gray-300 rounded-lg text-sm focus:ring-emerald-600 focus:border-emerald-600">
                        <button type="button" @click="guardarMeta()" :disabled="guardandoMeta"
                            class="btn bg-emerald-700 text-white px-3 py-2 rounded-lg text-xs font-bold hover:bg-emerald-800 disabled:opacity-50 cursor-pointer">
                            <span x-show="!guardandoMeta">Guardar</span>
                            <span x-show="guardandoMeta" x-cloak>Guardando…</span>
                        </button>
                        <button type="button" @click="cancelarEdicionMeta()" class="text-gray-400 hover:text-gray-600 text-xs font-bold cursor-pointer">Cancelar</button>
                    </div>
                </div>
                <p x-cloak x-show="metaExito" x-transition class="text-[11px] font-bold text-emerald-600 mt-3">&check; Meta guardada en company_targets</p>
            @endif

            <p class="text-xs text-gray-400 mt-3 font-medium">{{ $fuentesMeta[$estado['meta']['fuente']] ?? 'Avance de facturación del mes en curso.' }}</p>
        </div>

        <!-- Ranking de Servicios (una consulta agrupada, sin N+1) -->
        <div class="card p-6 lg:col-span-2" :class="cargando ? 'opacity-50' : ''">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-800">Ranking de Servicios Más Vendidos</h3>
                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Por ingreso facturado</span>
            </div>
            <div class="space-y-3">
                <template x-for="(sv, i) in datos.ranking" :key="sv.id">
                    <div class="flex items-center gap-3">
                        <span class="w-7 h-7 flex items-center justify-center rounded-full text-xs font-black shrink-0"
                            :class="i === 0 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'"
                            x-text="i + 1"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 truncate" x-text="sv.servicio"></p>
                            <p class="text-xs text-gray-500">
                                <span x-text="sv.unidades"></span> unidades · C$ <span x-text="money(sv.monto)"></span>
                            </p>
                        </div>
                        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden max-w-[160px]">
                            <div class="h-full bg-emerald-600 rounded-full" :style="'width:' + porcentaje(sv) + '%'"></div>
                        </div>
                    </div>
                </template>
                <p x-cloak x-show="!cargando && (datos.ranking?.length ?? 0) === 0" class="text-sm text-gray-500 text-center py-4">
                    Sin ventas de servicios en el periodo.
                </p>
            </div>
        </div>

    </div>

    <!-- Desglose del Periodo (todo desde el ledger) -->
    <div class="card p-6" :class="cargando ? 'opacity-50' : ''">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Desglose del Periodo — P&L del Libro Mayor</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 text-sm">
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">Efectivo</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ <span x-text="money(datos.metodo.efectivo)"></span></p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">BAC</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ <span x-text="money(datos.metodo.bac)"></span></p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">LAFISE</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ <span x-text="money(datos.metodo.lafise)"></span></p>
            </div>
            <div class="bg-orange-50 p-4 rounded-lg border border-orange-100">
                <p class="text-xs font-bold text-orange-600 uppercase">Costo Ventas (5.x)</p>
                <p class="text-lg font-black text-orange-700 mt-1">C$ <span x-text="money(datos.kpis.costos)"></span></p>
            </div>
            <div class="bg-red-50 p-4 rounded-lg border border-red-100">
                <p class="text-xs font-bold text-red-600 uppercase">Gastos Oper. (6.x)</p>
                <p class="text-lg font-black text-red-700 mt-1">C$ <span x-text="money(datos.kpis.gastos_operativos)"></span></p>
            </div>
            <div class="bg-red-50 p-4 rounded-lg border border-red-100">
                <p class="text-xs font-bold text-red-600 uppercase">Otros Egresos (7.x)</p>
                <p class="text-lg font-black text-red-700 mt-1">C$ <span x-text="money(datos.kpis.otros_egresos)"></span></p>
            </div>
            <div class="bg-emerald-50 p-4 rounded-lg border border-emerald-100">
                <p class="text-xs font-bold text-emerald-700 uppercase">Utilidad Neta</p>
                <p class="text-lg font-black text-emerald-700 mt-1">
                    C$ <span x-text="money((datos.kpis.utilidad_bruta ?? 0) - (datos.kpis.gastos_operativos ?? 0) - (datos.kpis.otros_egresos ?? 0))"></span>
                </p>
            </div>
        </div>
    </div>

    <!-- Últimos Movimientos (drill-down: clic = Quick View) -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Últimos Movimientos</h3>
                <p class="text-xs text-gray-500 mt-1">Clic en una fila para ver el ticket y su asiento contable.</p>
            </div>
            <a href="{{ url('/historial-ventas') }}" class="btn btn-secondary py-2 text-sm">Ver historial &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Hora</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Cajero</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Método de Pago</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Descuento</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Ingreso Neto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($ultimasVentas as $venta)
                        @php [$badgeTexto, $badgeClases] = $badgeEstados[$venta->status] ?? ['—', 'bg-gray-100 text-gray-600 border-gray-200']; @endphp
                        <tr @click="abrirMovimiento({{ $venta->id }})" tabindex="0"
                            @keydown.enter="abrirMovimiento({{ $venta->id }})"
                            class="hover:bg-emerald-50/40 transition cursor-pointer focus:outline-none focus:bg-emerald-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                {{ $venta->created_at->format('h:i A') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full border {{ $badgeClases }}">{{ $badgeTexto }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                {{ $venta->cajero?->name ?? 'Admin Salón' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                @if($venta->payment_method == 'efectivo')
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Efectivo</span>
                                @elseif(in_array($venta->payment_method, ['bac', 'lafise']))
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-blue-50 text-blue-700 border border-blue-200">{{ strtoupper($venta->payment_method) }}</span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-purple-50 text-purple-700 border border-purple-200">{{ ucfirst($venta->payment_method ?? '—') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold {{ $venta->discount > 0 ? 'text-red-500' : 'text-gray-300' }}">
                                {{ $venta->discount > 0 ? '- C$ ' . number_format($venta->discount, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-black text-emerald-700 text-base">
                                C$ {{ number_format($venta->total, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-gray-500 text-sm font-medium">
                                No hay movimientos registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- QUICK VIEW: modal de detalle del movimiento (drill-down) -->
    <div x-cloak x-show="modal.abierto" x-transition.opacity.duration.150ms class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-gray-900/50" @click="cerrarModal()"></div>

        <div class="relative bg-white rounded-2xl shadow-xl border border-gray-100 max-w-2xl w-full mx-auto my-10 max-h-[85vh] overflow-y-auto"
             x-show="modal.abierto" x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">

            <!-- Cargando -->
            <div x-show="modal.cargando" class="py-20 text-center text-gray-500 text-sm font-bold">
                <svg class="w-8 h-8 animate-spin mx-auto mb-3 text-emerald-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Cargando detalle del movimiento…
            </div>

            <!-- Error -->
            <div x-cloak x-show="modal.error" class="py-20 text-center">
                <p class="text-red-600 text-sm font-bold" x-text="modal.error"></p>
                <button type="button" @click="cerrarModal()" class="mt-4 btn btn-secondary py-2 text-sm">Cerrar</button>
            </div>

            <!-- Contenido -->
            <template x-if="modal.movimiento">
                <div>
                    <div class="flex items-start justify-between px-6 py-5 border-b border-gray-100 bg-gray-50/60 rounded-t-2xl">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-black text-gray-900">Factura #<span x-text="modal.movimiento.id"></span></h3>
                                <span class="px-2.5 py-0.5 inline-flex text-xs font-bold rounded-full border"
                                    :class="estadoVenta(modal.movimiento.estado).clases"
                                    x-text="estadoVenta(modal.movimiento.estado).texto"></span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                <span x-text="modal.movimiento.fecha"></span> · <span x-text="modal.movimiento.hora"></span>
                            </p>
                        </div>
                        <button type="button" @click="cerrarModal()" class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition cursor-pointer" aria-label="Cerrar">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    <div class="px-6 py-5 space-y-5">
                        <!-- Datos generales -->
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-[10px] font-bold text-gray-400 uppercase">Cliente</p>
                                <p class="font-bold text-gray-900" x-text="modal.movimiento.cliente"></p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-3">
                                <p class="text-[10px] font-bold text-gray-400 uppercase">Cajero</p>
                                <p class="font-bold text-gray-900" x-text="modal.movimiento.cajero"></p>
                            </div>
                        </div>

                        <!-- Anulación (si aplica) -->
                        <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-sm" x-show="modal.movimiento.anulacion" x-cloak>
                            <p class="text-xs font-black text-red-700 uppercase mb-1">Factura anulada</p>
                            <p class="text-red-700" x-text="modal.movimiento.anulacion?.motivo"></p>
                            <p class="text-xs text-red-500 mt-1" x-text="modal.movimiento.anulacion?.por + ' · ' + modal.movimiento.anulacion?.fecha"></p>
                        </div>

                        <!-- Líneas del ticket -->
                        <div>
                            <p class="text-xs font-black text-gray-400 uppercase tracking-wide mb-2">Detalle del ticket</p>
                            <div class="border border-gray-100 rounded-lg overflow-hidden">
                                <table class="min-w-full divide-y divide-gray-100 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-[10px] font-bold text-gray-500 uppercase">Ítem</th>
                                            <th class="px-4 py-2 text-center text-[10px] font-bold text-gray-500 uppercase">Cant.</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-gray-500 uppercase">Precio</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-bold text-gray-500 uppercase">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50">
                                        <template x-for="(item, i) in modal.movimiento.items" :key="i">
                                            <tr>
                                                <td class="px-4 py-2.5">
                                                    <p class="font-bold text-gray-900" x-text="item.producto"></p>
                                                    <p class="text-xs text-gray-400" x-show="item.estilista" x-text="'Atendido por ' + item.estilista"></p>
                                                </td>
                                                <td class="px-4 py-2.5 text-center text-gray-600" x-text="item.cantidad"></td>
                                                <td class="px-4 py-2.5 text-right text-gray-600">C$ <span x-text="money(item.precio)"></span></td>
                                                <td class="px-4 py-2.5 text-right font-bold text-gray-900">C$ <span x-text="money(item.subtotal)"></span></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Libro de pagos -->
                        <div>
                            <p class="text-xs font-black text-gray-400 uppercase tracking-wide mb-2">Libro de pagos</p>
                            <div class="space-y-2">
                                <template x-for="(pago, i) in modal.movimiento.pagos" :key="i">
                                    <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2 text-sm">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded"
                                                :class="pago.tipo === 'vuelto' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'"
                                                x-text="pago.tipo === 'vuelto' ? 'Vuelto' : 'Pago'"></span>
                                            <span class="font-bold text-gray-700 capitalize" x-text="pago.metodo"></span>
                                            <span class="text-gray-400 uppercase text-xs font-bold" x-text="pago.moneda"></span>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-black text-gray-900" x-text="(pago.tipo === 'vuelto' ? '- ' : '') + pago.moneda.toUpperCase() + ' ' + money(pago.monto)"></p>
                                            <p class="text-[10px] text-gray-400">C$ <span x-text="money(pago.valor_nio)"></span></p>
                                        </div>
                                    </div>
                                </template>
                                <p class="text-xs text-gray-500 text-right pt-1">
                                    Descuento: <span class="font-bold" x-text="'C$ ' + money(modal.movimiento.descuento)"></span> ·
                                    Total: <span class="font-black text-emerald-700" x-text="'C$ ' + money(modal.movimiento.total)"></span>
                                </p>
                            </div>
                        </div>

                        <!-- Asientos contables (drill-down al diario, solo finanzas) -->
                        <div x-show="flags.puedeFinanzas && modal.movimiento.asientos?.length" x-cloak>
                            <p class="text-xs font-black text-gray-400 uppercase tracking-wide mb-2">Trazabilidad contable</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="asiento in modal.movimiento.asientos" :key="asiento.id">
                                    <a :href="'/asientos/' + asiento.id" target="_blank"
                                        class="inline-flex items-center gap-1.5 bg-white border border-gray-200 rounded-lg px-3 py-1.5 text-xs font-bold text-gray-700 hover:border-emerald-300 hover:text-emerald-700 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                        <span x-text="asiento.numero"></span>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-100 flex justify-between items-center bg-gray-50/60 rounded-b-2xl">
                        <a :href="'/ventas/' + modal.movimiento.id + '/ticket'" target="_blank" x-show="flags.puedeCaja" x-cloak
                            class="btn btn-secondary py-2 text-sm">Ver ticket completo &rarr;</a>
                        <span x-show="!flags.puedeCaja"></span>
                        <button type="button" @click="cerrarModal()" class="btn bg-gray-800 text-white py-2 text-sm cursor-pointer">Cerrar</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

</div>
@endsection
