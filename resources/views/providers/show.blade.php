@extends('layouts.app')

@section('content')
<div x-data="{ tab: 'facturas', abonoModal: false, abonoCuentaId: null, abonoSaldo: 0, abonoVencimiento: '' }" class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <a href="{{ url('/proveedores') }}" class="text-gray-400 hover:text-gray-600 transition" title="Volver al directorio">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </a>
                <h2 class="page-title">{{ $proveedor->name }}</h2>
                @if(!$proveedor->is_active)
                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-gray-200 text-gray-600">INACTIVO</span>
                @endif
            </div>
            <p class="page-subtitle ml-9">
                @if($proveedor->tax_id) RUC {{ $proveedor->tax_id }} · @endif
                Crédito a {{ $proveedor->credit_days }} días
                @if($proveedor->contact_name) · Contacto: {{ $proveedor->contact_name }} @endif
                @if($proveedor->phone) · {{ $proveedor->phone }} @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ url('/inventario/comprar') }}" class="btn btn-primary">Registrar Compra</a>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- Métricas de deuda (AP en tiempo real) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5 {{ $metricas['saldo_pendiente'] > 0 ? 'border-red-200 bg-red-50' : '' }}">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Saldo por Pagar</p>
            <p class="text-2xl font-black {{ $metricas['saldo_pendiente'] > 0 ? 'text-red-600' : 'text-emerald-600' }} mt-1">
                C$ {{ number_format($metricas['saldo_pendiente'], 2) }}
            </p>
            <p class="text-xs text-gray-400 mt-1">{{ $metricas['facturas_abiertas'] }} factura(s) abierta(s)</p>
        </div>
        <div class="card p-5 {{ $metricas['monto_vencido'] > 0 ? 'border-red-300' : '' }}">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Monto Vencido</p>
            <p class="text-2xl font-black {{ $metricas['monto_vencido'] > 0 ? 'text-red-600' : 'text-gray-400' }} mt-1">
                C$ {{ number_format($metricas['monto_vencido'], 2) }}
            </p>
            <p class="text-xs text-gray-400 mt-1">{{ $metricas['facturas_vencidas'] }} factura(s) vencida(s)</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total Comprado</p>
            <p class="text-2xl font-black text-gray-800 mt-1">C$ {{ number_format($metricas['total_comprado'], 2) }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $metricas['articulos_suministrados'] }} artículo(s) en catálogo</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total Pagado</p>
            <p class="text-2xl font-black text-emerald-600 mt-1">C$ {{ number_format($metricas['total_pagado'], 2) }}</p>
            <p class="text-xs text-gray-400 mt-1">Abonos registrados en CxP</p>
        </div>
    </div>

    <!-- Pestañas -->
    <div class="card overflow-hidden">
        <div class="flex border-b border-gray-200 bg-gray-50 overflow-x-auto">
            <button @click="tab = 'facturas'" :class="tab === 'facturas' ? 'border-emerald-500 text-emerald-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-5 py-3 text-sm font-bold border-b-2 whitespace-nowrap transition">
                Facturas de Compra ({{ $facturas->count() + $cxpLegacy->count() }})
            </button>
            <button @click="tab = 'pagos'" :class="tab === 'pagos' ? 'border-emerald-500 text-emerald-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-5 py-3 text-sm font-bold border-b-2 whitespace-nowrap transition">
                Pagos Emitidos ({{ $pagos->count() }})
            </button>
            <button @click="tab = 'mermas'" :class="tab === 'mermas' ? 'border-emerald-500 text-emerald-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-5 py-3 text-sm font-bold border-b-2 whitespace-nowrap transition">
                Mermas / Devoluciones ({{ $mermas->count() }})
            </button>
            <button @click="tab = 'catalogo'" :class="tab === 'catalogo' ? 'border-emerald-500 text-emerald-700 bg-white' : 'border-transparent text-gray-500 hover:text-gray-700'" class="px-5 py-3 text-sm font-bold border-b-2 whitespace-nowrap transition">
                Catálogo Suministrado ({{ $articulos->count() }})
            </button>
        </div>

        <!-- TAB: FACTURAS DE COMPRA -->
        <div x-show="tab === 'facturas'" class="p-5 space-y-6">
            <div class="overflow-x-auto rounded-lg border border-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Fecha</th>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Documento</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Condición</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Total</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Saldo CxP</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Estado</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($facturas as $factura)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">{{ $factura->fecha_factura->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 font-bold text-gray-900">
                                {{ $factura->numero_documento ?? ('DOC ' . $factura->id) }}
                            </td>
                            <td class="px-4 py-3 text-center text-gray-600">
                                {{ $factura->condicion_pago === 'credito' ? 'Crédito' : 'Contado' }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900">C$ {{ number_format($factura->total, 2) }}</td>
                            <td class="px-4 py-3 text-right {{ $factura->cuentaPorPagar && $factura->cuentaPorPagar->saldo_pendiente > 0 ? 'text-red-600 font-bold' : 'text-gray-400' }}">
                                C$ {{ number_format($factura->cuentaPorPagar->saldo_pendiente ?? 0, 2) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($factura->estado === 'anulada')
                                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-gray-200 text-gray-600">Anulada</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Registrada</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <a href="{{ route('compras.show', $factura->id) }}" class="text-blue-600 hover:text-blue-900 font-bold text-xs transition">Ver detalle</a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Sin facturas de compra registradas con el nuevo flujo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($cxpLegacy->count() > 0)
            <div>
                <h4 class="text-sm font-bold text-gray-700 mb-2">Cuentas por Pagar del histórico (flujo anterior)</h4>
                <div class="overflow-x-auto rounded-lg border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Emisión</th>
                                <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Vencimiento</th>
                                <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Original</th>
                                <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Saldo</th>
                                <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Estado</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($cxpLegacy as $cuenta)
                            @php $vencida = \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->isPast() && in_array($cuenta->estado, ['pendiente','parcial']); @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600">{{ \Carbon\Carbon::parse($cuenta->fecha_emision)->format('d/m/Y') }}</td>
                                <td class="px-4 py-3 text-center {{ $vencida ? 'text-red-600 font-bold' : 'text-gray-600' }}">
                                    {{ \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->format('d/m/Y') }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500">C$ {{ number_format($cuenta->monto_original, 2) }}</td>
                                <td class="px-4 py-3 text-right font-bold">C$ {{ number_format($cuenta->saldo_pendiente, 2) }}</td>
                                <td class="px-4 py-3 text-center text-xs font-bold uppercase {{ $vencida ? 'text-red-600' : 'text-gray-500' }}">{{ $cuenta->estado }}</td>
                                <td class="px-4 py-3 text-center">
                                    @if(in_array($cuenta->estado, ['pendiente','parcial']))
                                        <button @click="abonoModal = true; abonoCuentaId = {{ $cuenta->id }}; abonoSaldo = {{ $cuenta->saldo_pendiente }}; abonoVencimiento = '{{ \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->format('d/m/Y') }}'" class="text-emerald-600 hover:text-emerald-900 font-bold text-xs transition">Abonar</button>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            @if($cuentasAbiertas->count() > 0)
            <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                <p class="text-sm font-bold text-emerald-800 mb-1">Registrar abono rápido a una cuenta abierta</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($cuentasAbiertas as $cuenta)
                        <button @click="abonoModal = true; abonoCuentaId = {{ $cuenta->id }}; abonoSaldo = {{ $cuenta->saldo_pendiente }}; abonoVencimiento = '{{ \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->format('d/m/Y') }}'" class="text-xs bg-white border border-emerald-300 hover:bg-emerald-100 rounded-lg px-3 py-2 font-bold text-emerald-800 transition">
                            {{ $cuenta->numero_documento ?? ('CxP #' . $cuenta->id) }} — C$ {{ number_format($cuenta->saldo_pendiente, 2) }}
                            <span class="text-gray-400 font-normal">· vence {{ \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->format('d/m') }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        <!-- TAB: PAGOS EMITIDOS -->
        <div x-show="tab === 'pagos'" x-cloak class="p-5">
            <div class="overflow-x-auto rounded-lg border border-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Fecha</th>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">CxP</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Forma de Pago</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Monto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($pagos as $pago)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">{{ \Carbon\Carbon::parse($pago->fecha)->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-gray-700">
                                {{ $pago->cuentaPorPagar->numero_documento ?? ('CxP #' . $pago->cuenta_por_pagar_id) }}
                            </td>
                            <td class="px-4 py-3 text-center text-gray-600 capitalize">{{ $pago->forma_pago }}</td>
                            <td class="px-4 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($pago->monto, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">No se han emitido pagos a este proveedor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: MERMAS / DEVOLUCIONES -->
        <div x-show="tab === 'mermas'" x-cloak class="p-5">
            <div class="overflow-x-auto rounded-lg border border-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Fecha</th>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Artículo</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Tipo</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Cantidad</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Valor</th>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($mermas as $merma)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">{{ \Carbon\Carbon::parse($merma->fecha)->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $merma->articulo->producto ?? 'Artículo eliminado' }}</td>
                            <td class="px-4 py-3 text-center text-gray-600 capitalize">{{ $merma->tipo }}</td>
                            <td class="px-4 py-3 text-center text-gray-700">{{ $merma->cantidad }}</td>
                            <td class="px-4 py-3 text-right font-bold text-red-600">C$ {{ number_format($merma->valor, 2) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $merma->motivo }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Sin mermas registradas de los artículos de este proveedor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: CATÁLOGO -->
        <div x-show="tab === 'catalogo'" x-cloak class="p-5">
            <div class="overflow-x-auto rounded-lg border border-gray-100">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-bold text-gray-600 uppercase text-xs">Producto</th>
                            <th class="px-4 py-3 text-center font-bold text-gray-600 uppercase text-xs">Stock</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Costo Promedio</th>
                            <th class="px-4 py-3 text-right font-bold text-gray-600 uppercase text-xs">Precio Venta (C$)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($articulos as $articulo)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">
                                {{ $articulo->producto }}
                                @if($articulo->is_fractionable)
                                    <span class="text-xs text-gray-400">({{ $articulo->unit_measure ?: 'ml' }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center {{ $articulo->existencia_actual <= $articulo->stock_min ? 'text-red-600 font-bold' : 'text-gray-700' }}">
                                {{ $articulo->existencia_actual }}
                            </td>
                            <td class="px-4 py-3 text-right text-gray-700">C$ {{ number_format($articulo->costo_promedio, 2) }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">C$ {{ number_format($articulo->precio_c, 2) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Ningún artículo del catálogo está vinculado a este proveedor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal de abono rápido -->
    <div x-show="abonoModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="abonoModal = false">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Abonar a Cuenta por Pagar</h3>
            <p class="text-sm text-gray-500 mb-4">Saldo pendiente: <span class="font-bold text-gray-800">C$ <span x-text="Number(abonoSaldo).toFixed(2)"></span></span> · Vence <span x-text="abonoVencimiento"></span></p>

            <form action="{{ url('/cuentas-por-pagar/abonar') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="cuenta_por_pagar_id" :value="abonoCuentaId">

                <div>
                    <label class="label">Monto a Pagar (C$)</label>
                    <input type="number" name="monto" step="0.01" required :max="abonoSaldo" min="0.01" class="input text-lg font-bold">
                </div>

                <div>
                    <label class="label">Origen de los Fondos</label>
                    <select name="forma_pago" required class="input">
                        <option value="efectivo">Caja / Efectivo</option>
                        <option value="banco">Transferencia Bancaria</option>
                    </select>
                </div>

                <div class="pt-4 flex justify-end space-x-3">
                    <button type="button" @click="abonoModal = false" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Abono</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
