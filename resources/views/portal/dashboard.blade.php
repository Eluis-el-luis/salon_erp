@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="portalDashboard()">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="page-title">Mi Portal</h1>
            <p class="page-subtitle">Bienvenido, {{ $user->name }}</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500">{{ $user->role }}</span>
            <a href="{{ route('portal.logout') }}" class="btn btn-secondary text-sm">Cerrar sesión</a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">Comisiones Pendientes</p>
            <p class="text-2xl font-black text-red-600">C$ {{ number_format($pendientes, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">Comisiones Pagadas</p>
            <p class="text-2xl font-black text-emerald-600">C$ {{ number_format($pagadas, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Comisiones ({{ $diasHistorial }} días)</p>
            <p class="text-2xl font-black text-gray-900">C$ {{ number_format($totalComisiones, 2) }}</p>
        </div>
        <div class="card p-5 border-l-4 border-blue-500">
            <p class="text-xs font-bold text-gray-500 uppercase">Cuotas Pendientes</p>
            <p class="text-2xl font-black text-blue-600">{{ $cuotasPendientes->count() }}</p>
        </div>
    </div>

    <!-- Gráfico de comisiones -->
    <div class="card p-5">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Evolución de Comisiones ({{ $diasHistorial }} días)</p>
        <canvas id="chartComisiones" height="80"></canvas>
    </div>

    <!-- Últimas Comisiones -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-900">Últimas Comisiones Generadas</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-white">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Venta</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Servicio</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Monto Venta</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">% Comisión</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Comisión</th>
                        <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Estado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($comisiones as $comision)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-600 font-medium whitespace-nowrap">{{ \Carbon\Carbon::parse($comision->fecha)->format('d/m/Y') }}</td>
                            <td class="px-6 py-3 text-gray-900 font-medium">#{{ $comision->venta_id }}</td>
                            <td class="px-6 py-3 text-gray-600">{{ $comision->venta?->detalles->first()?->servicio?->name ?? 'Servicio' }}</td>
                            <td class="px-6 py-3 text-right font-bold text-gray-900">C$ {{ number_format($comision->monto_venta, 2) }}</td>
                            <td class="px-6 py-3 text-right text-gray-600">{{ $comision->porcentaje_aplicado }}%</td>
                            <td class="px-6 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($comision->monto_comision, 2) }}</td>
                            <td class="px-6 py-3 text-center">
                                @if($comision->estado === 'pagada')
                                    <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Pagada</span>
                                @else
                                    <span class="px-2 py-1 rounded text-xs font-bold bg-amber-100 text-amber-800">Pendiente</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">No hay comisiones en el periodo.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $comisiones->links() }}
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    function portalDashboard() {
        return {
            comisionesChart: null,
            init() {
                this.initChart();
            },
            initChart() {
                const ctx = document.getElementById('chartComisiones');
                if (!ctx) return;
                
                const labels = [];
                const data = [];
                for (let i = 29; i >= 0; i--) {
                    const d = new Date();
                    d.setDate(d.getDate() - i);
                    labels.push(d.toLocaleDateString('es-NI', {day: '2-digit', month: '2-digit'}));
                    data.push(Math.floor(Math.random() * 5000));
                }

                this.comisionesChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Comisiones (C$)',
                            data: data,
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
            }
        }
    }
</script>
@endsection