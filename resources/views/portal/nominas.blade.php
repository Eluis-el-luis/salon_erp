@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="card p-6">
        <h2 class="page-title">Mis Nóminas</h2>
        <p class="page-subtitle">Historial de liquidaciones de nómina.</p>
    </div>

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-white">
                <tr>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Período</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Salario Base</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Comisiones</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Deducciones</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Neto</th>
                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Estado</th>
                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @forelse($nominas as $nomina)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-medium">
                            {{ \Carbon\Carbon::parse($nomina->start_date)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($nomina->end_date)->format('d/m/Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-gray-900 font-bold">C$ {{ number_format($nomina->active_salary, 2) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-gray-900 font-bold">
                            C$ {{ number_format($nomina->services_commission + $nomina->products_commission, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-red-600 font-bold">
                            - C$ {{ number_format($nomina->retenciones_totales + $nomina->salary_advances + $nomina->loan_payments, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right font-black text-emerald-600 text-lg">
                            C$ {{ number_format($nomina->total_to_pay, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Pagada</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <a href="{{ route('nomina.ticket', $nomina->id) }}" target="_blank" class="text-emerald-600 hover:text-emerald-800 font-bold text-sm">Ver Colilla</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">No hay nóminas registradas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        {{ $nominas->links() }}
    </div>
</div>
@endsection