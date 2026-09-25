@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    <!-- Encabezado + Filtro de Rango -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h1 class="page-title">Centro de Mando</h1>
            <p class="page-subtitle">Inteligencia de negocio — resumen del periodo.</p>
        </div>

        <div class="flex items-center gap-1 bg-white p-1 rounded-xl border border-gray-200 shadow-sm">
            @php $rangos = ['hoy' => 'Hoy', 'semana' => 'Semana', 'mes' => 'Mes', 'año' => 'Año']; @endphp
            @foreach($rangos as $key => $label)
                <a href="{{ url('/?rango=' . $key) }}"
                   class="px-4 py-2 rounded-lg text-sm font-bold transition {{ $rango === $key ? 'bg-emerald-700 text-white shadow-sm' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Ingresos del Periodo</p>
                <p class="text-2xl font-black text-gray-900">C$ {{ number_format($totalIngresos, 2) }}</p>
            </div>
        </div>

        <div class="card p-6 flex items-center space-x-4 border-l-4 border-l-emerald-600">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Utilidad Bruta</p>
                <p class="text-2xl font-black text-emerald-600">C$ {{ number_format($utilidadBruta, 2) }}</p>
                <p class="text-[10px] text-gray-400 font-medium">Ingresos - Costos (5.1 + 5.2)</p>
            </div>
        </div>

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full bg-purple-100 text-purple-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Agenda (Hoy)</p>
                <p class="text-2xl font-black text-gray-900">{{ $citasPendientes }} <span class="text-sm font-medium text-gray-400">pendientes</span></p>
                <p class="text-xs text-gray-500 mt-1 font-medium">De {{ $citasTotal }} citas</p>
            </div>
        </div>

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full {{ $stockCritico > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600' }}">
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
            </div>
        </div>

    </div>

    <!-- Gráficos -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Línea: últimos 7 días -->
        <div class="card p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Ventas — Últimos 7 Días</h3>
            <canvas id="chartVentas" height="100"></canvas>
        </div>

        <!-- Donut: métodos de pago -->
        <div class="card p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Ingresos por Método</h3>
            <canvas id="chartPagos" height="180"></canvas>
        </div>

    </div>

    <!-- Meta + Ranking -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Widget Meta Mensual -->
        <div class="card p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-lg font-bold text-gray-800">Meta Mensual</h3>
                <span class="text-xs font-bold text-emerald-700 bg-emerald-50 px-2 py-1 rounded">{{ $progresoMeta }}%</span>
            </div>
            <div class="h-3 bg-gray-100 rounded-full overflow-hidden mb-3">
                <div class="h-full bg-emerald-600 rounded-full transition-all" style="width: {{ $progresoMeta }}%"></div>
            </div>
            <div class="flex justify-between text-sm">
                <span class="font-bold text-gray-900">C$ {{ number_format($ventasMes, 2) }}</span>
                <span class="text-gray-500">de C$ {{ number_format($metaMensual, 2) }}</span>
            </div>
            <p class="text-xs text-gray-400 mt-3 font-medium">Avance de facturación del mes en curso.</p>
        </div>

        <!-- Ranking de Servicios -->
        <div class="card p-6 lg:col-span-2">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Ranking de Servicios Más Vendidos</h3>
            <div class="space-y-3">
                @forelse($serviciosMasVendidos as $i => $sv)
                    <div class="flex items-center gap-3">
                        <span class="w-7 h-7 flex items-center justify-center rounded-full text-xs font-black {{ $i == 0 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $i + 1 }}
                        </span>
                        <div class="flex-1">
                            <p class="text-sm font-bold text-gray-900">{{ $sv->servicio->name ?? 'Servicio' }}</p>
                            <p class="text-xs text-gray-500">{{ $sv->total_cantidad }} unidades vendidas</p>
                        </div>
                        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden max-w-[160px]">
                            <div class="h-full bg-emerald-600 rounded-full" style="width: {{ $serviciosMasVendidos->first()->total_cantidad > 0 ? round($sv->total_cantidad / $serviciosMasVendidos->first()->total_cantidad * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 text-center py-4">Sin ventas de servicios en el periodo.</p>
                @endforelse
            </div>
        </div>

    </div>

    <!-- Ingresos por vía + Egresos -->
    <div class="card p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Desglose del Periodo</h3>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">Efectivo</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ {{ number_format($ventasEfectivo, 2) }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">BAC</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ {{ number_format($ventasBac, 2) }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">LAFISE</p>
                <p class="text-lg font-black text-gray-900 mt-1">C$ {{ number_format($ventasLafise, 2) }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-100">
                <p class="text-xs font-bold text-gray-500 uppercase">Egresos Caja Chica</p>
                <p class="text-lg font-black text-red-600 mt-1">- C$ {{ number_format($egresosCaja, 2) }}</p>
            </div>
            <div class="bg-emerald-50 p-4 rounded-lg border border-emerald-100">
                <p class="text-xs font-bold text-emerald-700 uppercase">Costo Insumos + Mercadería</p>
                <p class="text-lg font-black text-emerald-700 mt-1">C$ {{ number_format($costoTotal, 2) }}</p>
            </div>
        </div>
    </div>

    <!-- Últimos Movimientos -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Últimos Movimientos</h3>
                <p class="text-xs text-gray-500 mt-1">Transacciones procesadas recientemente en la caja.</p>
            </div>
            <a href="{{ url('/historial-ventas') }}" class="btn btn-secondary py-2 text-sm">Ver historial &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Hora</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Cajero</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Método de Pago</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Descuento</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Ingreso Neto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($ultimasVentas as $venta)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                {{ $venta->created_at->format('h:i A') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                {{ $venta->cajero ? $venta->cajero->name : 'Admin Salón' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                @if($venta->payment_method == 'efectivo')
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Efectivo</span>
                                @elseif(in_array($venta->payment_method, ['bac', 'lafise']))
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-blue-50 text-blue-700 border border-blue-200">{{ strtoupper($venta->payment_method) }}</span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-purple-50 text-purple-700 border border-purple-200">{{ ucfirst($venta->payment_method) }}</span>
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
                            <td colspan="5" class="px-6 py-10 text-center text-gray-500 text-sm font-medium">
                                No hay movimientos registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const dias = @json($ventas7Dias->pluck('fecha'));
        const totales = @json($ventas7Dias->pluck('total'));

        new Chart(document.getElementById('chartVentas'), {
            type: 'line',
            data: {
                labels: dias,
                datasets: [{
                    label: 'Ventas (C$)',
                    data: totales,
                    borderColor: '#047857',
                    backgroundColor: 'rgba(4,120,87,0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => 'C$ ' + v.toLocaleString() } } }
            }
        });

        const pagos = @json($pagosDonut);
        new Chart(document.getElementById('chartPagos'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(pagos),
                datasets: [{
                    data: Object.values(pagos),
                    backgroundColor: ['#10b981', '#3b82f6', '#0d9488'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': C$ ' + ctx.raw.toLocaleString() } }
                }
            }
        });
    });
</script>
@endsection