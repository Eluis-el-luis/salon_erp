@extends('layouts.app')

@section('content')
<div x-data="{ anularModal: false }" class="max-w-6xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <a href="{{ $factura->proveedor ? route('proveedores.show', $factura->proveedor->id) : url('/proveedores') }}" class="text-gray-400 hover:text-gray-600 transition" title="Volver al proveedor">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </a>
                <h2 class="page-title">
                    Compra {{ $factura->numero_documento ?? ('DOC-' . str_pad($factura->id, 5, '0', STR_PAD_LEFT)) }}
                </h2>
                @if($factura->estado === 'anulada')
                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-gray-200 text-gray-600">ANULADA</span>
                @else
                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800">REGISTRADA</span>
                @endif
            </div>
            <p class="page-subtitle ml-9">
                Proveedor: <a href="{{ route('proveedores.show', $factura->provider_id) }}" class="text-emerald-600 font-bold hover:underline">{{ $factura->proveedor->name ?? '—' }}</a>
                · Fecha: {{ $factura->fecha_factura->format('d/m/Y') }}
                · {{ $factura->condicion_pago === 'credito' ? 'A crédito' : 'Contado (' . ($factura->metodo_pago_contado ?? '—') . ')' }}
            </p>
        </div>
        @if($factura->estado !== 'anulada')
            <button @click="anularModal = true" class="btn btn-danger">Anular Compra</button>
        @endif
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- Métricas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Factura</p>
            <p class="text-2xl font-black text-gray-900">C$ {{ number_format($factura->total, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">IVA Crédito Fiscal</p>
            <p class="text-2xl font-black text-blue-600">C$ {{ number_format($factura->impuestos, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase">Descuento / Envío</p>
            <p class="text-2xl font-black text-gray-700">C$ {{ number_format($factura->descuento, 2) }} / C$ {{ number_format($factura->gastos_envio, 2) }}</p>
        </div>
        <div class="card p-5 {{ $factura->cuentaPorPagar && $factura->cuentaPorPagar->saldo_pendiente > 0 ? 'border-red-200 bg-red-50' : '' }}">
            <p class="text-xs font-bold text-gray-500 uppercase">Saldo CxP</p>
            <p class="text-2xl font-black {{ $factura->cuentaPorPagar && $factura->cuentaPorPagar->saldo_pendiente > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                C$ {{ number_format($factura->cuentaPorPagar->saldo_pendiente ?? 0, 2) }}
            </p>
            @if($factura->cuentaPorPagar)
                <p class="text-xs text-gray-400 mt-1">Vence {{ \Carbon\Carbon::parse($factura->cuentaPorPagar->fecha_vencimiento)->format('d/m/Y') }}</p>
            @endif
        </div>
    </div>

    <!-- Líneas -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Detalle de Artículos (costo landed)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-white">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs text-gray-500">Producto</th>
                        <th class="px-4 py-3 text-center font-bold uppercase text-xs text-gray-500">Cantidad</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Costo Comprado</th>
                        <th class="px-4 py-3 text-center font-bold uppercase text-xs text-gray-500">IVA %</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Costo Landed</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Valor Neto</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs text-gray-500">Total Línea</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs text-gray-500">Lote / Vence</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($factura->lineas as $linea)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $linea->descripcion }}</td>
                            <td class="px-4 py-3 text-center text-gray-700">{{ $linea->cantidad }}</td>
                            <td class="px-4 py-3 text-right text-gray-600">C$ {{ number_format($linea->costo_unitario, 4) }}</td>
                            <td class="px-4 py-3 text-center text-gray-600">{{ number_format($linea->impuesto_porcentaje, 2) }}%</td>
                            <td class="px-4 py-3 text-right font-bold text-emerald-700">C$ {{ number_format($linea->costo_unitario_final, 4) }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">C$ {{ number_format($linea->valor_neto, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900">C$ {{ number_format($linea->total_linea, 2) }}</td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $linea->lote ?? '—' }}
                                @if($linea->fecha_vencimiento)
                                    <span class="text-xs text-gray-400 block">vence {{ $linea->fecha_vencimiento->format('d/m/Y') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Sin líneas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="bg-gray-50 p-4 text-right space-y-1 text-sm border-t">
            <p class="text-gray-600">Subtotal: <strong>C$ {{ number_format($factura->subtotal, 2) }}</strong></p>
            @if($factura->descuento > 0)
                <p class="text-red-500">Descuento: <strong>- C$ {{ number_format($factura->descuento, 2) }}</strong></p>
            @endif
            @if($factura->gastos_envio > 0)
                <p class="text-gray-600">Envío (capitalizado al costo): <strong>+ C$ {{ number_format($factura->gastos_envio, 2) }}</strong></p>
            @endif
            <p class="text-blue-600">IVA Crédito Fiscal: <strong>C$ {{ number_format($factura->impuestos, 2) }}</strong></p>
            <p class="text-lg text-gray-900">TOTAL: <strong>C$ {{ number_format($factura->total, 2) }}</strong></p>
        </div>
    </div>

    <!-- Contabilidad -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase mb-2">Asiento Contable</p>
            @if($factura->asiento)
                <p class="font-bold text-gray-900">{{ $factura->asiento->numero_asiento }}</p>
                <p class="text-sm text-gray-600">{{ $factura->asiento->concepto }}</p>
                <a href="{{ url('/contabilidad/diario') }}" class="text-sm text-blue-600 hover:underline font-bold mt-2 inline-block">Ver en el Libro Diario →</a>
            @else
                <p class="text-sm text-gray-400">Sin asiento vinculado.</p>
            @endif
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase mb-2">Notas</p>
            <p class="text-sm text-gray-600">{{ $factura->notas ?? 'Sin notas.' }}</p>
        </div>
    </div>

    <!-- Modal de anulación (solo admin: la ruta está protegida) -->
    <div x-show="anularModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="anularModal = false">
            <h3 class="text-xl font-bold text-red-700 mb-2">Anular Factura de Compra</h3>
            <p class="text-sm text-gray-600 mb-4">
                Se revierten el inventario, los lotes, el kárdex y la CxP, y se genera el asiento espejo <strong>REINV-COM-{{ str_pad($factura->id, 4, '0', STR_PAD_LEFT) }}</strong>.
                La anulación se bloquea si la compra ya tuvo abonos o consumos de lote.
            </p>
            <form method="POST" action="{{ route('compras.anular', $factura->id) }}" class="space-y-4">
                @csrf
                <div>
                    <label class="label">Motivo (obligatorio) *</label>
                    <input type="text" name="motivo" required minlength="5" class="input" placeholder="Ej. Factura duplicada del proveedor">
                </div>
                <div class="pt-4 flex justify-end space-x-3">
                    <button type="button" @click="anularModal = false" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Confirmar Anulación</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
