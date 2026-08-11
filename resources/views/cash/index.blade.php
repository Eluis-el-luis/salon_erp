@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <h2 class="text-3xl font-extrabold text-gray-900">Control de Caja</h2>
        <p class="text-gray-500 text-sm mt-1">Apertura, cierre y arqueo físico del turno actual.</p>
    </div>

    @if(!$session)
        <!-- PANTALLA DE APERTURA -->
        <div class="bg-white p-8 rounded-2xl shadow border border-emerald-100 text-center max-w-md mx-auto">
            <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">Abrir Turno</h3>
            <p class="text-sm text-gray-500 mb-6">Ingresa el fondo inicial o base de efectivo para comenzar a cobrar.</p>
            
            <form action="{{ url('/caja/abrir') }}" method="POST">
                @csrf
                <div class="mb-4 text-left">
                    <label class="block text-sm font-bold text-gray-700">Fondo Inicial (C$)</label>
                    <input type="number" name="monto_apertura" step="0.01" required value="0.00" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-lg font-bold">
                </div>
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg shadow transition">Iniciar Turno de Caja</button>
            </form>
        </div>
    @else
        <!-- PANTALLA DE CIERRE / ARQUEO -->
        @php
            $montoTeorico = $session->monto_apertura + $ventasEfectivo;
        @endphp
        
        <!-- Alerta de Auditoría del Turno Activo -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700 font-medium">
                        Turno abierto por <span class="font-black">{{ $session->user->name }}</span> el 
                        <span class="font-black">{{ \Carbon\Carbon::parse($session->fecha_apertura)->format('d/m/Y') }}</span> a las 
                        <span class="font-black">{{ \Carbon\Carbon::parse($session->fecha_apertura)->format('h:i A') }}</span>.
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Tarjeta Resumen Teórico -->
            <div class="bg-gray-900 text-white p-8 rounded-2xl shadow relative overflow-hidden">
                <div class="absolute top-0 right-0 bg-emerald-500 text-xs font-black px-3 py-1 rounded-bl-lg uppercase tracking-widest">
                    Turno Activo
                </div>
                <h3 class="text-lg font-bold text-gray-400 uppercase tracking-wider mb-6">Resumen del Sistema</h3>
                
                <div class="space-y-4">
                    <div class="flex justify-between border-b border-gray-700 pb-2">
                        <span>Fondo Inicial:</span>
                        <span class="font-bold">C$ {{ number_format($session->monto_apertura, 2) }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-700 pb-2">
                        <span>Ventas en Efectivo del Turno:</span>
                        <span class="font-bold text-emerald-400">+ C$ {{ number_format($ventasEfectivo, 2) }}</span>
                    </div>
                    <div class="flex justify-between pt-4 text-xl">
                        <span class="font-black text-gray-300">TOTAL TEÓRICO:</span>
                        <span class="font-black text-white">C$ {{ number_format($montoTeorico, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Formulario de Arqueo -->
            <div class="bg-white p-8 rounded-2xl shadow border border-red-100 relative">
                <h3 class="text-xl font-bold text-gray-900 mb-2">Arqueo Físico y Cierre</h3>
                <p class="text-sm text-gray-500 mb-6">Cuenta los billetes en caja e ingresa el monto total exacto.</p>

                <form action="{{ url('/caja/cerrar') }}" method="POST">
                    @csrf
                    <input type="hidden" name="monto_teorico" value="{{ $montoTeorico }}">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-bold text-gray-700">Total Físico Contado (C$)</label>
                        <input type="number" name="monto_fisico" step="0.01" required placeholder="0.00" class="mt-2 w-full border-gray-300 rounded-lg shadow-sm focus:border-red-500 focus:ring-red-500 text-3xl font-black text-center text-gray-900 py-4">
                    </div>
                    
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-4 rounded-lg shadow transition text-lg" onclick="return window.dispatchEvent(new CustomEvent('confirm', { detail: '¿Estás seguro de cerrar el turno y realizar el arqueo? Esta acción no se puede deshacer.' }))">
                        Cerrar Turno y Arqueo
                    </button>
                </form>
            </div>
        </div>
    @endif

    <!-- HISTORIAL DE TURNOS CERRADOS -->
    <div class="mt-12 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Bitácora de Turnos Pasados</h3>
        </div>
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-100 text-gray-600">
                <tr>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase">Cajero</th>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase">Apertura</th>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase">Cierre</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase">Diferencia (Arqueo)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
                @forelse($historial as $turno)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 font-bold text-gray-900">{{ $turno->user->name }}</td>
                        <td class="px-6 py-3 text-gray-600">
                            {{ \Carbon\Carbon::parse($turno->fecha_apertura)->format('d/m/y') }}<br>
                            <span class="text-xs font-bold">{{ \Carbon\Carbon::parse($turno->fecha_apertura)->format('h:i A') }}</span>
                        </td>
                        <td class="px-6 py-3 text-gray-600">
                            {{ \Carbon\Carbon::parse($turno->fecha_cierre)->format('d/m/y') }}<br>
                            <span class="text-xs font-bold">{{ \Carbon\Carbon::parse($turno->fecha_cierre)->format('h:i A') }}</span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            @if($turno->diferencia == 0)
                                <span class="bg-emerald-100 text-emerald-800 px-2 py-1 rounded text-xs font-bold">Cuadre Exacto</span>
                            @elseif($turno->diferencia < 0)
                                <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs font-bold">Faltante: C$ {{ number_format(abs($turno->diferencia), 2) }}</span>
                            @else
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-bold">Sobrante: C$ {{ number_format(abs($turno->diferencia), 2) }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-gray-500">No hay turnos cerrados registrados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>

@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.dispatchEvent(new CustomEvent('notify', { detail: '{{ session('success') }}' }));
    });
</script>
@endif
@endsection