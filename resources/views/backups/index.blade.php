@extends('layouts.app')

@section('content')
<div class="space-y-6 max-w-5xl mx-auto">
    
    <!-- Encabezado -->
    <div class="mb-8">
        <h2 class="page-title">Seguridad y Respaldos</h2>
        <p class="text-sm text-gray-500 mt-1">Consulta y descarga copias SQL del sistema.</p>
    </div>

    <!-- Alertas de Éxito / Error -->
    @if(session('success'))
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm mb-6 font-bold">
            ✓ {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm mb-6 font-bold">
            ⚠ {{ session('error') }}
        </div>
    @endif

    <!-- Tarjetas de Acción -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        
        <!-- Tarjeta: Descargar Respaldo -->
        <div class="bg-white rounded-xl shadow-md border border-gray-100 p-8 flex flex-col justify-between hover:shadow-lg transition">
            <div>
                <div class="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mb-6">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                </div>
                <h3 class="text-xl font-black text-gray-800 mb-2">Crear Respaldo Local</h3>
                <p class="text-sm text-gray-600 mb-6">Descarga una copia <span class="font-bold">.sql</span> del sistema. El destino de la exportación y su recuperación requieren verificación independiente antes de utilizarla para restaurar datos.</p>
            </div>
            <a href="{{ url('/backups/descargar') }}" class="w-full text-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded-lg shadow transition">
                Descargar Base de Datos
            </a>
        </div>

        <!-- Tarjeta: Restaurar Respaldo -->
        <div class="bg-white rounded-xl shadow-md border border-amber-200 p-8 flex flex-col justify-between hover:shadow-lg transition">
            <div>
                <div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mb-6">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                </div>
                <h3 class="text-xl font-black text-gray-800 mb-2">Restauración temporalmente deshabilitada</h3>
                <p id="restore-disabled-notice" role="status" class="text-sm text-gray-600 mb-6">La restauración SQL desde la aplicación está temporalmente deshabilitada por seguridad. La recuperación requiere un procedimiento autorizado.</p>
            </div>
            <button type="button" disabled aria-describedby="restore-disabled-notice" class="w-full bg-gray-200 text-gray-600 font-bold py-3 px-4 rounded-lg cursor-not-allowed">
                Restauración no disponible
            </button>
        </div>

    </div>

</div>
@endsection
