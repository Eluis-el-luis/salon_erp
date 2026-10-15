@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <div class="card p-6">
        <h2 class="page-title">Kárdex Inmutable (Stock Ledger)</h2>
        <p class="page-subtitle">Libro mayor del inventario: cada entrada/salida es un registro auditable con saldo corrido valorado a costo promedio móvil. El inventario no se edita.</p>
    </div>

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
            @if($articulo)
                <a href="{{ route('inventario.kardex.excel', ['item_id' => $articulo->id]) }}" class="btn btn-secondary">Excel</a>
                <a href="{{ route('inventario.kardex.pdf', ['item_id' => $articulo->id]) }}" class="btn btn-secondary">PDF</a>
            @endif
        </form>
    </div>

    @if($articulo)
        @if($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
                <p class="text-sm text-red-700 font-bold">{{ $errors->first() }}</p>
            </div>
        @endif
        @if(session('success'))
            <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-r">
                <p class="text-sm text-emerald-700 font-bold">{{ session('success') }}</p>
            </div>
        @endif

        <!-- Estado de apertura -->
        @if($requiereApertura)
            <div class="bg-amber-50 border border-amber-300 rounded-xl p-5">
                <h3 class="font-bold text-amber-800 mb-1">Este producto tiene stock físico previo al Kárdex corporativo</h3>
                <p class="text-sm text-amber-700 mb-3">Registra el saldo de apertura para fijar la posición inicial en el libro (registro real y auditable; los movimientos anteriores no se reconstruyen).</p>
                <form method="POST" action="{{ route('inventario.kardex.apertura') }}">
                    @csrf
                    <input type="hidden" name="item_id" value="{{ $articulo->id }}">
                    <button type="submit" class="btn btn-primary">Registrar saldo de apertura</button>
                </form>
            </div>
        @endif

        <!-- Resumen del producto -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
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
                <p class="text-xs font-bold text-gray-500 uppercase">Valor del Kárdex</p>
                <p class="text-2xl font-black text-purple-600">C$ {{ number_format($entradas->last()->saldo_valor ?? 0, 2) }}</p>
                <p class="text-xs text-gray-400 mt-1">Saldo valorado del libro</p>
            </div>
            <div class="card p-5">
                @php $costoMl = $resumenVolumen['costo_promedio_ml'] ?? 0; @endphp
                <p class="text-xs font-bold text-gray-500 uppercase">Costo Promedio / {{ $articulo->unit_measure ?? 'ml' }}</p>
                <p class="text-2xl font-black text-purple-600">C$ {{ number_format($costoMl, 6) }}</p>
            </div>
        </div>

        @if($resumenVolumen)
            <!-- Resumen por Volumen -->
            <div class="card p-5 bg-gradient-to-r from-purple-50 to-emerald-50 border border-purple-200">
                <h3 class="text-lg font-bold text-gray-900 mb-3">Resumen por Volumen (FIFO)</h3>
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
                        <p class="text-xs font-bold text-purple-600 uppercase">Volumen Total ({{ $articulo->unit_measure ?? 'ml' }})</p>
                        <p class="font-bold text-purple-800">{{ number_format($resumenVolumen['volumen_total_ml'], 2) }}</p>
                    </div>
                    <div class="bg-white p-3 rounded-lg border border-purple-100">
                        <p class="text-xs font-bold text-purple-600 uppercase">Costo Promedio / {{ $articulo->unit_measure ?? 'ml' }}</p>
                        <p class="font-bold text-purple-800">C$ {{ number_format($resumenVolumen['costo_promedio_ml'], 6) }}</p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Lotes abiertos y caducidad -->
        @if($lotes->count() > 0)
            <div class="card overflow-hidden">
                <div class="card-header bg-gray-50">
                    <h3 class="text-lg font-bold text-gray-900">Lotes Abiertos (FIFO) y Caducidad</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-white">
                            <tr>
                                <th class="px-6 py-3 text-left font-bold uppercase text-xs text-gray-500">Lote</th>
                                <th class="px-6 py-3 text-left font-bold uppercase text-xs text-gray-500">Entrada</th>
                                <th class="px-6 py-3 text-left font-bold uppercase text-xs text-gray-500">Vence</th>
                                <th class="px-6 py-3 text-right font-bold uppercase text-xs text-gray-500">Disponible</th>
                                <th class="px-6 py-3 text-right font-bold uppercase text-xs text-gray-500">Costo Unit.</th>
                                <th class="px-6 py-3 text-left font-bold uppercase text-xs text-gray-500">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach($lotes as $lote)
                                @php
                                    $dias = $lote->fecha_vencimiento ? now()->diffInDays($lote->fecha_vencimiento, false) : null;
                                    $vencido = $dias !== null && $dias < 0;
                                    $porVencer = $dias !== null && $dias >= 0 && $dias <= 30;
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 font-bold text-gray-800">{{ $lote->lote }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ \Carbon\Carbon::parse($lote->fecha_entrada)->format('d/m/Y') }}</td>
                                    <td class="px-6 py-3 {{ $vencido ? 'text-red-600 font-bold' : 'text-gray-600' }}">
                                        {{ $lote->fecha_vencimiento?->format('d/m/Y') ?? '—' }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-gray-800">{{ $lote->cantidad_disponible }}</td>
                                    <td class="px-6 py-3 text-right text-gray-600">C$ {{ number_format($lote->costo_unitario, 4) }}</td>
                                    <td class="px-6 py-3">
                                        @if($vencido)
                                            <span class="px-2 py-1 rounded text-xs font-bold bg-red-100 text-red-800">VENCIDO</span>
                                        @elseif($porVencer)
                                            <span class="px-2 py-1 rounded text-xs font-bold bg-amber-100 text-amber-800">Por vencer ({{ $dias }} días)</span>
                                        @else
                                            <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Vigente</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <!-- Movimientos del ledger -->
        <div class="card overflow-hidden">
            <div class="card-header bg-gray-50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">Movimientos — {{ $articulo->producto }}</h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-gray-500">Saldo: {{ number_format($entradas->last()->saldo_cantidad ?? 0, 2) }} {{ $articulo->unit_measure ?? 'uds' }}</span>
                    @if($articulo->is_fractionable)
                        <span class="text-xs font-bold text-purple-600 ml-4">Vol: {{ number_format($entradas->last()->saldo_volumen ?? 0, 2) }} {{ $articulo->unit_measure ?? 'ml' }}</span>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold uppercase text-xs text-gray-500">Fecha</th>
                            <th class="px-4 py-3 text-left font-bold uppercase text-xs text-gray-500">Concepto</th>
                            <th class="px-4 py-3 text-center font-bold uppercase text-xs text-gray-500">Tipo</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Cant. Und.</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Volumen</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Costo Unit.</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Valor (C$)</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Saldo Und.</th>
                            <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Saldo Valor</th>
                            <th class="px-4 py-3 text-left font-bold uppercase text-xs text-gray-500">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($entradas as $entrada)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ $entrada->fecha?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-gray-800 font-medium">
                                    @php
                                        $concepto = match($entrada->origen) {
                                            \App\Models\StockLedger::ORIGEN_COMPRA => 'Compra (DOC ' . $entrada->referencia_id . ')',
                                            \App\Models\StockLedger::ORIGEN_VENTA => 'Venta #' . $entrada->referencia_id,
                                            \App\Models\StockLedger::ORIGEN_MERMA => 'Merma #' . $entrada->referencia_id,
                                            \App\Models\StockLedger::ORIGEN_REVERSION_VENTA => 'Reversión de venta #' . $entrada->referencia_id,
                                            \App\Models\StockLedger::ORIGEN_ANULACION_COMPRA => 'Anulación de compra (DOC ' . $entrada->referencia_id . ')',
                                            \App\Models\StockLedger::ORIGEN_APERTURA => 'Apertura de kárdex',
                                            default => ucfirst(str_replace('_', ' ', $entrada->origen)),
                                        };
                                    @endphp
                                    {{ $concepto }}
                                    @if($entrada->notas)
                                        <span class="text-xs text-gray-400 block">{{ $entrada->notas }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if($entrada->movimiento == 'entrada')
                                        <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Entrada</span>
                                    @elseif($entrada->movimiento == 'salida')
                                        <span class="px-2 py-1 rounded text-xs font-bold bg-red-100 text-red-800">Salida</span>
                                    @else
                                        <span class="px-2 py-1 rounded text-xs font-bold bg-blue-100 text-blue-800">Ajuste</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right {{ $entrada->cantidad < 0 ? 'text-red-600' : 'text-emerald-700' }} font-bold">
                                    {{ number_format($entrada->cantidad, 2) }}
                                </td>
                                <td class="px-4 py-3 text-right {{ ($entrada->volumen ?? 0) < 0 ? 'text-red-600' : 'text-gray-700' }}">
                                    {{ $entrada->volumen !== null ? number_format($entrada->volumen, 2) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600">C$ {{ number_format($entrada->costo_unitario, 4) }}</td>
                                <td class="px-4 py-3 text-right font-bold {{ $entrada->valor < 0 ? 'text-red-600' : 'text-emerald-700' }}">
                                    {{ number_format($entrada->valor, 2) }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ number_format($entrada->saldo_cantidad, 2) }}</td>
                                <td class="px-4 py-3 text-right font-bold text-gray-900">C$ {{ number_format($entrada->saldo_valor, 2) }}</td>
                                <td class="px-4 py-3 text-gray-500 text-xs">{{ $entrada->usuario?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-10 text-center text-gray-400">
                                    Sin movimientos registrados en el kárdex para este producto.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
