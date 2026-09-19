@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="page-title">Centro de Mando</h1>
            <p class="page-subtitle">Resumen financiero y operativo del día</p>
        </div>
        <span class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-800 bg-emerald-50 border border-emerald-200 px-4 py-2 rounded-full capitalize">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            {{ \Carbon\Carbon::now()->locale('es')->isoFormat('dddd, D [de] MMMM [de] YYYY') }}
        </span>
    </div>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full bg-emerald-100 text-emerald-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total Ingresos</p>
                <p class="text-2xl font-black text-gray-900">C$ {{ number_format($totalIngresos, 2) }}</p>
            </div>
        </div>

        <div class="card p-6">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">Ingresos por Vía</p>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 flex items-center font-medium"><span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span>Efectivo</span>
                    <span class="font-bold text-gray-900">C$ {{ number_format($ventasEfectivo, 2) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 flex items-center font-medium"><span class="w-2 h-2 rounded-full bg-blue-600 mr-2"></span>BAC</span>
                    <span class="font-bold text-gray-900">C$ {{ number_format($ventasBac, 2) }}</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600 flex items-center font-medium"><span class="w-2 h-2 rounded-full bg-teal-700 mr-2"></span>LAFISE</span>
                    <span class="font-bold text-gray-900">C$ {{ number_format($ventasLafise, 2) }}</span>
                </div>
                <div class="flex justify-between items-center pt-1 border-t border-gray-100 text-red-600">
                    <span class="flex items-center font-semibold">Egresos (Caja Chica)</span>
                    <span class="font-black">- C$ {{ number_format($egresosCaja, 2) }}</span>
                </div>
            </div>
        </div>

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full bg-purple-100 text-purple-700">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Agenda del Día</p>
                <p class="text-2xl font-black text-gray-900">{{ $citasPendientes }} <span class="text-sm font-medium text-gray-400">pendientes</span></p>
                <p class="text-xs text-gray-500 mt-1 font-medium">De {{ $citasTotal }} citas programadas</p>
            </div>
        </div>

        <div class="card p-6 flex items-center space-x-4">
            <div class="p-3 rounded-full {{ $stockCritico > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-600' }}">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    @if($stockCritico > 0)
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    @endif
                </svg>
            </div>
            <div>
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Inventario</p>
                <p class="text-2xl font-black {{ $stockCritico > 0 ? 'text-red-600' : 'text-gray-900' }}">
                    {{ $stockCritico }} <span class="text-sm font-medium text-gray-400">críticos</span>
                </p>
            </div>
        </div>

    </div>

    <!-- Últimos Movimientos -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Últimos Movimientos</h3>
                <p class="text-xs text-gray-500 mt-1">Transacciones procesadas recientemente en la caja.</p>
            </div>
            <a href="{{ url('/historial-ventas') }}" class="btn btn-secondary py-2 text-sm">Ver historial &rarr;</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Hora</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Cajero</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Método de Pago</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Descuento</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Ingreso Neto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($ultimasVentas as $venta)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <span class="inline-flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    {{ $venta->created_at->format('h:i A') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 font-medium">
                                {{ $venta->cajero ? $venta->cajero->name : 'Admin Salón' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                @if($venta->payment_method == 'efectivo')
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Efectivo</span>
                                @elseif(in_array($venta->payment_method, ['bac', 'lafise']))
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-blue-50 text-blue-700 border border-blue-200">{{ strtoupper($venta->payment_method) }}</span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-xs font-bold rounded-full bg-purple-50 text-purple-700 border border-purple-200">{{ ucfirst($venta->payment_method) }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold {{ $venta->discount > 0 ? 'text-red-500' : 'text-gray-300' }}">
                                {{ $venta->discount > 0 ? '- C$ ' . number_format($venta->discount, 2) : '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-black text-emerald-700 text-base">
                                C$ {{ number_format($venta->total, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-gray-500 text-sm font-medium">
                                No hay movimientos registrados el día de hoy.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection