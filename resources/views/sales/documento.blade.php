@extends('layouts.app')

@section('title', 'Detalle de Venta #' . $venta->id)

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    {{-- Header con estado --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Detalle de Venta #{{ str_pad($venta->id, 5, '0', STR_PAD_LEFT) }}</h2>
            <p class="text-gray-500 text-sm mt-1">
                {{ $venta->created_at->format('d/m/Y H:i') }} | 
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                    @if($venta->status === 'pagada') bg-emerald-100 text-emerald-800
                    @elseif($venta->status === 'emitida') bg-amber-100 text-amber-800
                    @elseif($venta->status === 'borrador') bg-gray-100 text-gray-600
                    @elseif($venta->status === 'anulada') bg-red-100 text-red-800
                    @elseif($venta->status === 'pendiente_aprobacion') bg-amber-100 text-amber-800
                    @endif">
                    {{ ucfirst($venta->status) }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            @if($venta->status === 'pagada')
                <a href="{{ url('/ventas/' . $venta->id . '/ticket') }}" target="_blank" class="btn bg-emerald-600 hover:bg-emerald-700 text-white">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                    Ver Ticket
                </a>
            @endif
            <a href="{{ url('/mis-aprobaciones') }}" class="btn btn-secondary">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Volver
            </a>
        </div>
    </div>

    {{-- Alertas por estado --}}
    @if($venta->status === 'pendiente_aprobacion')
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-6 h-6 text-amber-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
            <div>
                <p class="font-bold text-amber-800">Venta pendiente de aprobación</p>
                <p class="text-sm text-amber-700">Esta venta no afecta inventario ni contabilidad hasta que el gerente la apruebe.</p>
            </div>
        </div>
    @elseif($venta->status === 'anulada')
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-6 h-6 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>
            <div>
                <p class="font-bold text-red-800">Venta anulada</p>
                <p class="text-sm text-red-700">{{ $venta->motivo_anulacion ?? 'Sin motivo especificado' }}</p>
                @if($venta->anulada_at)
                    <p class="text-xs text-red-600 mt-1">Anulada el {{ $venta->anulada_at->format('d/m/Y H:i') }} por {{ $venta->anuladaPor->name ?? '—' }}</p>
                @endif
            </div>
        </div>
    @elseif($venta->status === 'emitida')
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-6 h-6 text-amber-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 110 2H9a1 1 0 01-1-1V9z" clip-rule="evenodd"></path></svg>
            <div>
                <p class="font-bold text-amber-800">Factura por cobrar (CxC)</p>
                <p class="text-sm text-amber-700">Pendiente de pago. Use <a href="{{ url('/ventas/' . $venta->id . '/cobrar') }}" class="underline">Registrar Cobro</a> cuando el cliente pague.</p>
            </div>
        </div>
    @endif

    {{-- Información general --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="card p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Información General</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-600">Cliente</dt>
                    <dd class="font-semibold text-gray-900">{{ $venta->cliente?->name ?? 'Cliente General' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Cajero</dt>
                    <dd class="font-semibold text-gray-900">{{ $venta->cajero->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Fecha</dt>
                    <dd class="font-semibold text-gray-900">{{ $venta->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                @if($venta->status === 'pagada' && $venta->aprobada_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Aprobada por</dt>
                        <dd class="font-semibold text-gray-900">{{ $venta->aprobadaPor->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Fecha aprobación</dt>
                        <dd class="font-semibold text-gray-900">{{ $venta->aprobada_at->format('d/m/Y H:i') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="card p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Totales</h3>
            <dl class="space-y-3">
                <div class="flex justify-between">
                    <dt class="text-gray-600">Subtotal</dt>
                    <dd class="font-semibold text-gray-900">C$ {{ number_format($venta->subtotal, 2) }}</dd>
                </div>
                @if($venta->discount > 0)
                    <div class="flex justify-between text-emerald-700">
                        <dt class="text-gray-600">Descuento</dt>
                        <dd class="font-semibold">- C$ {{ number_format($venta->discount, 2) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between border-t pt-3 text-xl">
                    <dt class="font-black text-gray-900">TOTAL</dt>
                    <dd class="font-black text-emerald-700">C$ {{ number_format($venta->total, 2) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Detalle de items --}}
    <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Items ({{ $venta->detalles->count() }})</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Item</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Tipo</th>
                        <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Cant.</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Precio Unit.</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach($venta->detalles as $detalle)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900">{{ $detalle->servicio->name ?? $detalle->articulo->producto ?? 'Item' }}</div>
                                @if($detalle->variante)
                                    <div class="text-xs text-amber-700 font-bold">{{ $detalle->variante->name }}</div>
                                @endif
                                @if($detalle->estilista)
                                    <div class="text-xs text-gray-500">Estilista: {{ $detalle->estilista->name }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
                                    @if($detalle->service_id) bg-emerald-100 text-emerald-800
                                    @else bg-blue-100 text-blue-800
                                    @endif">
                                    @if($detalle->service_id) Servicio @else Producto @endif
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center font-semibold text-gray-900">{{ $detalle->quantity }}</td>
                            <td class="px-6 py-4 text-right font-semibold text-gray-900">C$ {{ number_format($detalle->unit_price, 2) }}</td>
                            <td class="px-6 py-4 text-right font-bold text-gray-900">C$ {{ number_format($detalle->unit_price * $detalle->quantity, 2) }}</td>
                        </tr>
                        
                        {{-- Consumos reales (BOM) --}}
                        @if($detalle->consumos->count() > 0)
                            <tr class="bg-blue-50/50">
                                <td colspan="5" class="px-6 py-3">
                                    <div class="flex items-center gap-2 text-xs text-blue-700 font-bold mb-2">Consumo real de insumos (BOM):</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($detalle->consumos as $consumo)
                                            <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs">
                                                {{ $consumo->articulo->producto ?? 'Insumo' }}: {{ $consumo->cantidad }} {{ $consumo->articulo->unit_measure ?? 'ml' }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pagos (si está pagada) --}}
    @if($venta->status === 'pagada' && $venta->pagos->count() > 0)
        <div class="card overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Libro de Pagos ({{ $venta->pagos->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Método</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Moneda</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Monto</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Tasa</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Valor NIO</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Tipo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($venta->pagos as $pago)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold
                                        @if($pago->tipo === 'efectivo') bg-emerald-100 text-emerald-800
                                        @elseif($pago->tipo === 'bac') bg-blue-100 text-blue-800
                                        @elseif($pago->tipo === 'lafise') bg-indigo-100 text-indigo-800
                                        @else bg-gray-100 text-gray-800 @endif">
                                        {{ ucfirst($pago->tipo) }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 font-semibold">
                                    @if($pago->moneda === 'usd') $ {{ number_format($pago->monto, 2) }} @else C$ {{ number_format($pago->monto, 2) }} @endif
                                </td>
                                <td class="px-6 py-3 text-right font-semibold">
                                    @if($pago->moneda === 'usd') $ {{ number_format($pago->monto, 2) }} @else C$ {{ number_format($pago->monto, 2) }} @endif
                                </td>
                                <td class="px-6 py-3 text-right text-sm text-gray-500">
                                    @if($pago->moneda === 'usd' && $pago->tasa) {{ number_format($pago->tasa, 2) }} @else — @endif
                                </td>
                                <td class="px-6 py-3 text-right font-bold text-gray-900">C$ {{ number_format($pago->valor_nio ?? $pago->monto, 2) }}</td>
                                <td class="px-6 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold
                                        @if($pago->tipo === 'vuelto') bg-amber-100 text-amber-800
                                        @elseif($pago->tipo === 'cambio') bg-purple-100 text-purple-800
                                        @else bg-gray-100 text-gray-800 @endif">
                                        {{ ucfirst($pago->tipo) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Asientos contables vinculados --}}
    @if($venta->asientosContables->count() > 0)
        <div class="card overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Asientos Contables Vinculados ({{ $venta->asientosContables->count() }})</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Asiento</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Concepto</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Módulo</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Debe</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Haber</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($venta->asientosContables as $asiento)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 font-bold text-gray-900">{{ $asiento->numero }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $asiento->fecha->format('d/m/Y') }}</td>
                                <td class="px-6 py-3 text-gray-900">{{ $asiento->concepto }}</td>
                                <td class="px-6 py-3 text-sm text-gray-500">{{ $asiento->modulo_origen }}</td>
                                <td class="px-6 py-3 text-right font-semibold text-red-600">C$ {{ number_format($asiento->detalles->sum('debe'), 2) }}</td>
                                <td class="px-6 py-3 text-right font-semibold text-emerald-600">C$ {{ number_format($asiento->detalles->sum('haber'), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection