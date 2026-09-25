@extends('layouts.app')

@section('content')
<div x-data="asientosIndex()" class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Asientos Manuales</h1>
            <p class="page-subtitle">Crear y gestionar asientos contables manuales con validación de partida doble</p>
        </div>
        <a href="{{ route('asientos.create') }}" class="btn btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Asiento Manual
        </a>
    </div>

    <!-- Filtros -->
    <div class="card p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="label">Buscar</label>
                <input type="text" name="buscar" value="{{ request('buscar') }}" placeholder="Buscar por concepto, número..." class="input">
            </div>
            <div>
                <label class="label">Desde</label>
                <input type="date" name="fecha_desde" value="{{ request('fecha_desde') }}" class="input">
            </div>
            <div>
                <label class="label">Hasta</label>
                <input type="date" name="fecha_hasta" value="{{ request('fecha_hasta') }}" class="input">
            </div>
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="{{ route('asientos.index') }}" class="btn btn-secondary">Limpiar</a>
        </form>
    </div>

    <!-- Tabla de Asientos -->
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Nº Asiento</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Concepto</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Total Debe</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Total Haber</th>
                        <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Estado</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($asientos as $asiento)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-gray-900 font-medium">
                                {{ \Carbon\Carbon::parse($asiento->fecha)->format('d/m/Y') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-900">
                                {{ $asiento->numero_asiento }}
                            </td>
                            <td class="px-6 py-4 text-gray-700 max-w-xs truncate">
                                {{ $asiento->concepto }}
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-red-600">
                                C$ {{ number_format($asiento->detalles->sum('debe'), 2) }}
                            </td>
                            <td class="px-6 py-4 text-right font-bold text-emerald-600">
                                C$ {{ number_format($asiento->detalles->sum('haber'), 2) }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Contabilizado</span>
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-medium">
                                <a href="{{ route('asientos.show', $asiento->id) }}" class="text-emerald-600 hover:text-emerald-900 font-semibold mr-3">Ver</a>
                                @if($asiento->modulo_origen !== 'reversion')
                                    <form action="{{ route('asientos.reversar', $asiento->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" onclick="return confirm('¿Crear asiento de reversión para anular este asiento?')" class="text-red-600 hover:text-red-900 font-medium" title="Reversar (crear asiento inverso)">
                                            Reversar
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">
                                No hay asientos manuales registrados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        {{ $asientos->links() }}
    </div>
</div>

@endsection