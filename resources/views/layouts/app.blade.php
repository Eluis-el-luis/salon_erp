<!-- resources/views/layouts/app.blade.php -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ERP - Álvaro Rugama Make Up Studio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans antialiased text-gray-900">
    
    <!-- Barra de Navegación Principal -->
    <nav class="bg-white shadow-md border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                
                <!-- Logo / Título -->
                <div class="flex items-center">
                    <span class="text-xl font-black tracking-tight text-emerald-600 uppercase">
                        Álvaro Rugama <span class="font-light text-gray-500">ERP</span>
                    </span>
                </div>
                
                <!-- Enlaces del Menú (Rediseño Limpio) -->
                <div class="hidden md:flex items-center space-x-2">
                    
                    <a href="{{ url('/') }}" class="text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 px-3 py-2 rounded-md text-sm font-bold transition">Dashboard</a>
                    <span class="text-gray-300 mx-1">|</span>

                    <!-- 1. Operaciones -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" @click.outside="open = false" class="flex items-center text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 px-3 py-2 rounded-md text-sm font-bold transition">
                            Operaciones
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div x-show="open" style="display: none;" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-100 py-1 z-50">
                            <a href="{{ url('/agenda') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">📅 Agenda Diaria</a>
                            <a href="{{ url('/pos') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💻 Caja (POS)</a>
                            <a href="{{ url('/caja/arqueo') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🔐 Turnos y Arqueo</a>
                        </div>
                    </div>

                    <!-- 2. Administración y Catálogos -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" @click.outside="open = false" class="flex items-center text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 px-3 py-2 rounded-md text-sm font-bold transition">
                            Administración
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div x-show="open" style="display: none;" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-100 py-1 z-50">
                            <a href="{{ url('/clientes') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">👥 Clientes</a>
                            <a href="{{ url('/servicios') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">✂️ Servicios</a>
                            <a href="{{ url('/formulas') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🧪 Fórmulas/Recetas</a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="{{ url('/inventario') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">📦 Inventario</a>
                            <a href="{{ url('/inventario/comprar') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🛒 Compras a Proveedor</a>
                            <a href="{{ url('/proveedores') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🏭 Proveedores</a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="{{ url('/backups') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💾 Respaldos (Backups)</a>
                        </div>
                    </div>

                    <!-- 3. Finanzas -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" @click.outside="open = false" class="flex items-center text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 px-3 py-2 rounded-md text-sm font-bold transition">
                            Finanzas
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div x-show="open" style="display: none;" class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-100 py-1 z-50">
                            <a href="{{ url('/historial-ventas') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🧾 Historial Ventas</a>
                            <a href="{{ url('/bancos') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🏦 Bancos y Depósitos</a>
                            <a href="{{ url('/mesa-cambio') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💱 Mesa de Cambio</a>
                            <a href="{{ url('/caja-chica') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💵 Caja Chica</a>
                            <a href="{{ url('/contabilidad/gasto') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💵 Gasto</a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="{{ url('/adelantos') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🤝 Cuentas por Cobrar</a>
                            <a href="{{ url('/cuentas-por-pagar') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💳 Cuentas por Pagar</a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="{{ url('/contabilidad') }}" class="block px-4 py-2 text-sm text-gray-700 font-bold hover:bg-emerald-50 hover:text-emerald-700">📚 Libro Diario</a>
                            <a href="{{ url('/contabilidad/mayor') }}" class="block px-4 py-2 text-sm text-gray-700 font-bold hover:bg-emerald-50 hover:text-emerald-700">📖 Libro Mayor</a>
                            <a href="{{ url('/contabilidad/resultados') }}" class="block px-4 py-2 text-sm text-gray-700 font-bold hover:bg-emerald-50 hover:text-emerald-700">📊 Estado de Resultados</a>
                            <a href="{{ url('/reportes') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">📊 Reportes</a>
                        </div>
                    </div>

                    <!-- 4. RRHH -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" @click.outside="open = false" class="flex items-center text-gray-600 hover:bg-emerald-50 hover:text-emerald-700 px-3 py-2 rounded-md text-sm font-bold transition">
                            RRHH
                            <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div x-show="open" style="display: none;" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-100 py-1 z-50">
                            <a href="{{ url('/empleados') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">👨‍💼 Colaboradores</a>
                            <a href="{{ url('/nomina') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">💰 Nómina (Planillas)</a>
                            <a href="{{ url('/asistencia') }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">🕒 Asistencia</a>
                        </div>
                    </div>

                    <span class="text-gray-300 mx-2">|</span>

                    <!-- Resto de tu código (Usuario y Salir) -->
                    @auth
                    <div class="hidden md:flex flex-col text-right mr-4 border-r pr-4 border-gray-200">
                        <span class="text-sm font-black text-gray-900 leading-tight">{{ auth()->user()->name }}</span>
                        <span class="text-[10px] uppercase font-black text-emerald-600 tracking-widest">{{ auth()->user()->role }}</span>
                    </div>
                    @endauth
                    
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-red-500 hover:bg-red-50 hover:text-red-700 px-3 py-2 rounded-md text-sm font-bold transition flex items-center">
                            Salir
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido Principal -->
    <main class="max-w-7xl mx-auto py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>

    <!-- Contenedor Global de Notificaciones (Toasts)  -->
    <div x-data="{ show: false, message: '' }"
         x-on:notify.window="message = $event.detail; show = true; setTimeout(() => show = false, 3000)"
         class="fixed bottom-8 right-8 z-50 min-w-[350px]"
         style="display: none;"
         x-show="show"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform translate-y-2"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 transform translate-y-0"
         x-transition:leave-end="opacity-0 transform translate-y-2">
         
        <div class="bg-emerald-600 text-white px-8 py-5 rounded-xl shadow-2xl flex items-center space-x-4 border-l-8 border-emerald-800">
            
            <svg class="w-9 h-9 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            <span class="font-bold text-lg" x-text="message"></span>
        </div>
    </div>

    <!-- Contenedor Global de Confirmación  -->
    <div x-data="{ showConfirm: false, confirmMessage: '', confirmAction: null }"
         @open-confirm.window="
            confirmMessage = $event.detail.message;
            confirmAction = $event.detail.action;
            showConfirm = true;
         "
         x-show="showConfirm"
         class="fixed inset-0 z-[100] overflow-y-auto" 
         style="display: none;">
         
         <!-- Usamos Flexbox puro para centrar perfectamente en cualquier pantalla -->
         <div class="flex items-center justify-center min-h-screen px-4 text-center">
             
             <!-- Fondo Oscuro  -->
             <div x-show="showConfirm" 
                  @click="showConfirm = false" 
                  x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" 
                  x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" 
                  class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
             
             <!-- Panel Blanco del Modal  -->
             <div x-show="showConfirm" 
                  x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                  x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                  class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all w-full max-w-lg z-10">
                 
                 <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                     <div class="sm:flex sm:items-start">
                         <!-- Ícono de Advertencia -->
                         <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-amber-100 sm:mx-0 sm:h-10 sm:w-10">
                             <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                             </svg>
                         </div>
                         <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                             <h3 class="text-lg leading-6 font-bold text-gray-900">Confirmar Acción</h3>
                             <div class="mt-2">
                                 <p class="text-sm text-gray-600 font-medium" x-text="confirmMessage"></p>
                             </div>
                         </div>
                     </div>
                 </div>
                 
                 <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                     <!-- Botón de Confirmar que ejecuta la función guardada -->
                     <button @click="if(confirmAction) confirmAction(); showConfirm = false;" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-bold text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm transition">
                         Sí, Continuar
                     </button>
                     <button @click="showConfirm = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition">
                         Cancelar
                     </button>
                 </div>
             </div>
         </div>
    </div>

</body>
</html>