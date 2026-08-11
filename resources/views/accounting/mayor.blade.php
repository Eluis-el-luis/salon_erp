@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Libro Mayor</h2>
            <p class="text-gray-500 text-sm mt-1">Saldos consolidados por cuenta contable.</p>
        </div>
        <a href="{{ url('/contabilidad') }}" class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
            ← Volver al Libro Diario
        </a>
    </div>

    <div class="bg-white shadow border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-800 text-white">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Código</th>
                    <th scope="col" class="px-6 py-4 text-left font-black tracking-wider uppercase">Nombre de la Cuenta</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Total Debe</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Total Haber</th>
                    <th scope="col" class="px-6 py-4 text-right font-black tracking-wider uppercase">Saldo Final</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($cuentas as $cuenta)
                    @php
                        $totalDebe = $cuenta->detalles->sum('debe');
                        $totalHaber = $cuenta->detalles->sum('haber');
                        
                        // Cálculo del saldo según su naturaleza
                        if($cuenta->naturaleza == 'deudora') {
                            $saldo = $totalDebe - $totalHaber;
                            $colorSaldo = 'text-blue-600';
                        } else {
                            $saldo = $totalHaber - $totalDebe;
                            $colorSaldo = 'text-emerald-600';
                        }
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-3 whitespace-nowrap text-gray-900 font-bold">{{ $cuenta->codigo }}</td>
                        <td class="px-6 py-3 text-gray-900 font-medium">{{ $cuenta->nombre }}</td>
                        <td class="px-6 py-3 text-right text-gray-600 font-medium">C$ {{ number_format($totalDebe, 2) }}</td>
                        <td class="px-6 py-3 text-right text-gray-600 font-medium">C$ {{ number_format($totalHaber, 2) }}</td>
                        <td class="px-6 py-3 text-right font-black {{ $colorSaldo }}">C$ {{ number_format($saldo, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 font-medium">
                            No hay movimientos consolidados en el Libro Mayor.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection