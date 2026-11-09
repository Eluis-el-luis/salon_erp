@extends('layouts.app')

@section('title', 'Notificaciones')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="page-title">Notificaciones</h2>
            <p class="text-gray-500 text-sm mt-1">Historial completo de alertas del sistema</p>
        </div>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('notificaciones.api.read-all') }}" class="inline">
                @csrf
                <button type="submit" class="btn btn-secondary text-sm">
                    Marcar todas como leídas
                </button>
            </form>
        </div>
    </div>

    @if($notifications->isEmpty())
        <div class="card p-12 text-center">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">No hay notificaciones</h3>
            <p class="text-gray-500">Cuando haya alertas del sistema, aparecerán aquí.</p>
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="divide-y divide-gray-100">
                @foreach($notifications as $notif)
                    @php
                        $typeClasses = [
                            // Ventas/Compras (verde)
                            'venta_pendiente_aprobacion' => 'bg-emerald-100 text-emerald-600',
                            'venta_aprobada' => 'bg-emerald-100 text-emerald-600',
                            'compra_pendiente_aprobacion' => 'bg-emerald-100 text-emerald-600',
                            'compra_pendiente_aprobacion_admin' => 'bg-emerald-100 text-emerald-600',
                            
                            // Rechazos (rojo)
                            'venta_rechazada' => 'bg-red-100 text-red-600',
                            'venta_rechazada_alvaro' => 'bg-red-100 text-red-600',
                            'compra_rechazada_alvaro' => 'bg-red-100 text-red-600',
                            
                            // Caja (ámbar/rojo)
                            'caja_sin_cerrar' => 'bg-amber-100 text-amber-600',
                            'caja_diferencia' => 'bg-red-100 text-red-600',
                            
                            // Stock (azul/rojo)
                            'stock_critico' => 'bg-blue-100 text-blue-600',
                            'stock_agotado' => 'bg-red-100 text-red-600',
                            'merma_registrada' => 'bg-red-100 text-red-600',
                            'proveedor_nuevo' => 'bg-blue-100 text-blue-600',
                            
                            // Finanzas CxP/CxC (rojo/ámbar)
                            'cxp_vencida' => 'bg-red-100 text-red-600',
                            'cxp_por_vencer' => 'bg-amber-100 text-amber-600',
                            'cxc_vencida' => 'bg-red-100 text-red-600',
                            'cxc_por_vencer' => 'bg-amber-100 text-amber-600',
                            'conciliacion_pendiente' => 'bg-blue-100 text-blue-600',
                            'cierre_contable' => 'bg-purple-100 text-purple-600',
                            'asiento_desbalanceado' => 'bg-red-100 text-red-600',
                            'banco_movimiento' => 'bg-blue-100 text-blue-600',
                            'iva_declaracion' => 'bg-purple-100 text-purple-600',
                            
                            // Caja
                            'caja_diferencia' => 'bg-red-100 text-red-600',
                            
                            // RRHH (púrpura)
                            'empleado_nuevo' => 'bg-purple-100 text-purple-600',
                            'ausencia_pendiente' => 'bg-amber-100 text-amber-600',
                            'nomina_pendiente' => 'bg-purple-100 text-purple-600',
                            'comision_ganada' => 'bg-emerald-100 text-emerald-600',
                            'comision_pagada' => 'bg-emerald-100 text-emerald-600',
                            'propina_recibida' => 'bg-amber-100 text-amber-600',
                            
                            // Agenda (azul)
                            'cita_nueva' => 'bg-blue-100 text-blue-600',
                            'cita_asignada' => 'bg-blue-100 text-blue-600',
                            'cita_cancelada' => 'bg-red-100 text-red-600',
                            'cita_reagendada' => 'bg-blue-100 text-blue-600',
                            'cita_modificada' => 'bg-blue-100 text-blue-600',
                            
                            // Clientes
                            'cliente_nuevo' => 'bg-emerald-100 text-emerald-600',
                            'cliente_cxc_vencida' => 'bg-red-100 text-red-600',
                            'cotizacion_solicitada' => 'bg-gray-100 text-gray-600',
                            
                            // Comisiones/Propinas (verde/ámbar)
                            'comision_ganada' => 'bg-emerald-100 text-emerald-600',
                            'comision_pagada' => 'bg-emerald-100 text-emerald-600',
                            'propina_recibida' => 'bg-amber-100 text-amber-600',
                            
                            // RRHH
                            'empleado_nuevo' => 'bg-purple-100 text-purple-600',
                            'ausencia_pendiente' => 'bg-amber-100 text-amber-600',
                            'nomina_pendiente' => 'bg-purple-100 text-purple-600',
                            
                            // Sistema
                            'alerta_critica' => 'bg-red-100 text-red-600',
                            'reporte_diario' => 'bg-blue-100 text-blue-600',
                            'respaldo_faltante' => 'bg-gray-100 text-gray-600',
                            'respaldo_completado' => 'bg-emerald-100 text-emerald-600',
                            'alerta_critica' => 'bg-red-100 text-red-600',
                        ];
                        $bgClass = $typeClasses[$notif->type] ?? 'bg-gray-100 text-gray-600';
                        
                        // Iconos SVG por tipo
                        $iconPaths = [
                            // Ventas/Compras
                            'venta_pendiente_aprobacion' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                            'venta_aprobada' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                            'compra_pendiente_aprobacion' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                            'compra_pendiente_aprobacion_admin' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
                            
                            // Rechazos
                            'venta_rechazada' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                            'venta_rechazada_alvaro' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                            'compra_rechazada_alvaro' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                            
                            // Caja
                            'caja_sin_cerrar' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                            'caja_diferencia' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                            
                            // Stock
                            'stock_critico' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                            'stock_agotado' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                            'merma_registrada' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16',
                            'proveedor_nuevo' => 'M19 17a2 2 0 11-4 0 2 2 0 014 0zM9 17a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1',
                            
                            // Finanzas
                            'cxp_vencida' => 'M3 10h18M7 15h4M3 6h18a1 1 0 011 1v10a1 1 0 01-1 1H3a1 1 0 01-1-1V7a1 1 0 011-1z',
                            'cxp_por_vencer' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'cxc_vencida' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'cxc_por_vencer' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'conciliacion_pendiente' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                            'cierre_contable' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                            'asiento_desbalanceado' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                            'banco_movimiento' => 'M3 10h18M7 15h4M3 6h18a1 1 0 011 1v10a1 1 0 01-1 1H3a1 1 0 01-1-1V7a1 1 0 011-1z',
                            'iva_declaracion' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            
                            // Agenda
                            'cita_nueva' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            'cita_asignada' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            'cita_cancelada' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
                            'cita_reagendada' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                            'cita_modificada' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
                            
                            // Clientes
                            'cliente_nuevo' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2 5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z',
                            'cliente_cxc_vencida' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'cotizacion_solicitada' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            
                            // Comisiones/Propinas
                            'comision_ganada' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                            'comision_pagada' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                            'propina_recibida' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
                            
                            // RRHH
                            'empleado_nuevo' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2 5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z',
                            'ausencia_pendiente' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            'nomina_pendiente' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                            
                            // Sistema
                            'alerta_critica' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                            'reporte_diario' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                            'respaldo_faltante' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4',
                            'respaldo_completado' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
                            
                            // Default
                            'default' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                        ];
                        $iconPath = $iconPaths[$notif->type] ?? $iconPaths['default'];
                    @endphp
                    
                    <a href="{{ $notif->data['url'] ?? '#' }}"
                       class="flex items-start gap-4 px-6 py-4 hover:bg-gray-50 transition border-b border-gray-50 last:border-0"
                       style="text-decoration: none;"
                       {{ $notif->data['url'] ? '' : 'tabindex="-1"' }}>
                        
                        <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center {{ $bgClass }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconPath }}"></path>
                            </svg>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-gray-900 {{ !$notif->read ? 'font-bold' : '' }}">{{ $notif->title }}</p>
                                <span class="text-[10px] text-gray-400 whitespace-nowrap">{{ \Carbon\Carbon::parse($notif->created_at)->diffForHumans() }}</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 truncate">{{ $notif->message ?? 'Sin mensaje' }}</p>
                            @if(!$notif->read)
                                <span class="inline-block mt-1.5 w-2 h-2 bg-emerald-500 rounded-full" title="No leída"></span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Paginación --}}
            @if($notifications->hasPages())
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100">
                    {{ $notifications->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection