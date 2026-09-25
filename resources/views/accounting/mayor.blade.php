@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="mayorApp()">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Libro Mayor</h1>
            <p class="page-subtitle">Saldos y movimientos por cuenta contable con detalle por centro de costo</p>
        </div>
        <a href="{{ url('/contabilidad') }}" class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
            ← Volver al Libro Diario
        </a>
    </div>

    <!-- Filtros -->
    <div class="card p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="label">Cuenta Contable</label>
                <select name="cuenta_id" class="input" x-model="filtros.cuenta_id" @change="$watch('filtros.cuenta_id', v => window.location.href = '{{ url('/contabilidad/mayor') }}?cuenta_id=' + v + '&centro_costo_id=' + filtros.centro_costo_id + '&fecha_desde=' + filtros.fecha_desde + '&fecha_hasta=' + filtros.fecha_hasta + '&buscar=' + filtros.buscar)">
                    <option value="">Todas las cuentas</option>
                    @foreach($cuentasDisponibles as $cuenta)
                        <option value="{{ $cuenta->id }}" {{ $filtros['cuenta_id'] == $cuenta->id ? 'selected' : '' }}>
                            {{ $cuenta->codigo }} - {{ $cuenta->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="w-48">
                <label class="label">Centro de Costo</label>
                <select name="centro_costo_id" class="input" x-model="filtros.centro_costo_id" @change="$watch('filtros.centro_costo_id', v => window.location.href = '{{ url('/contabilidad/mayor') }}?cuenta_id=' + filtros.cuenta_id + '&centro_costo_id=' + v + '&fecha_desde=' + filtros.fecha_desde + '&fecha_hasta=' + filtros.fecha_hasta + '&buscar=' + filtros.buscar)">
                    <option value="">Todos los centros</option>
                    @foreach($centrosCosto as $cc)
                        <option value="{{ $cc->id }}" {{ $filtros['centro_costo_id'] == $cc->id ? 'selected' : '' }}>
                            {{ $cc->codigo }} - {{ $cc->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="w-40">
                <label class="label">Desde</label>
                <input type="date" name="fecha_desde" value="{{ $filtros['fecha_desde'] }}" class="input" @change="window.location.href = '{{ url('/contabilidad/mayor') }}?cuenta_id=' + filtros.cuenta_id + '&centro_costo_id=' + filtros.centro_costo_id + '&fecha_desde=' + this.value + '&fecha_hasta=' + filtros.fecha_hasta + '&buscar=' + filtros.buscar">
            </div>

            <div class="w-40">
                <label class="label">Hasta</label>
                <input type="date" name="fecha_hasta" value="{{ $filtros['fecha_hasta'] }}" class="input" @change="window.location.href = '{{ url('/contabilidad/mayor') }}?cuenta_id=' + filtros.cuenta_id + '&centro_costo_id=' + filtros.centro_costo_id + '&fecha_desde=' + filtros.fecha_desde + '&fecha_hasta=' + this.value + '&buscar=' + filtros.buscar">
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="label">Buscar</label>
                <input type="text" name="buscar" value="{{ $filtros['buscar'] }}" placeholder="Buscar en concepto..." class="input" @change="window.location.href = '{{ url('/contabilidad/mayor') }}?cuenta_id=' + filtros.cuenta_id + '&centro_costo_id=' + filtros.centro_costo_id + '&fecha_desde=' + filtros.fecha_desde + '&fecha_hasta=' + filtros.fecha_hasta + '&buscar=' + this.value">
            </div>
        </form>
    </div>

    @if($cuenta)
        <!-- Resumen de la Cuenta Seleccionada -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="card p-5 border-l-4 border-l-emerald-600">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Debe</p>
                <p class="text-2xl font-black text-red-600">C$ {{ number_format($totales['debe'], 2) }}</p>
            </div>
            <div class="card p-5 border-l-4 border-l-emerald-600">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Haber</p>
                <p class="text-2xl font-black text-emerald-600">C$ {{ number_format($totales['haber'], 2) }}</p>
            </div>
            <div class="card p-5 border-l-4 border-l-blue-600">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Saldo Final</p>
                <p class="text-2xl font-black {{ $totales['saldo'] >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                    C$ {{ number_format($totales['saldo'], 2) }}
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ $totales['saldo'] >= 0 ? 'Deudor' : 'Acreedor' }}</p>
            </div>
            <div class="card p-5 border-l-4 border-l-purple-600">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Centro de Costo</p>
                <p class="text-lg font-black text-purple-600">{{ $filtros['centro_costo_id'] ? ($centrosCosto->firstWhere('id', $filtros['centro_costo_id'])?->nombre ?? 'Filtrado') : 'Todos' }}</p>
            </div>
        </div>

        <!-- Movimientos agrupados por Centro de Costo -->
        @if(isset($agrupadosPorCentro) && $agrupadosPorCentro->count() > 0)
            <div class="space-y-6">
                @foreach($agrupadosPorCentro as $grupo)
                    <div class="card overflow-hidden">
                        <div class="card-header bg-gray-50 border-b border-gray-200 px-6 py-3 flex justify-between items-center">
                            <div class="flex items-center gap-3">
                                <h3 class="text-lg font-bold text-gray-800">Centro de Costo: {{ $grupo['centro_costo']?->nombre ?? 'Sin asignar' }}</h3>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 rounded-full font-bold">
                                    Debe: C$ {{ number_format($grupo['totales']['debe'], 2) }}
                                </span>
                                <span class="px-3 py-1 bg-red-50 text-red-700 rounded-full font-bold">
                                    Haber: C$ {{ number_format($grupo['totales']['haber'], 2) }}
                                </span>
                                <span class="px-3 py-1 {{ $grupo['totales']['saldo'] >= 0 ? 'bg-blue-50 text-blue-700' : 'bg-red-50 text-red-700' }} rounded-full font-bold">
                                    Saldo: C$ {{ number_format($grupo['totales']['saldo'], 2) }}
                                </span>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Asiento</th>
                                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Concepto</th>
                                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Debe</th>
                                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Haber</th>
                                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo Acumulado</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @php $saldoAcumulado = 0; @endphp
                                    @foreach($grupo['detalles'] as $mov)
                                        @php
                                            $saldoAcumulado += ($mov['debe'] - $mov['haber']);
                                        @endphp
                                        <tr class="hover:bg-gray-50 transition" x-data="{ expandido: false }">
                                            <td class="px-6 py-3 whitespace-nowrap text-gray-600 font-medium">
                                                {{ \Carbon\Carbon::parse($mov['fecha'])->format('d/m/Y') }}
                                            </td>
                                            <td class="px-6 py-3 text-gray-900 font-mono font-bold">
                                                <a href="{{ url('/asientos/' . $mov['asiento_id']) }}" class="text-emerald-600 hover:underline font-mono">{{ $mov['asiento'] }}</a>
                                            </td>
                                            <td class="px-6 py-3 text-gray-700 max-w-xs truncate">{{ $mov['concepto'] }}</td>
                                            <td class="px-6 py-3 text-right font-bold text-red-600">{{ $mov['debe'] > 0 ? 'C$ ' . number_format($mov['debe'], 2) : '—' }}</td>
                                            <td class="px-6 py-3 text-right font-bold text-emerald-600">{{ $mov['haber'] > 0 ? 'C$ ' . number_format($mov['haber'], 2) : '—' }}</td>
                                            <td class="px-6 py-3 text-right font-bold {{ $mov['debe'] - $mov['haber'] >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                                C$ {{ number_format($mov['debe'] - $mov['haber'], 2) }}
                                            </td>
                                        </tr>
                                        @php $saldoAcumulado = $saldoAcumulado; @endphp
                                    @endforeach
                                    <!-- Total por centro -->
                                    <tr class="bg-gray-50 font-bold">
                                        <td colspan="3" class="px-6 py-3 text-right">TOTAL CENTRO</td>
                                        <td class="text-right font-bold text-red-600">C$ {{ number_format($grupo['totales']['debe'], 2) }}</td>
                                        <td class="px-6 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($grupo['totales']['haber'], 2) }}</td>
                                        <td class="px-6 py-3 text-right font-bold {{ $grupo['totales']['saldo'] >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                            C$ {{ number_format($grupo['totales']['saldo'], 2) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <!-- Vista plana sin agrupar por centro -->
            @if($movimientos->count() > 0)
                <div class="card overflow-hidden">
                    <div class="card-header bg-gray-50 border-b border-gray-200 px-6 py-3">
                        <h3 class="text-lg font-bold text-gray-800">Movimientos de la Cuenta</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Asiento</th>
                                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Concepto</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Centro Costo</th>
                                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Debe</th>
                                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Haber</th>
                                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo Acum.</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @php $saldoAcumulado = 0; @endphp
                                @foreach($movimientos as $mov)
                                    @php
                                        $saldoAcumulado += ($mov['debe'] - $mov['haber']);
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-3 whitespace-nowrap text-gray-600 font-medium">
                                            {{ \Carbon\Carbon::parse($mov['fecha'])->format('d/m/Y') }}
                                        </td>
                                        <td class="px-6 py-3">
                                            <a href="{{ url('/asientos/' . $mov['asiento_id']) }}" class="text-emerald-600 hover:underline font-mono font-bold">
                                                {{ $mov['asiento'] }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-3 text-gray-700 max-w-xs truncate">{{ $mov['concepto'] }}</td>
                                        <td class="px-6 py-3 text-center text-gray-600">
                                            {{ $mov['centro_costo'] ?? '—' }}
                                        </td>
                                        <td class="px-6 py-3 text-right font-bold text-red-600">{{ $mov['debe'] > 0 ? 'C$ ' . number_format($mov['debe'], 2) : '—' }}</td>
                                        <td class="px-6 py-3 text-right font-bold text-emerald-600">{{ $mov['haber'] > 0 ? 'C$ ' . number_format($mov['haber'], 2) : '—' }}</td>
                                        <td class="px-6 py-3 text-right font-bold {{ $mov['debe'] - $mov['haber'] >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                            C$ {{ number_format($mov['debe'] - $mov['haber'], 2) }}
                                        </td>
                                    </tr>
                                    @php $saldoAcumulado = $saldoAcumulado; @endphp
                                @endforeach
                                <!-- Total General -->
                                <tr class="bg-gray-50 font-bold">
                                    <td colspan="3" class="px-6 py-3 text-right font-bold text-gray-900">TOTALES</td>
                                    <td class="px-6 py-3 text-right font-bold text-red-600">C$ {{ number_format($totales['debe'], 2) }}</td>
                                    <td class="px-6 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($totales['haber'], 2) }}</td>
                                    <td class="px-6 py-3 text-right font-bold {{ $totales['saldo'] >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                        C$ {{ number_format($totales['saldo'], 2) }} ({{ $totales['saldo'] >= 0 ? 'Deudor' : 'Acreedor' }})
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-8 text-center">
                        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 001.414 0l4.414 4.414a1 1 0 001.414 0l3.536-3.536a1 1 0 000-1.414z"></path></svg>
                        <p class="text-lg font-medium text-gray-500">Sin movimientos en el rango seleccionado</p>
                    </div>
                @endif
            @endif
        @else
            <!-- Sin cuenta seleccionada -->
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-12 text-center">
                <svg class="w-20 h-20 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V17a2 2 0 01-2 2h-2m-4-11v5m0 0H7a2 2 0 01-2-2V3a2 2 0 012-2h2"></path></svg>
                <h3 class="text-lg font-semibold text-gray-700 mb-2">Selecciona una cuenta contable</h3>
                <p class="text-gray-500">Elige una cuenta del filtro superior para ver su kárdex detallado con desglose por centro de costo.</p>
            </div>
        @endif
    </div>
@endsection