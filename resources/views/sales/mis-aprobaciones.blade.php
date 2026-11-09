@extends('layouts.app')

@section('title', 'Mis Ventas Pendientes de Aprobación')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Mis Ventas Pendientes de Aprobación</h2>
            <p class="text-gray-500 text-sm mt-1">Ventas que requieren autorización del gerente antes de contabilizarse.</p>
        </div>
        <a href="{{ url('/historial-ventas?estado=pendiente_aprobacion') }}" class="btn btn-secondary">
            Ver en historial completo
        </a>
    </div>

    @if(session('success'))
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
            <span class="text-emerald-800 font-semibold">{{ session('success') }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3">
            <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
            <span class="text-red-800 font-semibold">{{ session('error') }}</span>
        </div>
    @endif

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Factura #</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Cliente</th>
                    <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Total (C$)</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($misVentasPendientes as $venta)
                    <tr class="hover:bg-amber-50/40 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            INV-{{ str_pad($venta->id, 5, '0', STR_PAD_LEFT) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $venta->created_at->format('d/m/Y h:i A') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            {{ $venta->cliente?->name ?? 'Cliente General' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-amber-700">
                            C$ {{ number_format($venta->total, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($venta->status === \App\Models\Venta::ESTADO_PENDIENTE_APROBACION)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2a1 1 0 102 0v-2zm0 6a1 1 0 10-2 0v4a1 1 0 102 0v-4z" clip-rule="evenodd"></path></svg>
                                    Pendiente aprobación
                                </span>
                            @elseif($venta->status === \App\Models\Venta::ESTADO_PAGADA)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                                    Aprobada y contabilizada
                                </span>
                            @elseif($venta->status === \App\Models\Venta::ESTADO_ANULADA)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                                    Rechazada / Anulada
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-gray-100 text-gray-600">
                                    {{ ucfirst($venta->status) }}
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex justify-center items-center gap-2">
                                @if($venta->status === \App\Models\Venta::ESTADO_PENDIENTE_APROBACION)
                                    <button disabled class="btn btn-secondary text-xs px-3 py-1.5" title="Esperando aprobación del gerente">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        Esperando...
                                    </button>
                                @elseif($venta->status === \App\Models\Venta::ESTADO_PAGADA)
                                    <a href="{{ url('/ventas/' . $venta->id . '/ticket') }}" target="_blank"
                                       class="btn bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-3 py-1.5"
                                       title="Generar/Ver ticket de venta">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                                        Ver Ticket
                                    </a>
                                    <a href="{{ url('/ventas/' . $venta->id . '/documento') }}"
                                       class="btn btn-secondary text-xs px-3 py-1.5"
                                       title="Ver detalle completo">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                        Detalle
                                    </a>
                                @elseif($venta->status === \App\Models\Venta::ESTADO_ANULADA)
                                    <a href="{{ url('/ventas/' . $venta->id . '/documento') }}"
                                       class="btn btn-secondary text-xs px-3 py-1.5"
                                       title="Ver motivo de anulación">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                        Ver motivo
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <div class="max-w-md mx-auto">
                                <svg class="w-20 h-20 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">No tienes ventas pendientes</h3>
                                <p class="text-gray-500 text-sm">Cuando una venta quede retenida para aprobación, aparecerá aquí.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Información adicional --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-6">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <h4 class="font-bold text-blue-900 mb-2">¿Cómo funciona?</h4>
                <ul class="text-sm text-blue-800 space-y-1">
                    <li>• Al confirmar una venta en el POS, si el monto supera el umbral (C$ 1), la venta queda <strong>retenida</strong> en estado "Pendiente aprobación".</li>
                    <li>• <strong>NO se descuenta inventario</strong>, <strong>NO se registra en contabilidad</strong>, <strong>NO hay ticket</strong> hasta que el gerente la apruebe.</li>
                    <li>• El gerente (Álvaro) recibe notificación y la aprueba desde <strong>Aprobaciones Pendientes</strong>.</li>
                    <li>• Al aprobar: se ejecutan inventario, pagos, comisiones y asiento contable. La venta pasa a <strong>Aprobada</strong>.</li>
                    <li>• Aquí verás el estado actualizado y podrás <strong>generar el ticket</strong> cuando esté aprobada.</li>
                    <li>• Si es rechazada: verás el motivo y la venta quedará como <strong>Anulada</strong>.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection