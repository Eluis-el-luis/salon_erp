@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Wizard de Nómina — Paso 2: Previsualización</h2>
        <p class="page-subtitle">Revise la liquidación calculada. <strong>Nada está contabilizado todavía</strong>: al confirmar se generan colillas, se cierran comisiones/adelantos/propinas y se registran los asientos.</p>
    </div>

    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    {{-- Resumen del período --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Período</p><p class="text-sm font-black text-gray-800">{{ \Carbon\Carbon::parse($datos['periodo_inicio'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($datos['periodo_fin'])->format('d/m/Y') }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Total ingresos</p><p class="text-lg font-black text-blue-600">C$ {{ number_format($preview['totales']['ingresos'], 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Total deducciones</p><p class="text-lg font-black text-red-600">C$ {{ number_format($preview['totales']['deducciones'], 2) }}</p></div>
        <div class="card p-4"><p class="text-[11px] font-bold text-gray-500 uppercase">Neto a pagar</p><p class="text-lg font-black text-emerald-600">C$ {{ number_format($preview['totales']['neto'], 2) }}</p></div>
    </div>

    {{-- Colillas calculadas --}}
    @foreach($preview['colillas'] as $colilla)
        <div class="card overflow-hidden">
            <div class="card-header flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 class="font-black text-gray-800">{{ $colilla['empleado']->codigo_empleado }} · {{ $colilla['empleado']->nombre }}</h3>
                    <p class="text-xs text-gray-500">Estructura: {{ $colilla['estructura']->nombre }}
                        · {{ $colilla['resumen']['dias_sin_goce'] }} d sin goce
                        · {{ $colilla['resumen']['horas_extras'] }} h extra
                        · {{ $colilla['resumen']['tardanzas'] }} tardanzas
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-[11px] font-bold text-gray-400 uppercase">Neto a recibir</p>
                    <p class="text-lg font-black text-emerald-600">C$ {{ number_format($colilla['neto_pagar'], 2) }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-100">
                {{-- Ingresos --}}
                <div class="p-4">
                    <p class="text-xs font-black text-emerald-700 uppercase mb-2">Ingresos</p>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse($colilla['lineas'] as $linea)
                                @if($linea['tipo'] === 'ingreso')
                                    <tr>
                                        <td class="py-1 text-gray-700">{{ $linea['concepto'] }}</td>
                                        <td class="py-1 text-right font-bold text-emerald-700">C$ {{ number_format($linea['monto'], 2) }}</td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td class="py-2 text-gray-400">Sin ingresos en el período.</td></tr>
                            @endforelse
                            <tr class="border-t border-gray-200">
                                <td class="py-1.5 font-black text-gray-700">Total ingresos</td>
                                <td class="py-1.5 text-right font-black text-emerald-700">C$ {{ number_format($colilla['total_ingresos'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                {{-- Deducciones --}}
                <div class="p-4">
                    <p class="text-xs font-black text-red-700 uppercase mb-2">Deducciones</p>
                    <table class="w-full text-sm">
                        <tbody>
                            @forelse($colilla['lineas'] as $linea)
                                @if($linea['tipo'] === 'deduccion')
                                    <tr>
                                        <td class="py-1 text-gray-700">{{ $linea['concepto'] }}</td>
                                        <td class="py-1 text-right font-bold text-red-600">C$ {{ number_format($linea['monto'], 2) }}</td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td class="py-2 text-gray-400">Sin deducciones en el período.</td></tr>
                            @endforelse
                            <tr class="border-t border-gray-200">
                                <td class="py-1.5 font-black text-gray-700">Total deducciones</td>
                                <td class="py-1.5 text-right font-black text-red-600">C$ {{ number_format($colilla['total_deducciones'], 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @if($colilla['resumen']['comisiones_pendientes'] > 0)
                <p class="px-4 pb-3 text-[11px] text-gray-500">ℹ️ Se cerrarán <strong>{{ $colilla['resumen']['comisiones_pendientes'] }}</strong> comisiones congeladas y <strong>{{ count($colilla['propinas_ids']) }}</strong> propinas en custodia.</p>
            @endif
        </div>
    @endforeach

    {{-- Confirmación --}}
    <div class="card p-6">
        <h3 class="font-black text-gray-800 mb-2">Confirmar y Contabilizar</h3>
        <p class="text-sm text-gray-600 mb-4">Se generará un asiento <code class="bg-gray-100 px-1 rounded">NOM-*</code> por colilla con la estructura: DEBE Gasto de Sueldos (6.7) + Gasto Comisiones (5.3) + Propinas por Pagar (2.1.6) — HABER Anticipos (1.1.6) + Retenciones (2.1.5) + Propinas en Custodia (1.1.1.3) + {{ $datos['metodo_pago'] === 'banco' ? 'Banco (1.1.2.x)' : 'Caja (1.1.1)' }}.</p>
        <div class="flex gap-3">
            <a href="/nomina/wizard" class="btn btn-secondary">← Volver a la selección</a>
            <form action="/nomina/procesar" method="POST" x-data="{ confirmado: false }" @submit="if (!confirmado) { confirmado = confirm('¿Contabilizar la nómina? Esta operación registra asientos y cierra comisiones.'); return confirmado; }">
                @csrf
                <input type="hidden" name="periodo_inicio" value="{{ $datos['periodo_inicio'] }}">
                <input type="hidden" name="periodo_fin" value="{{ $datos['periodo_fin'] }}">
                <input type="hidden" name="metodo_pago" value="{{ $datos['metodo_pago'] }}">
                @if(isset($datos['estructura_id'])) <input type="hidden" name="estructura_id" value="{{ $datos['estructura_id'] }}"> @endif
                @foreach($datos['empleado_ids'] as $id)
                    <input type="hidden" name="empleado_ids[]" value="{{ $id }}">
                @endforeach
                <button type="submit" class="btn btn-primary">🔒 Procesar y Contabilizar Corrida</button>
            </form>
        </div>
    </div>
</div>
@endsection
