@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Periodos Contables</h1>
            <p class="page-subtitle">Gestión de periodos fiscales y cierres contables</p>
        </div>
        <a href="{{ route('periodos.create') }}" class="btn btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Periodo
        </a>
    </div>

    <!-- Tabla de Periodos -->
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Periodo</th>
                        <th class="px-6 py-3 text-left text-xs font-bold tracking-wider uppercase text-gray-500">Fechas</th>
                        <th class="px-6 py-3 text-center text-xs font-bold tracking-wider uppercase text-gray-500">Estado</th>
                        <th class="px-6 py-3 text-right text-xs font-bold tracking-wider uppercase text-gray-500">Asientos</th>
                        <th class="px-6 py-3 text-right text-xs font-bold tracking-wider uppercase text-gray-500">Total Debe</th>
                        <th class="px-6 py-3 text-right text-xs font-bold tracking-wider uppercase text-gray-500">Total Haber</th>
                        <th class="px-6 py-3 text-center text-xs font-bold tracking-wider uppercase text-gray-500">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($periodos as $periodo)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="font-bold text-gray-900">{{ $periodo->nombre }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                {{ \Carbon\Carbon::parse($periodo->fecha_inicio)->format('d/m/Y') }} - {{ \Carbon\Carbon::parse($periodo->fecha_fin)->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if($periodo->estado === 'abierto')
                                    <span class="px-2 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Abierto</span>
                                @else
                                    <span class="px-2 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600">Cerrado</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-gray-900 font-medium">
                                {{ $periodo->asientos_count ?? 0 }}
                            </td>
                            <td class="px-6 py-4 text-right text-gray-600 font-medium">
                                C$ {{ number_format($periodo->asientos->flatMap->detalles->sum('debe') ?? 0, 2) }}
                            </td>
                            <td class="px-6 py-4 text-right text-gray-600 font-medium">
                                C$ {{ number_format($periodo->asientos->flatMap->detalles->sum('haber') ?? 0, 2) }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('periodos.show', $periodo) }}" class="text-emerald-600 hover:text-emerald-900 font-semibold text-sm">Ver</a>
                                    
                                    @if($periodo->estado === 'abierto')
                                        <form action="{{ route('periodos.cierre', $periodo) }}" method="POST" class="inline" onsubmit="return confirm('¿Cerrar definitivamente este periodo fiscal? Se generará el asiento de cierre y no se podrán registrar más operaciones en este periodo.')">
                                            @csrf
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-semibold text-sm">Cerrar</button>
                                        </form>
                                    @else
                                        <span class="text-gray-400 text-sm">Cerrado</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">
                                No hay periodos contables registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $periodos->links() }}
    </div>

    <!-- Botón para crear nuevo periodo -->
    <div class="text-center">
        <a href="{{ route('periodos.create') }}" class="btn btn-primary inline-flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Periodo Contable
        </a>
    </div>
</div>
@endsection