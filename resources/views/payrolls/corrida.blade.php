@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Corrida de Nómina #{{ $corrida->id }}</h2>
            <p class="page-subtitle">{{ $corrida->periodo_inicio->format('d/m/Y') }} – {{ $corrida->periodo_fin->format('d/m/Y') }} · {{ $corrida->metodo_pago === 'banco' ? 'Pago por banco' : 'Pago en efectivo' }} · Procesada por {{ $corrida->creador?->name ?? 'sistema' }}</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="px-3 py-2 text-xs font-black rounded-full uppercase {{ $corrida->estado === 'contabilizada' ? 'bg-emerald-100 text-emerald-700' : ($corrida->estado === 'anulada' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $corrida->estado }}</span>
            <a href="/nomina" class="btn btn-secondary">← Corridas</a>
        </div>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    @if($corrida->motivo_anulacion)
        <div class="bg-red-50 border-l-4 border-red-400 text-red-700 p-4 rounded text-sm"><strong>Motivo de anulación:</strong> {{ $corrida->motivo_anulacion }}</div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Total ingresos</p><p class="text-lg font-black text-blue-600">C$ {{ number_format((float) $corrida->total_ingresos, 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Total deducciones</p><p class="text-lg font-black text-red-600">C$ {{ number_format((float) $corrida->total_deducciones, 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Neto pagado</p><p class="text-lg font-black text-emerald-600">C$ {{ number_format((float) $corrida->total_neto, 2) }}</p></div>
    </div>

    {{-- Colillas --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Colillas de la Corrida</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Ingresos</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Deducciones</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Neto</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Asiento</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Colilla</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($corrida->colillas as $colilla)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <p class="text-sm font-bold text-gray-800">{{ $colilla->empleado->nombre }}</p>
                                <p class="text-xs text-gray-400">{{ $colilla->empleado->codigo_empleado }}</p>
                            </td>
                            <td class="px-6 py-3 text-right text-sm">C$ {{ number_format((float) $colilla->total_ingresos, 2) }}</td>
                            <td class="px-6 py-3 text-right text-sm text-red-500">C$ {{ number_format((float) $colilla->total_deducciones, 2) }}</td>
                            <td class="px-6 py-3 text-right text-sm font-black text-emerald-600">C$ {{ number_format((float) $colilla->neto_pagar, 2) }}</td>
                            <td class="px-6 py-3 text-center text-xs font-mono">
                                @if($colilla->asiento)
                                    <a href="/asientos/{{ $colilla->asiento_contable_id }}" class="text-blue-600 font-bold hover:underline">{{ $colilla->asiento->numero_asiento }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-6 py-3 text-center">
                                <span class="px-2 py-1 text-[10px] font-black rounded-full uppercase {{ $colilla->estado === 'pagada' ? 'bg-emerald-100 text-emerald-700' : ($colilla->estado === 'anulada' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $colilla->estado }}</span>
                            </td>
                            <td class="px-6 py-3 text-center"><a href="/nomina/colilla/{{ $colilla->id }}" target="_blank" class="text-emerald-600 font-bold text-sm hover:underline">Imprimir</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Anulación --}}
    @if($corrida->estado === 'contabilizada')
        <div class="card p-6 border-red-200" x-data="{ abierto: false }">
            <h3 class="font-black text-red-700">Zona de Reversión</h3>
            <p class="text-sm text-gray-600 mt-1">La anulación genera asientos espejo <code class="bg-gray-100 px-1 rounded">REV-*</code>, reabre comisiones, devuelve propinas a custodia y revierte cuotas amortizadas. El historial jamás se borra.</p>
            <button @click="abierto = !abierto" class="mt-3 bg-red-100 text-red-700 font-bold text-sm py-2 px-4 rounded-lg hover:bg-red-200">Anular corrida</button>
            <form action="/nomina/corrida/{{ $corrida->id }}/anular" method="POST" x-show="abierto" x-cloak class="mt-4 max-w-lg space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Motivo de anulación (obligatorio)</label>
                    <textarea name="motivo" rows="2" required minlength="5" maxlength="500" class="w-full border border-gray-300 rounded-md py-2 px-3" placeholder="Ej. Error de período duplicado con corrida #X"></textarea>
                </div>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg" onclick="return confirm('¿Confirma la anulación con asientos de reversión?')">Confirmar Anulación</button>
            </form>
        </div>
    @endif
</div>
@endsection
