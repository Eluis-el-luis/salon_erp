@extends('layouts.app')

@section('title', 'Aprobaciones Pendientes')

@section('content')
<div class="space-y-6" x-data="aprobaciones()">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Aprobaciones Pendientes</h2>
            <p class="page-subtitle">Transacciones retenidas por gobernanza: no tocan contabilidad ni inventario hasta que un supervisor las apruebe.</p>
        </div>
        <a href="{{ url('/historial-ventas?estado=pendiente_aprobacion') }}" class="btn btn-secondary">
            Ver en historial de ventas
        </a>
    </div>

    {{-- ================================================
         VENTAS RETENIDAS
         ================================================ --}}
    <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 flex items-center gap-2">
                <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full text-xs font-bold uppercase">Ventas</span>
                {{ $pendientes['ventas']->count() }} retenida(s)
            </h3>
            <span class="text-xs text-gray-400">Al aprobar: inventario + libro de pagos + comisiones + asiento contable</span>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Factura #</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Cajero</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Cliente</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total (C$)</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($pendientes['ventas'] as $venta)
                    <tr class="hover:bg-amber-50/40 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            INV-{{ str_pad($venta->id, 5, '0', STR_PAD_LEFT) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $venta->created_at->format('d/m/Y h:i A') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            {{ $venta->cajero?->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            {{ $venta->cliente?->name ?? 'Cliente General' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-amber-700">
                            C$ {{ number_format($venta->total, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="flex justify-end items-center gap-3">
                                {{-- APROBAR: doble confirmación con PIN de supervisor --}}
                                <form action="{{ route('aprobaciones.venta.aprobar', $venta->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="button"
                                            @click="window.dispatchEvent(new CustomEvent('open-pin', { detail: { mensaje: 'Aprobar una venta retenida exige el PIN de un supervisor (Álvaro / Administrador).', form: $event.target.closest('form') } }))"
                                            class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
                                        Aprobar
                                    </button>
                                </form>
                                <span class="text-gray-200">|</span>
                                <button @click="abrirRechazoVenta({{ $venta->id }})" class="text-red-500 hover:text-red-700 font-bold text-sm transition">
                                    Rechazar
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay ventas retenidas. Todo al día.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ================================================
         COMPRAS RETENIDAS
         ================================================ --}}
    <div class="card overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 flex items-center gap-2">
                <span class="bg-sky-100 text-sky-800 px-2 py-0.5 rounded-full text-xs font-bold uppercase">Compras</span>
                {{ $pendientes['compras']->count() }} retenida(s)
            </h3>
            <span class="text-xs text-gray-400">Al aprobar: inventario + lotes FIFO + kárdex + CxP + asiento COM-*</span>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Compra #</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Proveedor</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Registró</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Condición</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total (C$)</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($pendientes['compras'] as $compra)
                    <tr class="hover:bg-sky-50/40 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            <a href="{{ route('compras.show', $compra->id) }}" class="text-sky-700 hover:underline">COM-{{ str_pad($compra->id, 5, '0', STR_PAD_LEFT) }}</a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $compra->created_at->format('d/m/Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            {{ $compra->proveedor?->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            {{ $compra->usuario?->name ?? '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 capitalize">
                            {{ $compra->condicion_pago }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-sky-700">
                            C$ {{ number_format($compra->total, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="flex justify-end items-center gap-3">
                                {{-- APROBAR: doble confirmación con PIN de supervisor --}}
                                <form action="{{ route('aprobaciones.compra.aprobar', $compra->id) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="button"
                                            @click="window.dispatchEvent(new CustomEvent('open-pin', { detail: { mensaje: 'Aprobar una compra retenida exige el PIN de un supervisor (Álvaro / Administrador).', form: $event.target.closest('form') } }))"
                                            class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
                                        Aprobar
                                    </button>
                                </form>
                                <span class="text-gray-200">|</span>
                                <button @click="abrirRechazoCompra({{ $compra->id }})" class="text-red-500 hover:text-red-700 font-bold text-sm transition">
                                    Rechazar
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay compras retenidas.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ================================================
         MODAL DE RECHAZO (motivo obligatorio para auditoría)
         ================================================ --}}
    <div x-show="rechazo" style="display: none;" class="fixed inset-0 z-[90] overflow-y-auto bg-gray-900/60 backdrop-blur-sm">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
                <div class="bg-red-600 text-white px-6 py-4">
                    <h3 class="text-lg font-bold">Rechazar transacción</h3>
                    <p class="text-red-100 text-xs">Nunca tuvo efectos contables: quedará marcada como anulada con este motivo.</p>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Motivo del rechazo (obligatorio)</label>
                        <textarea x-model="motivo" name="motivo" rows="3" maxlength="255"
                                  class="w-full text-sm shadow-sm border border-gray-300 rounded-lg py-2 px-3 focus:ring-2 focus:ring-red-500"
                                  placeholder="Ej. Monto no concilia con el comprobante del proveedor..." required></textarea>
                    </div>
                    <p class="text-[11px] text-gray-400 italic">
                        Se registrará quién rechazó, cuándo y por qué.
                    </p>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-row-reverse gap-3">
                    <form method="POST" class="flex-1"
                          :action="rechazoVenta ? '/aprobaciones/venta/' + rechazoVenta + '/rechazar' : '/aprobaciones/compra/' + rechazoCompra + '/rechazar'">
                        @csrf
                        {{-- El motivo ya está en el textarea con name="motivo" --}}
                        {{-- DOBLE CONFIRMACIÓN: rechazar exige PIN de supervisor --}}
                        <button type="button"
                                @click="window.dispatchEvent(new CustomEvent('open-pin', { detail: { mensaje: 'Rechazar una transacción retenida exige el PIN de un supervisor (Álvaro / Administrador).', form: $event.target.closest('form') } }))"
                                class="btn bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-4 rounded-xl w-full">
                            Confirmar Rechazo
                        </button>
                    </form>
                    <button type="button" @click="rechazo = false; motivo = ''" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function aprobaciones() {
        return {
            rechazo: false,
            rechazoVenta: null,
            rechazoCompra: null,
            motivo: '',

            abrirRechazoVenta(id) {
                this.rechazoVenta = id;
                this.rechazoCompra = null;
                this.motivo = '';
                this.rechazo = true;
            },

            abrirRechazoCompra(id) {
                this.rechazoCompra = id;
                this.rechazoVenta = null;
                this.motivo = '';
                this.rechazo = true;
            },

            confirmarRechazo(evento) {
                if (!this.motivo || this.motivo.trim().length < 5) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Describe el motivo del rechazo (mínimo 5 caracteres).' }));
                    return;
                }
                // El botón pertenece al formulario con la acción dinámica
                const formulario = evento.target.closest('form');
                if (formulario) formulario.submit();
            }
        }
    }
</script>
@endpush