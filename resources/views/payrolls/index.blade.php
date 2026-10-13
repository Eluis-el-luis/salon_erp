@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Nómina Corporativa</h2>
            <p class="page-subtitle">Corridas de nómina con estructuras salariales, colillas auditables y asientos contables por colaborador.</p>
        </div>
        <div class="flex gap-2">
            <a href="/nomina/wizard" class="btn btn-primary">⚡ Nueva Corrida (Wizard)</a>
            <a href="/nomina/estructuras" class="btn btn-secondary">Estructuras Salariales</a>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Corridas (mes)</p><p class="text-xl font-black text-gray-800">{{ number_format($kpis['corridas_mes']) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Neto pagado (mes)</p><p class="text-xl font-black text-emerald-600">C$ {{ number_format($kpis['neto_mes'], 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Comisiones pendientes</p><p class="text-xl font-black text-blue-600">C$ {{ number_format($kpis['comisiones_pendientes'], 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Cuotas vencidas</p><p class="text-xl font-black text-red-600">C$ {{ number_format($kpis['cuotas_vencidas'], 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Propinas en custodia</p><p class="text-xl font-black text-purple-600">C$ {{ number_format($kpis['propinas_pendientes'], 2) }}</p></div>
    </div>

    {{-- Corridas --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Corridas de Nómina</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">#</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Período</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Colillas</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Ingresos</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Deducciones</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Neto pagado</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($corridas as $corrida)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-xs font-black text-gray-400">#{{ $corrida->id }}</td>
                            <td class="px-6 py-3 text-sm font-bold text-gray-700">{{ $corrida->periodo_inicio->format('d/m/Y') }} – {{ $corrida->periodo_fin->format('d/m/Y') }}
                                <span class="block text-[10px] text-gray-400">{{ $corrida->metodo_pago === 'banco' ? 'Pago por banco' : 'Pago en efectivo' }}</span>
                            </td>
                            <td class="px-6 py-3 text-center text-sm">{{ $corrida->colillas->count() }}</td>
                            <td class="px-6 py-3 text-right text-sm">C$ {{ number_format((float) $corrida->total_ingresos, 2) }}</td>
                            <td class="px-6 py-3 text-right text-sm text-red-500">C$ {{ number_format((float) $corrida->total_deducciones, 2) }}</td>
                            <td class="px-6 py-3 text-right text-sm font-black text-emerald-600">C$ {{ number_format((float) $corrida->total_neto, 2) }}</td>
                            <td class="px-6 py-3 text-center">
                                <span class="px-2 py-1 text-[10px] font-black rounded-full uppercase {{ $corrida->estado === 'contabilizada' ? 'bg-emerald-100 text-emerald-700' : ($corrida->estado === 'anulada' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $corrida->estado }}</span>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <a href="/nomina/corrida/{{ $corrida->id }}" class="text-emerald-600 font-bold text-sm hover:underline">Detalle</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">Aún no hay corridas. Use el wizard para procesar la primera.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Historial legacy --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Planillas Legacy (pre-HRMS)</h3><p class="text-xs text-gray-500">Historial anterior al motor corporativo. Solo lectura.</p></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Período</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Neto</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Colilla</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($nominasLegacy as $nomina)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $nomina->usuario->name ?? '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600">{{ \Carbon\Carbon::parse($nomina->start_date)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($nomina->end_date)->format('d/m/Y') }}</td>
                            <td class="px-6 py-3 text-right text-sm font-black text-emerald-600">C$ {{ number_format((float) $nomina->total_to_pay, 2) }}</td>
                            <td class="px-6 py-3 text-center"><span class="px-2 py-1 text-[10px] font-black rounded-full {{ $nomina->status === 'pagada' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $nomina->status }}</span></td>
                            <td class="px-6 py-3 text-center"><a href="/nomina/legacy/{{ $nomina->id }}/ticket" target="_blank" class="text-emerald-600 font-bold text-sm hover:underline">Ver</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-6 text-center text-sm text-gray-500">Sin planillas legacy.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
