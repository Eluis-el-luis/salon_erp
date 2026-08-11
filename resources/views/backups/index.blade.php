@extends('layouts.app')

@section('content')
<div x-data="{ restoreModalOpen: false }" class="space-y-6 max-w-5xl mx-auto">
    
    <!-- Encabezado -->
    <div class="mb-8">
        <h2 class="text-2xl font-extrabold text-gray-900">Seguridad y Respaldos</h2>
        <p class="text-sm text-gray-500 mt-1">Exporta e importa la información completa de tu sistema.</p>
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
                <p class="text-sm text-gray-600 mb-6">Descarga un archivo <span class="font-bold">.sql</span> con toda la información actual del salón (clientes, caja, agenda e inventario).</p>
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
                <h3 class="text-xl font-black text-gray-800 mb-2">Restaurar Sistema</h3>
                <p class="text-sm text-gray-600 mb-6">Sube un archivo <span class="font-bold">.sql</span> previamente descargado para restaurar el sistema a un punto anterior. <br><span class="text-red-500 font-bold">¡Atención! Esto borrará los datos actuales.</span></p>
            </div>
            <button @click="restoreModalOpen = true" class="w-full bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-4 rounded-lg shadow transition">
                Subir Archivo de Respaldo
            </button>
        </div>

    </div>

    <!-- MODAL PARA SUBIR ARCHIVO -->
    <div x-show="restoreModalOpen" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            
            <div x-show="restoreModalOpen" @click="restoreModalOpen = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            
            <div x-show="restoreModalOpen" class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10 border-t-4 border-red-500">
                <form action="{{ url('/backups/restaurar') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-black text-red-600 border-b pb-2 mb-4">¡Advertencia de Restauración!</h3>
                        
                        <p class="text-sm text-gray-600 mb-4">
                            Estás a punto de sobrescribir toda la base de datos actual con la información del archivo que vas a subir. Cualquier venta, cliente o cita registrada después de la fecha de ese respaldo <strong>se perderá permanentemente</strong>.
                        </p>

                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center bg-gray-50">
                            <label class="block text-sm font-bold text-gray-700 mb-2">Selecciona tu archivo .sql</label>
                            <input type="file" name="backup_file" accept=".sql" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer">
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-bold text-white hover:bg-red-700 sm:ml-3 sm:w-auto sm:text-sm transition">
                            Estoy seguro, Restaurar
                        </button>
                        <button @click="restoreModalOpen = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection