@extends('layouts.app')

@section('content')
<div class="space-y-6" x-data="{ anularId: null }">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Historial de Ventas</h2>
            <p class="page-subtitle">Registro completo de documentos: cotizaciones, facturas por cobrar, pagadas y anuladas.</p>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" action="{{ url('/historial-ventas') }}">
                <select name="estado" onchange="this.form.submit()" class="text-sm shadow-sm border border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-2 focus:ring-emerald-500">
                    <option value="">Todos los estados</option>
                    <option value="pagada" {{ request('estado') === 'pagada' ? 'selected' : '' }}>Pagada</option>
                    <option value="emitida" {{ request('estado') === 'emitida' ? 'selected' : '' }}>Emitida (Por cobrar)</option>
                    <option value="borrador" {{ request('estado') === 'borrador' ? 'selected' : '' }}>Cotización (Borrador)</option>
                    <option value="anulada" {{ request('estado') === 'anulada' ? 'selected' : '' }}>Anulada</option>
                </select>
            </form>
            <a href="{{ url('/pos') }}" class="btn btn-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                Ir a Caja (POS)
            </a>
        </div>
    </div>

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Factura #</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha y Hora</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Cliente</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Pago</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total (C$)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($ventas as $venta)
                    <tr class="hover:bg-gray-50 transition {{ $venta->status === 'anulada' ? 'bg-red-50/40' : '' }}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            INV-{{ str_pad($venta->id, 5, '0', STR_PAD_LEFT) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $venta->created_at->format('d/m/Y h:i A') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-800">
                            {{ $venta->cliente ? $venta->cliente->name : 'Cliente General' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @php
                                $estilosEstado = [
                                    'pagada'   => 'bg-emerald-100 text-emerald-800',
                                    'emitida'  => 'bg-amber-100 text-amber-800',
                                    'borrador' => 'bg-gray-200 text-gray-700',
                                    'anulada'  => 'bg-red-100 text-red-800',
                                ];
                                $etiquetasEstado = [
                                    'pagada'   => 'Pagada',
                                    'emitida'  => 'Por Cobrar',
                                    'borrador' => 'Cotización',
                                    'anulada'  => 'Anulada',
                                ];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $estilosEstado[$venta->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $etiquetasEstado[$venta->status] ?? $venta->status }}
                            </span>
                            @if($venta->status === 'anulada' && $venta->motivo_anulacion)
                                <p class="text-[10px] text-red-500 italic mt-1 max-w-[180px] truncate" title="{{ $venta->motivo_anulacion }}">
                                    {{ $venta->motivo_anulacion }}
                                </p>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $venta->pagos_cobro_count > 1 ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }} uppercase">
                                {{ $venta->pagos_cobro_count > 1 ? 'Mixto ('.$venta->pagos_cobro_count.')' : $venta->payment_method }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold {{ $venta->status === 'anulada' ? 'text-red-400 line-through' : 'text-emerald-600' }}">
                            C$ {{ number_format($venta->total, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex justify-end items-center gap-3">
                                {{-- Continuar cotización: carga el documento en el POS --}}
                                @if($venta->status === 'borrador')
                                    <a href="{{ url('/pos') }}?draft_id={{ $venta->id }}" class="text-gray-700 hover:text-gray-900 font-bold transition" title="Cargar en el POS">
                                        Cotizar / Cobrar
                                    </a>
                                @endif

                                {{-- Cobrar factura a crédito --}}
                                @if($venta->status === 'emitida')
                                    <a href="{{ url('/pos') }}?cobro_id={{ $venta->id }}" class="text-amber-600 hover:text-amber-900 font-bold transition">
                                        Cobrar
                                    </a>
                                @endif

                                <a href="{{ url('/ventas/'.$venta->id.'/ticket') }}" target="_blank" class="text-emerald-600 hover:text-emerald-900 flex items-center transition">
                                    <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                    Imprimir
                                </a>

                                {{-- ANULAR: privilegio exclusivo del administrador, nunca borra el documento --}}
                                @if($esAdmin && in_array($venta->status, ['pagada', 'emitida', 'borrador']))
                                    <button @click="anularId = {{ $venta->id }}" class="text-red-500 hover:text-red-700 font-bold transition">
                                        Anular
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay ventas registradas aún.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL DE ANULACIÓN (auditoría: motivo obligatorio) -->
    <div x-show="anularId" style="display: none;" class="fixed inset-0 z-[90] overflow-y-auto bg-gray-900/60 backdrop-blur-sm">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
                <div class="bg-red-600 text-white px-6 py-4">
                    <h3 class="text-lg font-bold">Anular Factura</h3>
                    <p class="text-red-100 text-xs">El documento NO se borrará: se revertirá contablemente y el inventario regresará.</p>
                </div>
                <form method="POST" :action="'/ventas/' + anularId + '/anular'">
                    @csrf
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Motivo de la anulación (obligatorio)</label>
                            <textarea name="motivo" rows="3" required minlength="5" maxlength="255"
                                      class="w-full text-sm shadow-sm border border-gray-300 rounded-lg py-2 px-3 focus:ring-2 focus:ring-red-500"
                                      placeholder="Ej. Cliente canceló el servicio / error de digitación..."></textarea>
                        </div>
                        <p class="text-[11px] text-gray-400 italic">
                            Se registrará quién anuló, cuándo y por qué. Si la factura tiene comisiones ya pagadas en nómina, la anulación será rechazada.
                        </p>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-row-reverse gap-3">
                        <button type="submit" class="btn bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-4 rounded-xl flex-1">
                            Confirmar Anulación
                        </button>
                        <button type="button" @click="anularId = null" class="btn btn-secondary">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
