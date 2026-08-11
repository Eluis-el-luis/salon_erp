@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <!-- Cabecera Corporativa -->
    <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100 text-center">
        <h1 class="text-3xl font-black text-gray-900 uppercase tracking-widest">Álvaro Rugama</h1>
        <h2 class="text-xl font-bold text-gray-600 uppercase mt-1">Make Up Studio</h2>
        <h3 class="text-lg font-bold text-emerald-600 uppercase mt-4">Estado de Resultados</h3>
        <p class="text-gray-500 text-sm mt-1">Periodo actual (Agosto 2026)</p>
        <p class="text-gray-400 text-xs mt-1">Expresado en Córdobas (C$)</p>
    </div>

    <!-- Cuerpo del Estado de Resultados -->
    <div class="bg-white shadow-lg border border-gray-200 rounded-xl overflow-hidden p-8">
        
        <!-- 1. INGRESOS -->
        <div class="mb-6">
            <h4 class="text-lg font-black text-gray-800 border-b-2 border-gray-800 mb-3">INGRESOS OPERATIVOS</h4>
            @foreach($cuentasResultados->filter(fn($c) => str_starts_with($c->codigo, '4') && $c->saldo_final != 0) as $cuenta)
                <div class="flex justify-between text-sm py-1">
                    <span class="text-gray-700">{{ $cuenta->nombre }}</span>
                    <span class="font-medium text-gray-900">C$ {{ number_format($cuenta->saldo_final, 2) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between text-sm py-2 mt-2 bg-gray-50 font-bold border-t border-gray-200 px-2 rounded">
                <span>TOTAL INGRESOS</span>
                <span class="text-emerald-700">C$ {{ number_format($ingresos, 2) }}</span>
            </div>
        </div>

        <!-- 2. COSTOS -->
        <div class="mb-6">
            <h4 class="text-lg font-black text-gray-800 border-b-2 border-gray-800 mb-3">COSTOS DIRECTOS</h4>
            @foreach($cuentasResultados->filter(fn($c) => str_starts_with($c->codigo, '5') && $c->saldo_final != 0) as $cuenta)
                <div class="flex justify-between text-sm py-1">
                    <span class="text-gray-700">{{ $cuenta->nombre }}</span>
                    <span class="font-medium text-gray-900">C$ {{ number_format($cuenta->saldo_final, 2) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between text-sm py-2 mt-2 bg-gray-50 font-bold border-t border-gray-200 px-2 rounded">
                <span>TOTAL COSTOS</span>
                <span class="text-red-700">(C$ {{ number_format($costos, 2) }})</span>
            </div>
        </div>

        <!-- UTILIDAD BRUTA -->
        <div class="flex justify-between text-lg py-3 mb-8 bg-emerald-800 text-white font-black px-4 rounded-lg shadow-inner">
            <span>UTILIDAD BRUTA</span>
            <span>C$ {{ number_format($utilidadBruta, 2) }}</span>
        </div>

        <!-- 3. GASTOS OPERATIVOS -->
        <div class="mb-6">
            <h4 class="text-lg font-black text-gray-800 border-b-2 border-gray-800 mb-3">GASTOS DE OPERACIÓN</h4>
            @foreach($cuentasResultados->filter(fn($c) => str_starts_with($c->codigo, '6') && $c->saldo_final != 0) as $cuenta)
                <div class="flex justify-between text-sm py-1">
                    <span class="text-gray-700">{{ $cuenta->nombre }}</span>
                    <span class="font-medium text-gray-900">C$ {{ number_format($cuenta->saldo_final, 2) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between text-sm py-2 mt-2 bg-gray-50 font-bold border-t border-gray-200 px-2 rounded">
                <span>TOTAL GASTOS DE OPERACIÓN</span>
                <span class="text-red-700">(C$ {{ number_format($gastosOperativos, 2) }})</span>
            </div>
        </div>

        <!-- 3. GASTOS OPERATIVOS -->
        <div class="mb-6">
            <h4 class="text-lg font-black text-gray-800 border-b-2 border-gray-800 mb-3">GASTOS DE OPERACIÓN</h4>
            @foreach($cuentasResultados->filter(fn($c) => str_starts_with($c->codigo, '6') && $c->saldo_final != 0) as $cuenta)
                <div class="flex justify-between text-sm py-1">
                    <span class="text-gray-700">{{ $cuenta->nombre }}</span>
                    <span class="font-medium text-gray-900">C$ {{ number_format($cuenta->saldo_final, 2) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between text-sm py-2 mt-2 bg-gray-50 font-bold border-t border-gray-200 px-2 rounded">
                <span>TOTAL GASTOS DE OPERACIÓN</span>
                <span class="text-red-700">(C$ {{ number_format($gastosOperativos, 2) }})</span>
            </div>
        </div>

        <!-- UTILIDAD NETA -->
        @php
            $colorNeta = $utilidadNeta >= 0 ? 'bg-gray-900 text-emerald-400' : 'bg-red-900 text-red-100';
            $textoNeta = $utilidadNeta >= 0 ? 'UTILIDAD NETA (Ganancia)' : 'PÉRDIDA NETA';
        @endphp
        <div class="flex justify-between text-xl py-4 mt-8 {{ $colorNeta }} font-black px-6 rounded-xl shadow-2xl border-2 border-gray-800">
            <span>{{ $textoNeta }}</span>
            <span>C$ {{ number_format($utilidadNeta, 2) }}</span>
        </div>

    </div>
</div>
@endsection