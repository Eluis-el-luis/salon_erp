@extends('layouts.app')

@section('title', 'Libro Mayor Bancario')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4">
        <div>
            <a href="{{ url('/bancos') }}" class="text-xs font-bold text-emerald-700 hover:underline uppercase tracking-widest">&larr; Volver a Bancos</a>
            <h2 class="page-title mt-1">Libro Mayor — {{ $cuenta->banco->nombre }}</h2>
            <p class="page-subtitle">
                {{ $cuenta->etiqueta() }}
                @if($cuenta->cuentaContable)
                    &nbsp;•&nbsp;Mayor: <b>{{ $cuenta->cuentaContable->codigo }} {{ $cuenta->cuentaContable->nombre }}</b>
                @endif
            </p>
        </div>
        <div class="flex flex-col items-end gap-1 text-right">
            <span class="text-xs font-bold uppercase tracking-widest text-gray-400">Saldo actual en libros</span>
            <span class="text-2xl font-black text-gray-900">{{ $cuenta->esEnDolares() ? '$ ' : 'C$ ' }}{{ number_format($cuenta->saldo_actual, 2) }}</span>
            <span class="text-[11px] text-gray-400">Saldo inicial histórico: {{ number_format($saldoInicial, 2) }} {{ $cuenta->esEnDolares() ? 'USD' : 'NIO' }}</span>
        </div>
    </div>

    <!-- Resumen de estados de conciliación -->
    @php
        $totalPendientes = $movimientos->where('estado_conciliacion', 'pendiente')->count();
        $totalTransito = $movimientos->where('estado_conciliacion', 'en_transito')->count();
        $totalConciliados = $movimientos->where('estado_conciliacion', 'conciliado')->count();
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-widest text-amber-600">Pendientes</span>
            <span class="text-xl font-black text-gray-900">{{ $totalPendientes }}</span>
        </div>
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-widest text-blue-600">En tránsito</span>
            <span class="text-xl font-black text-gray-900">{{ $totalTransito }}</span>
        </div>
        <div class="card p-4 flex items-center justify-between">
            <span class="text-xs font-bold uppercase tracking-widest text-emerald-600">Conciliados</span>
            <span class="text-xl font-black text-gray-900">{{ $totalConciliados }}</span>
        </div>
    </div>

    <a href="{{ route('bancos.conciliacion', $cuenta->id) }}" class="btn btn-primary inline-flex">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        Ir a conciliación bancaria
    </a>

    <!-- Tabla del libro -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="font-bold text-gray-900">Historial de movimientos (más recientes primero)</h3>
            <p class="text-xs text-gray-500">Cada movimiento queda vinculado a su asiento contable y a su referencia de origen.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Fecha</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Concepto</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Origen</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Asiento</th>
                        <th class="px-4 py-3 text-center font-bold uppercase text-xs tracking-wider">Conciliación</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Cargo</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Abono</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Saldo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($movimientos as $m)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600 font-medium whitespace-nowrap">{{ $m->fecha->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-gray-800">{{ $m->concepto }}
                                @if($m->referencia_banco)
                                    <span class="block text-[11px] text-gray-400">Ref: {{ $m->referencia_banco }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-gray-100 text-gray-600 py-1 px-2 rounded-full">{{ $m->modulo_origen }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($m->asientoContable)
                                    <a href="{{ url('/asientos/' . $m->asientoContable->id) }}"
                                       class="font-bold text-emerald-700 hover:underline">{{ $m->asientoContable->numero_asiento }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($m->estado_conciliacion === 'conciliado')
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-700 py-1 px-2 rounded-full"
                                          title="Conciliado el {{ $m->fecha_conciliacion?->format('d/m/Y') }}">OK</span>
                                @elseif($m->estado_conciliacion === 'en_transito')
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-blue-100 text-blue-700 py-1 px-2 rounded-full">Tránsito</span>
                                @else
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 py-1 px-2 rounded-full">Pend.</span>
                                @endif
                            </td>
                            @if($m->signo() > 0)
                                <td class="px-4 py-3 text-right text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-black text-emerald-700">{{ number_format($m->monto, 2) }}</td>
                            @else
                                <td class="px-4 py-3 text-right font-black text-red-600">{{ number_format($m->monto, 2) }}</td>
                                <td class="px-4 py-3 text-right text-gray-400">—</td>
                            @endif
                            <td class="px-4 py-3 text-right font-bold text-gray-900">{{ number_format($m->saldo_corriendo, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 text-sm">Esta cuenta no tiene movimientos registrados aún.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
