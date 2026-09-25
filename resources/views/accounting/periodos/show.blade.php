@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">{{ $periodo->nombre }}</h1>
            <p class="page-subtitle">
                {{ \Carbon\Carbon::parse($periodo->fecha_inicio)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($periodo->fecha_fin)->format('d/m/Y') }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="px-3 py-1 rounded-full text-xs font-bold {{ $periodo->estado === 'abierto' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                {{ ucfirst($periodo->estado) }}
            </span>
            <a href="{{ route('periodos.index') }}" class="btn btn-secondary">Volver a Lista</a>
        </div>
    </div>

    <!-- Resumen del Periodo -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-5 border-l-4 border-l-emerald-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Asientos</p>
            <p class="text-2xl font-black text-gray-900">{{ $periodo->asientos->count() }}</p>
        </div>
        <div class="card p-5 border-l-4 border-l-red-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Debe</p>
            <p class="text-2xl font-black text-red-600">C$ {{ number_format($totalDebe, 2) }}</p>
        </div>
        <div class="card p-5 border-l-4 border-l-emerald-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Haber</p>
            <p class="text-2xl font-black text-emerald-600">C$ {{ number_format($totalHaber, 2) }}</p>
        </div>
        <div class="card p-5 border-l-4 border-l-blue-600">
            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Diferencia</p>
            <p class="text-2xl font-black {{ abs($totalDebe - $totalHaber) < 0.01 ? 'text-emerald-600' : 'text-red-600' }}">
                C$ {{ number_format(abs($totalDebe - $totalHaber), 2) }}
            </p>
        </div>
    </div>

    <!-- Acción de cierre -->
    @if($periodo->estado === 'abierto')
        <div class="flex gap-3 mb-6">
            <form action="{{ route('periodos.cierre', $periodo) }}" method="POST" onsubmit="return confirm('¿Cerrar definitivamente este periodo fiscal? Se generará el asiento de cierre y no se podrán registrar más operaciones.')">
                @csrf
                <button type="submit" class="btn btn-danger">Cerrar Periodo Fiscal</button>
            </form>
        </div>
    @else
        <div class="flex gap-3 mb-6">
            <span class="btn btn-secondary cursor-not-allowed">Periodo Cerrado</span>
        </div>
    @endif

    <!-- Detalle de Asientos -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50 border-b border-gray-200 px-6 py-3 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-800">Asientos del Periodo</h3>
            <span class="text-sm font-bold text-gray-500">{{ $periodo->asientos->count() }} asientos</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Fecha</th>
                        <th class="px-6 py-3 text-left text-xs font-bold tracking-wider uppercase text-gray-500">Nº Asiento</th>
                        <th class="px-6 py-3 text-left text-xs font-bold tracking-wider uppercase text-gray-500">Concepto</th>
                        <th class="px-6 py-3 text-right text-xs font-bold tracking-wider uppercase text-gray-500">Debe</th>
                        <th class="px-6 py-3 text-right text-xs font-bold tracking-wider uppercase text-gray-500">Haber</th>
                        <th class="px-6 py-3 text-left text-xs font-bold tracking-wider uppercase text-gray-500">Módulo</th>
                        <th class="px-6 py-3 text-center text-xs font-bold tracking-wider uppercase text-gray-500">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($periodo->asientos as $asiento)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-600 font-medium whitespace-nowrap">
                                {{ \Carbon\Carbon::parse($asiento->fecha)->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-3 text-gray-900 font-bold whitespace-nowrap">
                                {{ $asiento->numero_asiento }}
                            </td>
                            <td class="px-6 py-3 text-gray-700">
                                {{ $asiento->concepto }}
                            </td>
                            <td class="px-6 py-3 text-right text-red-600 font-bold">
                                C$ {{ number_format($asiento->detalles->sum('debe'), 2) }}
                            </td>
                            <td class="px-6 py-3 text-right font-bold text-emerald-600">
                                C$ {{ number_format($asiento->detalles->sum('haber'), 2) }}
                            </td>
                            <td class="px-6 py-3 text-gray-600 text-sm">
                                <span class="px-2 py-1 rounded text-xs font-bold {{ $asiento->modulo_origen === 'reversion' ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ ucfirst($asiento->modulo_origen) }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <a href="{{ route('asientos.show', $asiento->id) }}" class="text-emerald-600 hover:text-emerald-900 font-semibold text-sm">Ver</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">
                                No hay asientos registrados en este periodo.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
