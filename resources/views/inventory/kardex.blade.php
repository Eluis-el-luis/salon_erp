@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <div class="card p-6">
        <h2 class="page-title">Kárdex Físico-Valorado AVANZADO</h2>
        <p class="page-subtitle">Estado de cuenta del inventario con FIFO por volumen, costo promedio móvil y trazabilidad completa.</    </div>

    <!-- Selector de producto -->
    <div class="card p-6">
        <form method="GET" action="{{ url('/inventario/kardex') }}" class="flex flex-col sm:flex-row items-end gap-3">
            <div class="flex-1">
                <label class="label" for="item_id">Producto / Insumo</label>
                <select id="item_id" name="item_id" required onchange="this.form.submit()" class="input">
                    <option value="">Selecciona un producto...</option>
                    @foreach($articulos as $a)
                        <option value="{{ $a->id }}" {{ $articulo && $articulo->id == $a->id ? 'selected' : '' }}>
                            {{ $a->producto }} (Stock: {{ $a->existencia_actual }} {{ $a->unit_measure ?? 'uds' }})
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Consultar</button>
        </form>
    </div>

    @if($articulo)
        <!-- Resumen del producto -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div class="card p-5">
                <p class="text-xs font-bold text-gray-500 uppercase">Existencia</p>
                <p class="text-2xl font-black text-gray-900">{{ $articulo->existencia_actual }} <span class="text-sm text-gray-400">{{ $articulo->unit_measure ?? 'uds' }}</span></p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-bold text-gray-500 uppercase">Costo Promedio / Und</p>
                <p class="text-2xl font-black text-emerald-600">C$ {{ number_format($articulo->costo_promedio, 4) }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-bold text-gray-500 uppercase">Volumen Total</p>
                <p class="text-2xl font-black text-gray-900">{{ number_format(($articulo->existencia_actual * ($articulo->total_volume ?? 0)) + $articulo->current_volume, 2) }} {{ $articulo->unit_measure ?? 'ml' }}</p>
            </div>
            <div class="card p-5">
                @php $costoMl = $resumenVolumen['costo_promedio_ml'] ?? 0; @endphp
                <p class="text-xs font-bold text-gray-500 uppercase">Costo Promedio / ml</p>
                <p class="text-2xl font-black text-purple-600">C$ {{ number_format($costoMl, 6) }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs font-bold text-gray-500 uppercase">Valor Stock Total</p>
                <p class="text-2xl font-black text-purple-600">C$ {{ number_format(($articulo->existencia_actual * $articulo->costo_promedio) + ($articulo->current_volume * ($resumenVolumen['costo_promedio_ml'] ?? 0)), 2) }}</p>
            </div>
        </div>

        @if($resumenVolumen)
            <!-- Resumen por Volumen -->
            <div class="card p-5 bg-gradient-to-r from-purple-50 to-emerald-50 border border-purple-200">
                <h3 class="text-lg font-bold text-gray-900 mb-3 flex items-center gap-2">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                    Resumen por Volumen (FIFO)
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div class="bg-white p-3 rounded-lg border border-purple-100">
                        <p class="text-xs font-bold text-purple-600 uppercase">Lotes Activos</p>
                        <p class="font-bold text-purple-800">{{ $resumenVolumen['lotes_activos'] }}</p>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-purple-100">
                        <p class="text-xs font-bold text-purple-600 uppercase">Unidades Totales</p>
                        <p class="font-bold text-purple-800">{{ $resumenVolumen['existencia_unidades'] }}</p>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-purple-100">
                        <p class="text-xs font-bold text-purple-600 uppercase">Volumen Total (ml)</p>
                        <p class="font-bold text-purple-800">{{ number_format($resumenVolumen['volumen_total_ml'], 2) }}</p>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-purple-100">
                        <p class="text-xs font-bold text-purple-600 uppercase">Costo Promedio / ml</p>
                        <p class="font-bold text-purple-800">C$ {{ number_format($resumenVolumen['costo_promedio_ml'], 6) }}</p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Movimientos -->
        <div class="card overflow-hidden">
            <div class="card-header bg-gray-50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">Movimientos — {{ $articulo->producto }}</h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-gray-500">Saldo: {{ number_format($saldo ?? 0, 2) }} {{ $articulo->unit_measure ?? 'uds' }}</span>
                    @if($resumenVolumen)
                        <span class="text-xs font-bold text-purple-600 ml-4">Vol: {{ number_format($resumenVolumen['volumen_total_ml'] ?? 0, 2) }} ml</span>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Concepto</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Tipo</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Cant. Und.</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Volumen (ml)</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Costo Unit.</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Valor</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo Und.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @php $saldo = 0; $saldoVolumen = 0; @endphp
                        @forelse($movimientos as $mv)
                            @php
                                $saldo += $mv['cantidad'];
                                $saldoVolumen += ($mv['volumen_ml'] ?? 0);
                                $valor = $mv['cantidad'] * $mv['costo'];
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ \Carbon\Carbon::parse($mv['fecha'])->format('d/m/Y') }}</td>
                                <td class="px-6 py-3 text-gray-800 font-medium">{{ $mv['concepto'] }}</td>
                                <td class="px-6 py-3 text-center">
                                    @if($mv['tipo'] == 'entrada')
                                        <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Entrada</span>
                                    @else
                                        <span class="px-2 py-1 rounded text-xs font-bold bg-red-100 text-red-700">Salida</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right font-bold {{ $mv['cantidad'] > 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ $mv['cantidad'] > 0 ? '+' : '' }}{{ number_format($mv['cantidad'], 2) }}
                                </td>
                                <td class="px-6 py-3 text-right text-gray-600">{{ number_format($mv['volumen_ml'] ?? 0, 2) }}</td>
                                <td class="px-6 py-3 text-right text-gray-600">C$ {{ number_format($mv['costo'], 4) }}</td>
                                <td class="px-6 py-3 text-right text-gray-700 font-semibold">C$ {{ number_format($valor, 2) }}</td>
                                <td class="px-6 py-3 text-right font-black text-gray-900">{{ number_format($saldo, 2) }} ({{ number_format($saldoVolumen, 2) }} ml)</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500 font-medium">
                                    No hay movimientos registrados para este producto.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card p-10 text-center text-gray-400 text-sm">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
            <p class="text-lg font-medium">Selecciona un producto para ver su kárdex físico-valorado.</            <p class="text-xs text-gray-500 mt-1">Incluye tracking por unidades y volumen (ml/oz) con FIFO y costo promedio móvil.</p>
        </div>
    @endif

</div>
@endsection