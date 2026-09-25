@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Balance General</h1>
            <p class="page-subtitle">Situación financiera al corte del {{ \Carbon\Carbon::parse($fechaCorte)->format('d/m/Y') }}</p>
        </div>
        <form method="GET" class="flex items-end gap-3">
            <div>
                <label class="label">Fecha de Corte</label>
                <input type="date" name="fecha_corte" value="{{ $fechaCorte }}" class="input w-auto">
            </div>
            <button type="submit" class="btn btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Actualizar
            </button>
        </form>
    </div>

    <!-- Resumen Ejecutivo -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="card p-5 border-l-4 border-l-blue-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">TOTAL ACTIVO</p>
            <p class="text-2xl font-black text-blue-600">C$ {{ number_format($totalActivo, 2) }}</p>
        </div>
        <div class="card p-5 border-l-4 border-l-red-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">TOTAL PASIVO</p>
            <p class="text-2xl font-black text-red-600">C$ {{ number_format($totalPasivo, 2) }}</p>
        </div>
        <div class="card p-5 border-l-4 border-l-purple-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">TOTAL PATRIMONIO</p>
            <p class="text-2xl font-black text-purple-600">C$ {{ number_format($totalPatrimonio, 2) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- ACTIVO -->
        <div class="card">
            <div class="card-header bg-blue-600 text-white px-6 py-3">
                <h3 class="text-lg font-bold">ACTIVO (Clase 1)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Código</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cuenta</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($cuentasActivoConSaldo as $cuenta)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-left text-xs font-bold text-gray-900">{{ $cuenta->codigo }}</td>
                                <td class="px-6 py-3 text-left text-gray-700">{{ $cuenta->nombre }}</td>
                                <td class="px-6 py-3 text-right font-bold text-blue-600">C$ {{ number_format($cuenta->saldo_calculado, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-gray-500">No hay cuentas de activo con saldo</td>
                            </tr>
                        @endforelse
                        <tr class="bg-blue-50 font-bold">
                            <td colspan="2" class="px-6 py-3 text-right">TOTAL ACTIVO</td>
                            <td class="text-right font-black text-blue-600">C$ {{ number_format($totalActivo, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PASIVO Y PATRIMONIO -->
        <div class="space-y-6">
            <!-- PASIVO -->
            <div class="card">
                <div class="card-header bg-red-600 text-white px-6 py-3">
                    <h3 class="text-lg font-bold">PASIVO (Clase 2)</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Código</th>
                                <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cuenta</th>
                                <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse($cuentasPasivoConSaldo as $cuenta)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-left text-xs font-bold text-gray-900">{{ $cuenta->codigo }}</td>
                                    <td class="px-6 py-3 text-left text-gray-700">{{ $cuenta->nombre }}</td>
                                    <td class="px-6 py-3 text-right font-bold text-red-600">C$ {{ number_format($cuenta->saldo_calculado, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-8 text-center text-gray-500">No hay cuentas de pasivo con saldo</td>
                                </tr>
                            @endforelse
                            <tr class="bg-red-50 font-bold">
                                <td colspan="2" class="px-6 py-3 text-right">TOTAL PASIVO</td>
                                <td class="text-right font-black text-red-600">C$ {{ number_format($totalPasivo, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- PATRIMONIO -->
                <div class="card mt-6">
                    <div class="card-header bg-purple-600 text-white px-6 py-3">
                        <h3 class="text-lg font-bold">PATRIMONIO (Clase 3)</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Código</th>
                                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cuenta</th>
                                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Saldo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @forelse($cuentasPatrimonioConSaldo as $cuenta)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-3 text-left text-xs font-bold text-gray-900">{{ $cuenta->codigo }}</td>
                                        <td class="px-6 py-3 text-left text-gray-700">{{ $cuenta->nombre }}</td>
                                        <td class="px-6 py-3 text-right font-bold text-purple-600">C$ {{ number_format($cuenta->saldo_calculado, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="px-6 py-8 text-center text-gray-500">No hay cuentas de patrimonio con saldo</td>
                                    </tr>
                                @endforelse
                                <tr class="bg-purple-50 font-bold">
                                    <td colspan="2" class="px-6 py-3 text-right">TOTAL PATRIMONIO</td>
                                    <td class="text-right font-black text-purple-600">C$ {{ number_format($totalPatrimonio, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Verificación de Cuadre -->
                <div class="card bg-gradient-to-r from-blue-50 to-purple-50 border-2 border-blue-200">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Verificación de Cuadre (Activo = Pasivo + Patrimonio)</div>
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="bg-blue-100 p-4 rounded-lg">
                                <p class="text-xs font-bold text-blue-800 uppercase">TOTAL ACTIVO</p>
                                <p class="text-2xl font-black text-blue-800">C$ {{ number_format($totalActivo, 2) }}</p>
                            </div>
                            <div class="bg-red-100 p-4 rounded-lg">
                                <p class="text-xs font-bold text-red-800 uppercase">TOTAL PASIVO</p>
                                <p class="text-2xl font-black text-red-600">C$ {{ number_format($totalPasivo, 2) }}</p>
                            </div>
                            <div class="bg-purple-100 p-4 rounded-lg">
                                <p class="text-xs font-bold text-purple-800 uppercase">TOTAL PATRIMONIO</p>
                                <p class="text-2xl font-black text-purple-600">C$ {{ number_format($totalPatrimonio, 2) }}</p>
                            </div>
                        </div>
                        <div class="mt-4 text-center">
                            <div class="inline-flex items-center gap-3 px-4 py-2 rounded-lg {{ abs($totalActivo - ($totalPasivo + $totalPatrimonio)) < 0.01 ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ abs($totalActivo - ($totalPasivo + $totalPatrimonio)) < 0.01 ? 'M5 13l4 4L19 7' : 'M6 18L18 6M6 6l12 12' }}"></path></svg>
                                <span class="font-bold text-lg">
                                    {{ abs($totalActivo - ($totalPasivo + $totalPatrimonio)) < 0.01 ? 'CUADRADO ✓' : 'DESCUADRADO ✗' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection