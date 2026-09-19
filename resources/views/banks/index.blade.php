@extends('layouts.app')

@section('content')
<div x-data="{ modalDeposito: false, cuentaId: '', bancoNombre: '' }" class="max-w-7xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="page-title">Gestión Bancaria</h2>
            <p class="text-gray-500 text-sm mt-1">Control de cuentas en BAC, LAFISE y depósitos del día.</p>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($cuentas as $cuenta)
            <!-- Tarjeta Estilo Bancaria -->
            <div class="bg-gradient-to-br {{ $cuenta->banco->codigo == 'BAC' ? 'from-red-700 to-red-900' : 'from-green-700 to-green-900' }} rounded-2xl p-6 shadow-lg text-white relative overflow-hidden transition transform hover:scale-105">
                <div class="absolute top-0 right-0 p-4 opacity-20">
                    <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                </div>
                
                <h3 class="text-xl font-black uppercase tracking-widest mb-1">{{ $cuenta->banco->nombre }}</h3>
                <p class="text-gray-200 text-sm font-medium mb-6">Cuenta {{ $cuenta->tipo_cuenta }} • {{ $cuenta->numero_cuenta }}</p>
                
                <p class="text-xs text-gray-300 uppercase tracking-widest font-bold">Saldo Actual</p>
                <p class="text-3xl font-black mb-6">C$ {{ number_format($cuenta->saldo_actual, 2) }}</p>

                <div class="flex justify-between items-center border-t border-white border-opacity-20 pt-4">
                    <button @click="modalDeposito = true; cuentaId = {{ $cuenta->id }}; bancoNombre = '{{ $cuenta->banco->nombre }} - {{ $cuenta->numero_cuenta }}'" 
                            class="bg-white text-gray-900 hover:bg-gray-100 font-bold py-2 px-4 rounded-lg shadow text-sm transition">
                        Depositar Efectivo
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Modal de Depósito -->
    <div x-show="modalDeposito" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto overflow-x-hidden bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="modalDeposito = false">
            
            <h3 class="text-xl font-bold text-gray-900 mb-2">Depositar a Banco</h3>
            <p class="text-sm text-gray-500 mb-4">Destino: <span class="font-bold text-gray-800" x-text="bancoNombre"></span></p>
            
            @if(!$cajaActiva)
                <div class="bg-red-50 text-red-700 p-4 rounded-lg text-sm font-bold border border-red-200">
                    No tienes una caja abierta actualmente. Abre tu turno en el Control de Caja antes de depositar dinero.
                </div>
                <div class="mt-4 text-right">
                    <button type="button" @click="modalDeposito = false" class="bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg">Cerrar</button>
                </div>
            @else
                <form action="{{ url('/bancos/depositar') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="cuenta_destino_id" x-model="cuentaId">
                    <input type="hidden" name="caja_origen_id" value="{{ $cajaActiva->id }}">
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Efectivo a Depositar (C$)</label>
                        <input type="number" name="monto" step="0.01" required min="1" class="w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 text-2xl font-black text-center text-gray-900 py-3">
                        <p class="text-xs text-gray-500 text-center mt-2">Este dinero saldrá de tu Turno de Caja #{{ $cajaActiva->id }}</p>
                    </div>

                    <div class="pt-4 flex justify-end space-x-3">
                        <button type="button" @click="modalDeposito = false" class="bg-gray-200 text-gray-700 hover:bg-gray-300 font-bold py-2 px-4 rounded-lg transition">Cancelar</button>
                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg shadow transition">Confirmar Depósito</button>
                    </div>
                </form>
            @endif
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