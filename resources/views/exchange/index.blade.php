@extends('layouts.app')

@section('content')
<div x-data="exchangeManager()" class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="card p-6">
        <h2 class="page-title">Mesa de Cambio (Divisas)</h2>
        <p class="page-subtitle">Compra y venta de Dólares (USD) y cálculo de costo promedio ponderado.</p>
    </div>

    <!-- Alertas -->
    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-sm">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- COLUMNA IZQUIERDA: Bóveda y Saldo -->
        <div class="space-y-6">
            <!-- Tarjeta de Saldo USD -->
            <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-2xl p-6 shadow-xl text-white relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-20">
                    <svg class="w-24 h-24 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                
                <p class="text-gray-400 font-bold uppercase tracking-widest text-xs mb-1">Fondo Actual en Caja</p>
                <h3 class="text-4xl font-black text-emerald-400 mb-6">$ {{ number_format($saldoUsd, 2) }} <span class="text-lg text-emerald-600">USD</span></h3>
                
                <div class="border-t border-slate-700 pt-4">
                    <p class="text-xs text-slate-400 uppercase tracking-widest font-bold mb-1">Costo Promedio Ponderado</p>
                    <p class="text-xl font-bold text-white">C$ {{ number_format($costoPromedio, 6) }}</p>
                </div>
            </div>
        </div>

        <!-- COLUMNA DERECHA: Formulario de Operación -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 h-full">
                <h3 class="text-lg font-black text-gray-900 border-b pb-3 mb-5">Registrar Operación</h3>
                
                @if(!$cajaActiva)
                    <div class="bg-red-50 text-red-700 p-4 rounded-lg text-sm font-bold border border-red-200 flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        Debes abrir tu turno en el Control de Caja antes de operar en la Mesa de Cambio.
                    </div>
                @else
                    <form action="{{ url('/mesa-cambio/operar') }}" method="POST" class="space-y-6">
                        @csrf
                        <input type="hidden" name="caja_origen_id" value="{{ $cajaActiva->id }}">
                        
                        <!-- Tabs de Compra / Venta -->
                        <div class="flex p-1 bg-gray-100 rounded-lg">
                            <label class="flex-1 text-center cursor-pointer">
                                <input type="radio" name="tipo" value="compra" x-model="tipo" class="sr-only">
                                <div class="py-2 rounded-md font-bold text-sm transition-all" :class="tipo === 'compra' ? 'bg-white shadow-sm text-emerald-700' : 'text-gray-500 hover:text-gray-700'">
                                    SALÓN COMPRA USD
                                </div>
                            </label>
                            <label class="flex-1 text-center cursor-pointer">
                                <input type="radio" name="tipo" value="venta" x-model="tipo" class="sr-only">
                                <div class="py-2 rounded-md font-bold text-sm transition-all" :class="tipo === 'venta' ? 'bg-white shadow-sm text-blue-700' : 'text-gray-500 hover:text-gray-700'">
                                    SALÓN VENDE USD
                                </div>
                            </label>
                        </div>

                        <!-- Inputs de la Operación -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Monto en Dólares (USD) <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 font-bold">$</span>
                                    </div>
                                    <input type="number" step="0.01" min="0.01" name="monto_usd" x-model.number="montoUsd" required class="pl-8 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500 font-black text-lg py-3 text-gray-900" placeholder="0.00">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Tasa de Cambio (C$) <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 font-bold">C$</span>
                                    </div>
                                    <input type="number" step="0.0001" min="1" name="tasa_aplicada" x-model.number="tasa" required class="pl-10 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500 font-black text-lg py-3 text-gray-900" placeholder="36.5000">
                                </div>
                            </div>
                        </div>

                        <!-- Calculadora Visual en Tiempo Real -->
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex justify-between items-center">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase" x-text="tipo === 'compra' ? 'El cajero entrega (NIO):' : 'El cajero recibe (NIO):'"></p>
                                <p class="text-2xl font-black" :class="tipo === 'compra' ? 'text-red-600' : 'text-emerald-600'">C$ <span x-text="totalConversion"></span></p>
                            </div>
                            <button type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-3 px-6 rounded-lg shadow transition">
                                Procesar Operación
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <!-- Historial de Operaciones -->
    <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden mt-8">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">Historial de Operaciones (Kárdex)</h3>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-white text-gray-500">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs">Fecha</th>
                        <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs">Tipo</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs">Tasa Aplicada</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs">Monto USD</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs">Equivalente NIO</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($operaciones as $op)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-3 text-gray-600 font-medium">
                                {{ \Carbon\Carbon::parse($op->fecha)->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-3 text-center">
                                @if($op->tipo == 'compra')
                                    <span class="bg-emerald-100 text-emerald-800 py-1 px-3 rounded-full text-xs font-bold uppercase tracking-wider">Compra</span>
                                @else
                                    <span class="bg-blue-100 text-blue-800 py-1 px-3 rounded-full text-xs font-bold uppercase tracking-wider">Venta</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right text-gray-900 font-bold">
                                {{ number_format($op->tasa_aplicada, 4) }}
                            </td>
                            <td class="px-6 py-3 text-right font-black {{ $op->tipo == 'compra' ? 'text-emerald-600' : 'text-red-500' }}">
                                {{ $op->tipo == 'compra' ? '+' : '-' }} $ {{ number_format($op->tipo == 'compra' ? $op->monto_origen : $op->monto_destino, 2) }}
                            </td>
                            <td class="px-6 py-3 text-right font-black text-gray-700">
                                C$ {{ number_format($op->tipo == 'compra' ? $op->monto_destino : $op->monto_origen, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500 font-medium text-sm">
                                No se han registrado operaciones de cambio de divisas aún.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function exchangeManager() {
        return {
            tipo: 'compra',
            montoUsd: '',
            tasa: '',
            
            get totalConversion() {
                let monto = parseFloat(this.montoUsd) || 0;
                let tasa = parseFloat(this.tasa) || 0;
                return (monto * tasa).toFixed(2);
            }
        }
    }
</script>

@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.dispatchEvent(new CustomEvent('notify', { detail: '{{ session('success') }}' }));
    });
</script>
@endif
@endsection