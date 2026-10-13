@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-emerald-600 text-white flex items-center justify-center text-xl font-black">
                {{ strtoupper(substr($empleado->nombre, 0, 2)) }}
            </div>
            <div>
                <h2 class="page-title">{{ $empleado->nombre }}</h2>
                <p class="page-subtitle">{{ $empleado->codigo_empleado }} · {{ ucfirst($empleado->usuario->role ?? 'sin rol') }} @if($empleado->cargo) · {{ $empleado->cargo }} @endif</p>
            </div>
        </div>
        <div class="flex gap-2">
            <span class="px-3 py-2 text-xs font-black rounded-full {{ $empleado->estado_laboral === 'activo' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }} uppercase">
                {{ $empleado->estado_laboral }}
            </span>
            <a href="/rrhh" class="btn btn-secondary">← Volver</a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Expediente / Contrato --}}
        <div class="card p-6">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Expediente y Contrato</h3>
            <dl class="space-y-3 text-sm">
                @php
                    $campos = [
                        'Tipo de contrato' => ucfirst(str_replace('_', ' ', $empleado->tipo_contrato)),
                        'Fecha de ingreso' => $empleado->fecha_ingreso?->format('d/m/Y') ?? '—',
                        'Salario base' => 'C$ ' . number_format((float) $empleado->salario_base, 2),
                        'Periodicidad' => ucfirst($empleado->periodicidad_pago),
                        'Turno vigente' => $turnoVigente ? ($turnoVigente->nombre . ' (' . substr($turnoVigente->hora_entrada, 0, 5) . '–' . substr($turnoVigente->hora_salida, 0, 5) . ')') : 'Sin turno asignado',
                        'Estructura salarial' => $empleado->estructuraActual()?->nombre ?? '—',
                        'Documento' => $empleado->documento_identidad ?? '—',
                        'INSS (NSS)' => $empleado->nss ?? '—',
                        'Banco de depósito' => trim(($empleado->banco_deposito ?? '') . ' ' . ($empleado->cuenta_bancaria_deposito ?? '')) ?: '—',
                        'Dirección' => $empleado->direccion ?? '—',
                    ];
                @endphp
                @foreach($campos as $etiqueta => $valor)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 font-bold">{{ $etiqueta }}</dt>
                        <dd class="text-gray-800 text-right">{{ $valor }}</dd>
                    </div>
                @endforeach
            </dl>
            @if($empleado->notas)
                <p class="mt-4 text-xs bg-gray-50 border border-gray-200 rounded p-3 text-gray-600">{{ $empleado->notas }}</p>
            @endif
        </div>

        {{-- Indicadores 30 días --}}
        <div class="card p-6">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Asistencia (últimos 30 días)</h3>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-gray-50 rounded-lg p-3"><p class="text-[11px] font-bold text-gray-500 uppercase">Días registrados</p><p class="text-xl font-black text-gray-800">{{ $asistencia['dias_registrados'] }}</p></div>
                <div class="bg-gray-50 rounded-lg p-3"><p class="text-[11px] font-bold text-gray-500 uppercase">Horas extra</p><p class="text-xl font-black text-blue-600">{{ $asistencia['horas_extras'] }} h</p></div>
                <div class="bg-gray-50 rounded-lg p-3"><p class="text-[11px] font-bold text-gray-500 uppercase">Tardanzas</p><p class="text-xl font-black text-red-600">{{ $asistencia['tardanzas'] }}</p></div>
                <div class="bg-gray-50 rounded-lg p-3"><p class="text-[11px] font-bold text-gray-500 uppercase">Minutos perdidos</p><p class="text-xl font-black text-red-600">{{ $asistencia['minutos_tardanza'] }}</p></div>
            </div>

            <h3 class="font-black text-gray-800 border-b pb-2 my-4">Cuenta con la Casa</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500 font-bold">Comisiones pendientes</dt><dd class="font-black text-emerald-600">C$ {{ number_format($comisiones['monto'], 2) }} ({{ $comisiones['pendientes'] }})</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 font-bold">Adelantos por amortizar</dt><dd class="font-black text-red-600">C$ {{ number_format($cuentaAdelantos['saldo'], 2) }} ({{ $cuentaAdelantos['cuotas'] }} cuotas)</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500 font-bold">Propinas en custodia</dt><dd class="font-black text-purple-600">C$ {{ number_format($propinasPendientes, 2) }}</dd></div>
            </dl>
        </div>

        {{-- Colillas recientes --}}
        <div class="card p-6">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Colillas de Nómina</h3>
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @forelse($colillas as $colilla)
                    <a href="/nomina/colilla/{{ $colilla->id }}" target="_blank" class="flex justify-between items-center p-2 rounded hover:bg-gray-50 border border-gray-100">
                        <div>
                            <p class="text-xs font-bold text-gray-700">{{ $colilla->periodo_inicio->format('d/m') }} – {{ $colilla->periodo_fin->format('d/m/Y') }}</p>
                            <p class="text-[10px] text-gray-400">{{ $colilla->corrida?->metodo_pago === 'banco' ? 'Banco' : 'Efectivo' }} · {{ ucfirst($colilla->estado) }}</p>
                        </div>
                        <span class="text-sm font-black text-emerald-600">C$ {{ number_format((float) $colilla->neto_pagar, 2) }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-500 text-center py-4">Sin colillas generadas.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Historial de ausencias --}}
    <div class="card overflow-hidden">
        <div class="card-header flex justify-between items-center">
            <h3 class="font-black text-gray-800">Historial de Ausencias</h3>
            <a href="/ausencias" class="text-emerald-600 font-bold text-sm hover:underline">Nueva solicitud →</a>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Tipo</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Período</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Días</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Revisó</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($ausencias as $ausencia)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-sm font-bold text-gray-700">{{ $ausencia->tipo->nombre }}</td>
                        <td class="px-6 py-3 text-sm text-gray-600">{{ $ausencia->fecha_inicio->format('d/m/Y') }} → {{ $ausencia->fecha_fin->format('d/m/Y') }}</td>
                        <td class="px-6 py-3 text-center text-sm">{{ $ausencia->dias }}</td>
                        <td class="px-6 py-3 text-center">
                            <span class="px-2 py-1 text-[10px] font-black rounded-full uppercase {{ $ausencia->estado === 'aprobada' ? 'bg-emerald-100 text-emerald-700' : ($ausencia->estado === 'pendiente' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-500') }}">{{ $ausencia->estado }}</span>
                        </td>
                        <td class="px-6 py-3 text-sm text-gray-500">{{ $ausencia->revisor?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">Sin ausencias registradas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
