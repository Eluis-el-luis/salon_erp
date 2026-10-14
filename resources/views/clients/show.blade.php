@extends('layouts.app')

@section('content')
@php
    $diasCredito = (int) ($cliente->credit_days ?? 0);
    $creditoDisponible = $cliente->creditoDisponible();
@endphp

<div x-data="{ tab: 'cxc' }" class="space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <a href="{{ url('/clientes') }}" class="text-xs font-bold text-gray-500 hover:text-emerald-600 transition">&larr; Volver al directorio</a>
            <h2 class="page-title mt-1">{{ $cliente->name }}</h2>
            <div class="flex flex-wrap items-center gap-2 mt-1">
                @if ($cliente->grupo)
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800">
                        {{ $cliente->grupo->name }}
                        @if ((float) $cliente->grupo->discount_percent > 0)
                            · {{ number_format($cliente->grupo->discount_percent, 0) }}% dscto
                        @endif
                    </span>
                @endif
                <span class="text-xs text-gray-500">{{ $cliente->phone ?? 'Sin teléfono' }}</span>
                <span class="text-xs text-gray-500">{{ $cliente->email ?? 'Sin correo' }}</span>
                @if ($cliente->birthday)
                    <span class="text-xs text-pink-600 font-semibold">Cumpleaños: {{ $cliente->birthday->format('d/m') }}</span>
                @endif
            </div>
        </div>

        <!-- Tarjeta de crédito -->
        <div class="bg-white rounded-xl shadow border border-gray-200 p-4 min-w-[260px]">
            <div class="text-xs font-bold text-gray-500 uppercase mb-2">Condiciones de Crédito</div>
            @if ($cliente->credit_limit !== null)
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Límite</span>
                    <span class="font-bold text-gray-900">C$ {{ number_format($cliente->credit_limit, 2) }}</span>
                </div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600">Disponible</span>
                    <span class="font-bold {{ $creditoDisponible > 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        C$ {{ number_format($creditoDisponible, 2) }}
                    </span>
                </div>
            @else
                <p class="text-sm text-gray-400 italic mb-1">Sin límite definido</p>
            @endif
            <div class="flex justify-between text-sm">
                <span class="text-gray-600">Días de crédito</span>
                <span class="font-bold text-gray-900">{{ $diasCredito }}</span>
            </div>
            @if ($metricas['facturas_vencidas'] > 0)
                <div class="mt-2 bg-red-50 border border-red-200 text-red-700 text-xs font-bold rounded-lg p-2">
                    Facturación a crédito BLOQUEADA: tiene facturas vencidas.
                </div>
            @endif
        </div>
    </div>

    <!-- MÉTRICAS 360 -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="card p-4">
            <div class="text-xs font-bold text-gray-500 uppercase">Total Facturado</div>
            <div class="text-xl font-black text-gray-900 mt-1">C$ {{ number_format($metricas['total_facturado'], 2) }}</div>
        </div>
        <div class="card p-4">
            <div class="text-xs font-bold text-gray-500 uppercase">Saldo Pendiente (AR)</div>
            <div class="text-xl font-black {{ $metricas['saldo_pendiente'] > 0 ? 'text-amber-600' : 'text-emerald-600' }} mt-1">
                C$ {{ number_format($metricas['saldo_pendiente'], 2) }}
            </div>
        </div>
        <div class="card p-4">
            <div class="text-xs font-bold text-gray-500 uppercase">Facturas Vencidas</div>
            <div class="text-xl font-black {{ $metricas['facturas_vencidas'] > 0 ? 'text-red-600' : 'text-gray-400' }} mt-1">
                {{ $metricas['facturas_vencidas'] }}
            </div>
        </div>
        <div class="card p-4">
            <div class="text-xs font-bold text-gray-500 uppercase">Total Citas</div>
            <div class="text-xl font-black text-gray-900 mt-1">{{ $metricas['total_citas'] }}</div>
        </div>
        <div class="card p-4">
            <div class="text-xs font-bold text-gray-500 uppercase">Última Visita</div>
            <div class="text-sm font-bold text-gray-900 mt-2">{{ $metricas['ultima_visita'] ?? 'Sin visitas' }}</div>
        </div>
    </div>

    @if ($cliente->notes)
        <div class="bg-blue-50 border-l-4 border-blue-400 text-blue-800 p-3 rounded-lg text-sm font-medium">
            <span class="font-bold">Notas:</span> {{ $cliente->notes }}
        </div>
    @endif

    <!-- PESTAÑAS -->
    <div class="flex gap-2 border-b border-gray-200">
        <button @click="tab = 'cxc'" :class="tab === 'cxc' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2 text-sm font-bold border-b-2 transition">
            Cuenta por Cobrar ({{ $pendientes->count() }})
        </button>
        <button @click="tab = 'facturas'" :class="tab === 'facturas' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2 text-sm font-bold border-b-2 transition">
            Facturas
        </button>
        <button @click="tab = 'citas'" :class="tab === 'citas' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-4 py-2 text-sm font-bold border-b-2 transition">
            Historial de Citas
        </button>
    </div>

    <!-- TAB: CxC -->
    <div x-show="tab === 'cxc'" class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Factura</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Emitida</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Vence</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Monto</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Cobrar</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($pendientes as $venta)
                    @php
                        $vence = $venta->created_at->copy()->addDays($diasCredito);
                        $vencida = $vence->isPast();
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-sm font-bold text-gray-900">#{{ $venta->id }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $venta->created_at->format('d/m/Y') }}</td>
                        <td class="px-6 py-4 text-sm {{ $vencida ? 'text-red-600 font-bold' : 'text-gray-600' }}">
                            {{ $vence->format('d/m/Y') }}
                            @if ($vencida)
                                <span class="ml-1 px-1.5 py-0.5 text-[10px] font-black rounded bg-red-100 text-red-700">VENCIDA</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-gray-900">C$ {{ number_format($venta->total, 2) }}</td>
                        <td class="px-6 py-4 text-center">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">Por cobrar</span>
                        </td>
                        <td class="px-6 py-4 text-right text-sm">
                            <a href="{{ url('/pos?cobro_id=' . $venta->id) }}" class="text-emerald-600 hover:text-emerald-900 font-bold">Ir a cobrar &rarr;</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">
                            El cliente no tiene facturas pendientes de cobro. Cuenta al día.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- TAB: Facturas -->
    <div x-show="tab === 'facturas'" style="display: none;" class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Factura</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Método</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Ticket</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($ventas as $venta)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-sm font-bold text-gray-900">#{{ $venta->id }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $venta->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-6 py-4 text-center">
                            @switch($venta->status)
                                @case('pagada')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Pagada</span>
                                    @break
                                @case('emitida')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800">Por cobrar</span>
                                    @break
                                @case('borrador')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Cotización</span>
                                    @break
                                @case('anulada')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Anulada</span>
                                    @break
                            @endswitch
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-600">{{ ucfirst($venta->payment_method) }}</td>
                        <td class="px-6 py-4 text-right text-sm font-bold text-gray-900">C$ {{ number_format($venta->total, 2) }}</td>
                        <td class="px-6 py-4 text-right text-sm">
                            <a href="{{ url('/ventas/' . $venta->id . '/ticket') }}" target="_blank" class="text-blue-600 hover:text-blue-900 font-semibold">Ver</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">El cliente aún no tiene facturas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- TAB: Citas -->
    <div x-show="tab === 'citas'" style="display: none;" class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha y Hora</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Servicio</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Estilistas</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Notas</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($citas as $cita)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-sm text-gray-700 font-semibold">{{ $cita->appointment_date->format('d/m/Y H:i') }}</td>
                        <td class="px-6 py-4 text-sm text-gray-900">{{ $cita->servicio?->name ?? 'N/A' }}</td>
                        <td class="px-6 py-4 text-xs text-gray-600">{{ $cita->estilistas->pluck('name')->implode(', ') ?: 'Sin asignar' }}</td>
                        <td class="px-6 py-4 text-center">
                            @switch($cita->status)
                                @case('pendiente')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Pendiente</span>
                                    @break
                                @case('completada')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Completada</span>
                                    @break
                                @case('cancelada')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Cancelada</span>
                                    @break
                                @case('no_asistio')
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">No asistió</span>
                                    @break
                            @endswitch
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-500">{{ $cita->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 text-sm">El cliente no tiene citas registradas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
