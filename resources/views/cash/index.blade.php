@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="card p-6">
        <h2 class="page-title">Control de Caja</h2>
        <p class="page-subtitle">Apertura, cierre y arqueo físico del turno actual.</p>
    </div>

    @if(isset($errors) && $errors->any())
        <div class="card p-4 bg-red-50 border-red-200">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-semibold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if(!$sesion)
        <!-- ===== APERTURA DE TURNO ===== -->
        <div class="card p-8 max-w-md mx-auto">
            <div class="flex items-center gap-4 mb-6">
                <div class="p-3 rounded-full bg-emerald-100 text-emerald-700">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path></svg>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-gray-900">Abrir Turno</h3>
                    <p class="text-sm text-gray-500">Inicia tu sesión de caja para comenzar a cobrar.</p>
                </div>
            </div>

            <form action="{{ url('/caja/abrir') }}" method="POST" class="space-y-4">
                @csrf

                @if($puedeGestionarMontos)
                    <div>
                        <label class="label" for="monto_apertura">Fondo Inicial (C$)</label>
                        <input id="monto_apertura" type="number" name="monto_apertura" step="0.01" min="0" value="0.00" class="input text-lg font-bold">
                        <p class="text-xs text-gray-400 mt-1">Administrador / Contador: ingresa el fondo manualmente.</p>
                    </div>
                @else
                    <input type="hidden" name="monto_apertura" value="{{ $autoApertura }}">
                    <div class="flex items-center justify-between bg-gray-50 border border-gray-200 rounded-lg px-4 py-3">
                        <span class="text-sm text-gray-600 font-medium">Fondo inicial automático (último cierre)</span>
                        <span class="text-lg font-black text-emerald-700">C$ {{ number_format($autoApertura, 2) }}</span>
                    </div>
                    <p class="text-xs text-gray-400 -mt-2">El fondo se calcula automáticamente según tu último arqueo.</p>
                @endif

                <button type="submit" class="btn btn-primary w-full py-3">Iniciar Turno de Caja</button>
            </form>
        </div>

    @else
        <!-- ===== ARQUEO Y CIERRE ===== -->
        @php
            $montoTeorico = round($sesion->monto_apertura + $ventasEfectivo, 2);
        @endphp

        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-lg">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path></svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700 font-medium">
                        Turno abierto por <span class="font-black">{{ $sesion->usuario->name }}</span> el
                        <span class="font-black">{{ \Carbon\Carbon::parse($sesion->fecha_apertura)->format('d/m/Y') }}</span> a las
                        <span class="font-black">{{ \Carbon\Carbon::parse($sesion->fecha_apertura)->format('h:i A') }}</span>.
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Resumen del Sistema -->
            <div class="bg-gray-900 text-white p-6 rounded-xl shadow relative overflow-hidden">
                <div class="absolute top-0 right-0 bg-emerald-500 text-xs font-black px-3 py-1 rounded-bl-lg uppercase tracking-widest">Turno Activo</div>
                <h3 class="text-lg font-bold text-gray-400 uppercase tracking-wider mb-6">Resumen del Sistema</h3>
                <div class="space-y-3">
                    <div class="flex justify-between border-b border-gray-700 pb-2">
                        <span>Fondo Inicial:</span>
                        <span class="font-bold">C$ {{ number_format($sesion->monto_apertura, 2) }}</span>
                    </div>
                    <div class="flex justify-between border-b border-gray-700 pb-2">
                        <span>Ventas en Efectivo del Turno:</span>
                        <span class="font-bold text-emerald-400">+ C$ {{ number_format($ventasEfectivo, 2) }}</span>
                    </div>
                    <div class="flex justify-between pt-3 text-xl">
                        <span class="font-black text-gray-300">TOTAL TEÓRICO:</span>
                        <span class="font-black text-white">C$ {{ number_format($montoTeorico, 2) }}</span>
                    </div>
                </div>
            </div>

            <!-- Formulario de Arqueo -->
            <div class="card p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-2">Arqueo y Cierre</h3>
                <p class="text-sm text-gray-500 mb-6">
                    @if($puedeGestionarMontos)
                        Cuenta los billetes en caja e ingresa el monto total exacto.
                    @else
                        El cierre se calculará automáticamente con el total teórico.
                    @endif
                </p>

                <form action="{{ url('/caja/cerrar') }}" method="POST" class="space-y-4">
                    @csrf

                    @if($puedeGestionarMontos)
                        <div>
                            <label class="label" for="monto_fisico">Total Físico Contado (C$)</label>
                            <input id="monto_fisico" type="number" name="monto_fisico" step="0.01" min="0" value="{{ $montoTeorico }}" class="input text-2xl font-black text-center">
                            <p class="text-xs text-gray-400 mt-1">Teórico: C$ {{ number_format($montoTeorico, 2) }}</p>
                        </div>
                    @else
                        <input type="hidden" name="monto_fisico" value="{{ $montoTeorico }}">
                        <div class="flex items-center justify-between bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3">
                            <span class="text-sm text-gray-600 font-medium">Monto de cierre calculado</span>
                            <span class="text-lg font-black text-emerald-700">C$ {{ number_format($montoTeorico, 2) }}</span>
                        </div>
                        <p class="text-xs text-gray-400">Se cerrará al total teórico sin diferencia.</p>
                    @endif

                    <button type="submit" class="btn btn-danger w-full py-3"
                            onclick="return window.dispatchEvent(new CustomEvent('confirm', { detail: '¿Estás seguro de cerrar el turno y realizar el arqueo? Esta acción no se puede deshacer.' }))">
                        Cerrar Turno y Arqueo
                    </button>
                </form>
            </div>
        </div>
    @endif

    <!-- BITÁCORA DE TURNOS CERRADOS -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Bitácora de Turnos Pasados</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-white">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cajero</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Apertura</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cierre</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Fondo</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Diferencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($historial as $turno)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 font-bold text-gray-900">{{ $turno->usuario->name }}</td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ \Carbon\Carbon::parse($turno->fecha_apertura)->format('d/m/y') }}<br>
                                <span class="text-xs font-bold">{{ \Carbon\Carbon::parse($turno->fecha_apertura)->format('h:i A') }}</span>
                            </td>
                            <td class="px-6 py-3 text-gray-600">
                                {{ \Carbon\Carbon::parse($turno->fecha_cierre)->format('d/m/y') }}<br>
                                <span class="text-xs font-bold">{{ \Carbon\Carbon::parse($turno->fecha_cierre)->format('h:i A') }}</span>
                            </td>
                            <td class="px-6 py-3 text-right text-gray-900 font-semibold">C$ {{ number_format($turno->monto_fisico ?? 0, 2) }}</td>
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
                            <td colspan="5" class="px-6 py-6 text-center text-gray-500">No hay turnos cerrados registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
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