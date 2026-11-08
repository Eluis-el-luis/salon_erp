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
            <button wire:click="markAllRead" class="btn btn-secondary text-sm">
                Marcar todas como leídas
            </button>
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
                    <a href="{{ $notif->data['url'] ?? '#' }}"
                       class="flex items-start gap-4 px-6 py-4 hover:bg-gray-50 transition border-b border-gray-50 last:border-0"
                       style="text-decoration: none;"
                       {{ $notif->data['url'] ? '' : 'tabindex="-1"' }}>
                        
                        <div class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                             :class="{
                                 'bg-emerald-100 text-emerald-600': $notif->type === 'venta_pendiente_aprobacion' || $notif->type === 'venta_aprobada',
                                 'bg-red-100 text-red-600': $notif->type === 'venta_rechazada',
                                 'bg-amber-100 text-amber-600': $notif->type === 'caja_sin_cerrar',
                                 'bg-red-100 text-red-600': $notif->type === 'cxp_vencida' || $notif->type === 'cxc_vencida',
                                 'bg-blue-100 text-blue-600': $notif->type === 'stock_critico',
                                 'bg-purple-100 text-purple-600': $notif->type === 'compra_pendiente_aprobacion',
                                 'bg-gray-100 text-gray-600': true
                             }">
                            @switch($notif->type)
                                @case('venta_pendiente_aprobacion')
                                @case('compra_pendiente_aprobacion')
                                @case('venta_aprobada')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                                    @break
                                @case('venta_rechazada')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    @break
                                @case('caja_sin_cerrar')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                    @break
                                @case('stock_critico')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                    @break
                                @case('cxp_vencida')
                                @case('cxc_vencida')
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    @break
                                @default
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            @endswitch
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