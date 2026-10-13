@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Propinas en Custodia</h2>
        <p class="page-subtitle">Propinas que el salón guarda para el colaborador y liquida por nómina. Cada registro genera asiento: DEBE 1.1.1.3 Custodia / HABER 2.1.6 Propinas por Pagar.</p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Formulario de registro --}}
        <div class="card p-6">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Registrar Propinas Recibidas</h3>
            <form action="/rrhh/propinas" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Colaborador</label>
                    <select name="employee_id" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">Seleccione...</option>
                        @foreach($empleados as $empleado)
                            <option value="{{ $empleado->id }}">{{ $empleado->codigo_empleado }} · {{ $empleado->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Fecha</label>
                        <input type="date" name="fecha" value="{{ now()->toDateString() }}" required max="{{ now()->toDateString() }}" class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Monto (C$)</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Observación</label>
                    <input type="text" name="observacion" maxlength="255" placeholder="Ej. Propinas de la caja de sábado" class="w-full border border-gray-300 rounded-md py-2 px-3">
                </div>
                <button type="submit" class="btn btn-primary w-full">Registrar y Contabilizar</button>
            </form>
        </div>

        {{-- Pendientes por colaborador --}}
        <div class="card lg:col-span-2 overflow-hidden">
            <div class="card-header"><h3 class="font-black text-gray-800">Pendientes de Liquidación</h3></div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Registros</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total pendiente</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($pendientes as $codigo => $grupo)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <p class="text-sm font-bold text-gray-800">{{ $grupo->first()->empleado->nombre }}</p>
                                <p class="text-xs text-gray-400">{{ $codigo }}</p>
                            </td>
                            <td class="px-6 py-3 text-center text-sm">{{ $grupo->count() }}</td>
                            <td class="px-6 py-3 text-right text-sm font-black text-purple-600">C$ {{ number_format((float) $grupo->sum('monto'), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500">No hay propinas pendientes de liquidación.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Historial --}}
    <div class="card overflow-hidden">
        <div class="card-header"><h3 class="font-black text-gray-800">Historial de Propinas</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Observación</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Monto</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Liquidada en</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($historial as $propina)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-600">{{ $propina->fecha->format('d/m/Y') }}</td>
                            <td class="px-6 py-3 text-sm font-bold text-gray-800">{{ $propina->empleado->nombre }}</td>
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $propina->observacion ?? '—' }}</td>
                            <td class="px-6 py-3 text-right text-sm font-bold">C$ {{ number_format((float) $propina->monto, 2) }}</td>
                            <td class="px-6 py-3 text-center">
                                <span class="px-2 py-1 text-[10px] font-black rounded-full {{ $propina->estado === 'pagada' ? 'bg-emerald-100 text-emerald-700' : 'bg-purple-100 text-purple-700' }}">{{ $propina->estado === 'pagada' ? 'Liquidada' : 'En custodia' }}</span>
                            </td>
                            <td class="px-6 py-3 text-center text-xs text-gray-500">
                                @if($propina->colilla)
                                    <a href="/nomina/colilla/{{ $propina->colilla->id }}" target="_blank" class="text-emerald-600 font-bold hover:underline">Colilla #{{ $propina->colilla->id }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">Sin propinas registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
