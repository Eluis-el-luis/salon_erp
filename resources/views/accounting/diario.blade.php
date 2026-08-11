@extends('layouts.app')

@section('content')
<div x-data="{ modalGasto: false }" class="max-w-7xl mx-auto space-y-6">

    <!-- Cabecera y Botón de Gastos -->
    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Libro Diario</h2>
            <p class="text-gray-500 text-sm mt-1">Registro cronológico de operaciones contables.</p>
        </div>
        <button @click="modalGasto = true" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2.5 px-5 rounded-lg shadow transition">
            + Registrar Egreso / Gasto
        </button>
    </div>

    <!-- Modal de Registro de Gastos -->
    <div x-show="modalGasto" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto overflow-x-hidden bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="modalGasto = false">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Registrar Nuevo Gasto</h3>
            
            <form action="{{ url('/contabilidad/gasto') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700">Concepto o Descripción</label>
                    <input type="text" name="descripcion" required placeholder="Ej. Pago a modelo Celine" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700">Clasificación Contable</label>
                    <select name="cuenta_id" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">Seleccione el tipo de gasto...</option>
                        @foreach($cuentasGasto as $cuenta)
                            <option value="{{ $cuenta->id }}">{{ $cuenta->codigo }} - {{ $cuenta->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Monto (C$)</label>
                        <input type="number" step="0.01" name="monto" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Método de Pago</label>
                        <select name="metodo_pago" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="efectivo">Caja (Efectivo)</option>
                            <option value="banco">Transferencia / Banco</option>
                        </select>
                    </div>
                </div>

                <div class="pt-4 flex justify-end space-x-3">
                    <button type="button" @click="modalGasto = false" class="bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg">Cancelar</button>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg">Guardar Gasto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FORMATO DE LIBRO DIARIO CLÁSICO -->
    <div class="bg-white shadow border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-emerald-800 text-white">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Fecha / Folio</th>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Concepto o Descripción</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Debe</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Haber</th>
                </tr>
            </thead>
            
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($asientos as $asiento)
                    
                    @php 
                        $totalDebe = 0; 
                        $totalHaber = 0; 
                    @endphp

                    <!-- Cabecera del Asiento -->
                    <tr class="bg-gray-50 border-t-4 border-gray-300">
                        <td class="px-6 py-3 whitespace-nowrap text-gray-900 font-bold">
                            {{ \Carbon\Carbon::parse($asiento->fecha)->format('d/m/Y') }} <br>
                            <span class="text-xs text-gray-500">{{ $asiento->numero_asiento }}</span>
                        </td>
                        <td class="px-6 py-3 text-gray-700 italic font-medium" colspan="3">
                            {{ $asiento->concepto }}
                        </td>
                    </tr>

                    <!-- Líneas del Asiento (Debe/Haber) -->
                    @foreach($asiento->detalles as $detalle)
                        @php 
                            $totalDebe += $detalle->debe; 
                            $totalHaber += $detalle->haber; 
                        @endphp
                        <tr class="hover:bg-emerald-50 transition">
                            <td class="px-6 py-2"></td>
                            <td class="px-6 py-2 text-gray-900 font-bold flex items-center">
                                <span class="text-xs bg-gray-200 px-2 py-1 rounded mr-3">{{ $detalle->cuenta->codigo }}</span>
                                {{ $detalle->cuenta->nombre }}
                            </td>
                            <td class="px-6 py-2 text-right text-gray-900 font-medium">
                                {{ $detalle->debe > 0 ? 'C$ ' . number_format($detalle->debe, 2) : '' }}
                            </td>
                            <td class="px-6 py-2 text-right text-gray-900 font-medium">
                                {{ $detalle->haber > 0 ? 'C$ ' . number_format($detalle->haber, 2) : '' }}
                            </td>
                        </tr>
                    @endforeach

                    <!-- Sumas Iguales -->
                    <tr class="border-t border-dashed border-gray-300 bg-gray-50">
                        <td class="px-6 py-2"></td>
                        <td class="px-6 py-2 text-right text-gray-900 font-black">SUMAS IGUALES</td>
                        <td class="px-6 py-2 text-right text-emerald-700 font-black border-b-2 border-emerald-500">C$ {{ number_format($totalDebe, 2) }}</td>
                        <td class="px-6 py-2 text-right text-emerald-700 font-black border-b-2 border-emerald-500">C$ {{ number_format($totalHaber, 2) }}</td>
                    </tr>

                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-500 font-medium">
                            No hay registros contables en este periodo. Ve al POS a realizar una venta.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>

<!-- Si la sesión retorna éxito, disparamos tu notificación Toast de Alpine -->
@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.dispatchEvent(new CustomEvent('notify', { detail: '{{ session('success') }}' }));
    });
</script>
@endif

@endsection