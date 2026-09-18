@extends('layouts.app')

@section('content')
<div x-data="{ modalRetiro: false }" class="max-w-7xl mx-auto space-y-6">

    <!-- Cabecera -->
    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Retiros del Propietario</h2>
            <p class="text-gray-500 text-sm mt-1">
                Los retiros del dueño se registran contra <span class="font-semibold text-emerald-700">Patrimonio (3.3)</span>, nunca como gasto operativo.
            </p>
        </div>
        <button @click="modalRetiro = true" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-5 rounded-lg shadow transition">
            + Registrar Retiro
        </button>
    </div>

    <!-- Modal de Registro -->
    <div x-show="modalRetiro" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto overflow-x-hidden bg-gray-900 bg-opacity-75">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="modalRetiro = false">
            <h3 class="text-xl font-bold text-gray-900 mb-4">Registrar Retiro de Propietario</h3>

            @if ($errors->any())
                <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm font-medium">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form action="{{ url('/retiros') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700">Fecha</label>
                    <input type="date" name="fecha" value="{{ \Carbon\Carbon::today()->toDateString() }}" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700">Concepto</label>
                    <input type="text" name="concepto" required placeholder="Ej. Retiro personal de caja" class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Monto</label>
                        <input type="number" step="0.01" min="0.01" name="monto" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Moneda</label>
                        <select name="moneda" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="nio">Córdobas (C$)</option>
                            <option value="usd">Dólares (USD)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700">Método de Pago</label>
                    <select name="metodo_pago" required class="mt-1 w-full border-gray-300 rounded-lg shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="efectivo">Caja (Efectivo)</option>
                        <option value="banco">Transferencia / Banco</option>
                    </select>
                </div>

                <div class="pt-4 flex justify-end space-x-3">
                    <button type="button" @click="modalRetiro = false" class="bg-gray-200 text-gray-700 font-bold py-2 px-4 rounded-lg">Cancelar</button>
                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg">Guardar Retiro</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Retiros -->
    <div class="bg-white shadow border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-emerald-800 text-white">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Fecha</th>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Concepto</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Monto</th>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Método</th>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Registrado por</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($retiros as $retiro)
                    <tr class="hover:bg-emerald-50 transition">
                        <td class="px-6 py-3 text-gray-900 font-semibold whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($retiro->fecha)->format('d/m/Y') }}
                        </td>
                        <td class="px-6 py-3 text-gray-700">{{ $retiro->concepto }}</td>
                        <td class="px-6 py-3 text-right text-gray-900 font-bold whitespace-nowrap">
                            {{ $retiro->moneda === 'usd' ? 'US$ ' : 'C$ ' }}{{ number_format($retiro->monto, 2) }}
                        </td>
                        <td class="px-6 py-3 text-gray-700">{{ ucfirst($retiro->metodo_pago) }}</td>
                        <td class="px-6 py-3 text-gray-700">{{ $retiro->usuario->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 font-medium">
                            No hay retiros registrados. Los retiros del propietario se contabilizan contra Patrimonio.
                        </td>
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