@extends('layouts.app')

@section('content')
<div x-data="{ modalAbono: false, cuentaId: '', saldoMaximo: 0, proveedorNombre: '' }" class="max-w-7xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Cuentas por Pagar (CxP)</h2>
            <p class="text-gray-500 text-sm mt-1">Control de facturas y deudas pendientes con proveedores.</p>
        </div>
    </div>

    <!-- Mensajes de Error de Validación -->
    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white shadow border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-800 text-white">
                <tr>
                    <th class="px-6 py-4 text-left font-black tracking-wider uppercase">Proveedor</th>
                    <th class="px-6 py-4 text-center font-black tracking-wider uppercase">Vencimiento</th>
                    <th class="px-6 py-4 text-right font-black tracking-wider uppercase">Deuda Original</th>
                    <th class="px-6 py-4 text-right font-black tracking-wider uppercase">Saldo Pendiente</th>
                    <th class="px-6 py-4 text-center font-black tracking-wider uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($cuentas as $cuenta)
                    @php
                        // Alerta visual si la factura ya venció
                        $vencida = \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->isPast();
                        $colorFecha = $vencida ? 'text-red-600 font-black' : 'text-gray-600';
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 font-bold text-gray-900">{{ $cuenta->provider->name }}</td>
                        <td class="px-6 py-4 text-center {{ $colorFecha }}">
                            {{ \Carbon\Carbon::parse($cuenta->fecha_vencimiento)->format('d/m/Y') }}
                            @if($vencida) <br><span class="text-xs bg-red-100 px-2 rounded text-red-800">Vencida</span> @endif
                        </td>
                        <td class="px-6 py-4 text-right text-gray-500">C$ {{ number_format($cuenta->monto_original, 2) }}</td>
                        <td class="px-6 py-4 text-right text-lg font-black text-emerald-600">C$ {{ number_format($cuenta->saldo_pendiente, 2) }}</td>
                        <td class="px-6 py-4 text-center">
                            <button @click="modalAbono = true; cuentaId = {{ $cuenta->id }}; saldoMaximo = {{ $cuenta->saldo_pendiente }}; proveedorNombre = '{{ $cuenta->provider->name }}'" 
                                    class="bg-gray-900 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg shadow text-xs transition">
                                Registrar Abono
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 font-medium">
                            Excelente, no hay deudas pendientes con proveedores.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Modal de Abono -->
    <div x-show="modalAbono" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto overflow-x-hidden bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="modalAbono = false">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Abonar a Factura</h3>
            <p class="text-sm text-gray-500 mb-4">Proveedor: <span class="font-bold text-gray-800" x-text="proveedorNombre"></span></p>
            
            <form action="{{ url('/cuentas-por-pagar/abonar') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="cuenta_por_pagar_id" x-model="cuentaId">
                
                <div>
                    <label class="block text-sm font-bold text-gray-700">Monto a Pagar (C$)</label>
                    <input type="number" name="monto" step="0.01" required :max="saldoMaximo" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 text-lg font-bold">
                    <p class="text-xs text-gray-500 mt-1">Saldo pendiente máximo: C$ <span x-text="saldoMaximo"></span></p>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700">Origen de los Fondos</label>
                    <select name="forma_pago" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500">
                        <option value="efectivo">Caja / Efectivo</option>
                        <option value="banco">Transferencia Bancaria</option>
                    </select>
                </div>

                <div class="pt-4 flex justify-end space-x-3">
                    <button type="button" @click="modalAbono = false" class="bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg">Cancelar</button>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg">Guardar Abono</button>
                </div>
            </form>
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