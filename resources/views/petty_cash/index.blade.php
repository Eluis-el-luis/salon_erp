@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Control de Caja Chica</h2>
            <p class="text-gray-500 text-sm mt-1">Fondo para gastos menores, cafetería y transporte.</p>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- COLUMNA IZQUIERDA: Tarjeta de Fondo y Formulario -->
        <div class="space-y-6">
            
            <!-- Tarjeta de Saldo Visual -->
            <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl p-6 shadow-xl text-white relative overflow-hidden">
                <div class="absolute top-0 right-0 p-4 opacity-20">
                    <svg class="w-24 h-24" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                </div>
                <p class="text-gray-400 font-bold uppercase tracking-widest text-xs mb-1">Fondo Asignado: C$ {{ number_format($caja->monto_fondo, 2) }}</p>
                <h3 class="text-sm font-medium text-gray-300 mt-4">Saldo Disponible</h3>
                <p class="text-4xl font-black text-emerald-400">C$ {{ number_format($saldoDisponible, 2) }}</p>
                <div class="mt-4 flex justify-between text-xs text-gray-400 font-bold">
                    <span>Responsable: {{ $caja->responsable->name }}</span>
                    <span class="text-red-400">Gastado: C$ {{ number_format($totalGastado, 2) }}</span>
                </div>
            </div>

            <!-- Formulario de Gasto -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Registrar Nuevo Gasto</h3>
                
                <form action="{{ url('/caja-chica/gasto') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="caja_chica_id" value="{{ $caja->id }}">
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Categoría (Tipo de Gasto)</label>
                        <select name="tipo_gasto_id" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500">
                            <option value="">Seleccione...</option>
                            @foreach($tiposGasto as $tipo)
                                <option value="{{ $tipo->id }}">{{ $tipo->nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700">Descripción / Justificación</label>
                        <input type="text" name="descripcion" required placeholder="Ej. Compra de azúcar y café" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500">
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700">Monto (C$)</label>
                        <input type="number" name="monto" step="0.01" required min="1" max="{{ $saldoDisponible }}" placeholder="0.00" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 font-bold text-lg text-red-600">
                    </div>

                    <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded-lg shadow transition">
                        Guardar Gasto
                    </button>
                </form>
            </div>
        </div>

        <!-- COLUMNA DERECHA: Historial de Gastos -->
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Historial de Movimientos</h3>
            </div>
            
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider">Fecha</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider">Categoría</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider">Descripción</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider">Monto</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($movimientos as $mov)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-600 font-medium">
                                {{ \Carbon\Carbon::parse($mov->fecha)->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-3">
                                <span class="bg-gray-200 text-gray-800 py-1 px-2 rounded text-xs font-bold">{{ $mov->tipoGasto->nombre }}</span>
                            </td>
                            <td class="px-6 py-3 text-gray-900">{{ $mov->descripcion }}</td>
                            <td class="px-6 py-3 text-right font-black text-red-600">- C$ {{ number_format($mov->monto, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 font-medium">
                                No se han registrado gastos en esta caja chica.
                            </td>
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