@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="card p-6">
        <h2 class="page-title">Mis Adelantos y Cuotas</h2>
        <p class="page-subtitle">Control de cuotas pendientes y pagadas.</p>
    </div>

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-white">
                <tr>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha Vencimiento</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Monto Cuota</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Monto Pagado</th>
                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Estado</th>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Adelanto Original</th>
                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Cuota #</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @forelse($cuotas as $cuota)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600 font-medium">{{ \Carbon\Carbon::parse($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-gray-900 font-bold">C$ {{ number_format($cuota->monto, 2) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-emerald-600 font-bold">C$ {{ number_format($cuota->monto_pagado, 2) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($cuota->estado === 'pagada')
                                <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Pagada</span>
                            @elseif($cuota->estado === 'parcial')
                                <span class="px-2 py-1 rounded text-xs font-bold bg-blue-100 text-blue-800">Parcial</span>
                            @else
                                <span class="px-2 py-1 rounded text-xs font-bold bg-amber-100 text-amber-800">Pendiente</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-700">{{ $cuota->adelanto->description ?? 'Adelanto #' . $cuota->adelanto_id }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-center font-bold text-gray-900">{{ $cuota->numero_cuota }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 font-medium">No hay cuotas de adelantos registradas.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        {{ $cuotas->links() }}
    </div>
</div>
@endsection