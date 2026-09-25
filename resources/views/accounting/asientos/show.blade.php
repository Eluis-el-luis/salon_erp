@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Asiento Manual #{{ $asiento->numero_asiento }}</h1>
            <p class="page-subtitle">Detalle del asiento contable manual</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('asientos.index') }}" class="btn btn-secondary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                Volver al Listado
            </a>
        </div>
    </div>

    <!-- Cabecera del Asiento -->
    <div class="card p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div>
                <h2 class="text-xl font-bold text-gray-800">Asiento #{{ $asiento->numero_asiento }}</h2>
                <p class="text-sm text-gray-500 mt-1">Creado el {{ \Carbon\Carbon::parse($asiento->created_at)->format('d/m/Y H:i') }} por {{ $asiento->usuario->name }}</p>
            </div>
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Contabilizado</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-xs font-bold text-gray-500 uppercase">Fecha del Asiento</p>
                <p class="font-bold text-gray-900">{{ \Carbon\Carbon::parse($asiento->fecha)->format('d/m/Y') }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-xs font-bold text-gray-500 uppercase">Concepto</p>
                <p class="font-medium text-gray-900">{{ $asiento->concepto }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-xs font-bold text-gray-500 uppercase">Usuario</p>
                <p class="font-medium text-gray-900">{{ $asiento->usuario->name }}</p>
            </div>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-xs font-bold text-gray-500 uppercase">Período</p>
                <p class="font-medium text-gray-900">{{ $asiento->periodo->nombre ?? 'N/A' }}</p>
            </div>
        </div>

        @if($asiento->modulo_origen === 'reversion')
            <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-800 text-sm">
                <strong>Asiento de Reversión:</strong> Este asiento revierte el asiento original #{{ $asiento->referencia_id }}.
            </div>
        @endif
    </div>

    <!-- Detalle de Líneas -->
    <div class="card overflow-hidden">
        <div class="card-header bg-gray-50 border-b border-gray-200 px-6 py-4 flex justify-between items-center">
            <h3 class="text-lg font-bold text-gray-800">Detalle de Líneas (Partida Doble)</h3>
            <div class="flex items-center gap-4 text-sm">
                <span class="font-bold text-red-600">Total Debe: C$ {{ number_format($asiento->detalles->sum('debe'), 2) }}</span>
                <span class="font-bold text-emerald-600">Total Haber: C$ {{ number_format($asiento->detalles->sum('haber'), 2) }}</span>
                <span class="px-2 py-1 rounded text-xs font-bold {{ $asiento->detalles->sum('debe') == $asiento->detalles->sum('haber') ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">
                    {{ $asiento->detalles->sum('debe') == $asiento->detalles->sum('haber') ? 'CUADRADO ✓' : 'DESCUADRADO ✗' }}
                </span>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Cuenta</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Centro de Costo</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">DEBE (C$)</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">HABER (C$)</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Descripción</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($asiento->detalles as $index => $detalle)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-center text-sm font-medium text-gray-900">{{ $loop->iteration }}</td>
                            <td class="px-6 py-3">
                                <div class="font-bold text-gray-900">{{ $detalle->cuenta->codigo }} - {{ $detalle->cuenta->nombre }}</div>
                                <div class="text-xs text-gray-500">{{ $detalle->cuenta->codigo }} - {{ $detalle->cuenta->naturaleza }}</div>
                            </td>
                            <td class="px-6 py-3">
                                @if($detalle->centroCosto)
                                    <span class="px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 rounded">{{ $detalle->centroCosto->codigo }} - {{ $detalle->centroCosto->nombre }}</span>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right font-bold text-red-600">{{ $detalle->debe > 0 ? 'C$ ' . number_format($detalle->debe, 2) : '—' }}</td>
                            <td class="px-6 py-3 text-right font-bold text-emerald-600">{{ $detalle->haber > 0 ? 'C$ ' . number_format($detalle->haber, 2) : '—' }}</td>
                            <td class="px-6 py-3 text-gray-600 text-sm">{{ $detalle->descripcion ?? '—' }}</td>
                        </tr>
                    @endforeach
                    
                    <!-- Totales -->
                    <tr class="bg-gray-50 font-bold">
                        <td colspan="3" class="px-6 py-3 text-right font-bold text-gray-900">TOTALES</td>
                        <td class="px-6 py-3 text-right font-bold text-red-600">C$ {{ number_format($asiento->detalles->sum('debe'), 2) }}</td>
                        <td class="px-6 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($asiento->detalles->sum('haber'), 2) }}</td>
                        <td></td>
                    </tr>
                    <tr class="bg-gray-50">
                        <td colspan="3" class="px-6 py-3 text-right font-bold text-gray-900">DIFERENCIA</td>
                        <td colspan="2" class="px-6 py-3 text-right font-bold {{ $asiento->detalles->sum('debe') == $asiento->detalles->sum('haber') ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $asiento->detalles->sum('debe') == $asiento->detalles->sum('haber') ? 'CUADRADO ✓' : 'DESCUADRADO ✗' }}
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Acciones -->
        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200">
            <a href="{{ route('asientos.index') }}" class="btn btn-secondary">Volver al Listado</a>
            @if($asiento->modulo_origen !== 'reversion')
                <form action="{{ route('asientos.reversar', $asiento->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" onclick="return confirm('¿Crear asiento de reversión para anular este asiento? Se generará un asiento inverso que anulará el efecto financiero.')" class="btn btn-danger">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m-1 11v-5m0 0l12-12m0 0l-.065 4.021A2 2 0 016.24 14H6.5a1 1 0 01-.328-.95l3.573-3.572a1 1 0 011.414 0l4.586 4.586a1 1 0 001.414 0l1.75-1.75a1 1 0 011.414 0l3.5-3.5a1 1 0 011.414 0l1.5 1.5a1 1 0 010 1.414z"></path></svg>
                        Reversar (Crear Asiento Inverso)
                    </button>
            @endif
        </div>
    </div>
</div>
@endsection