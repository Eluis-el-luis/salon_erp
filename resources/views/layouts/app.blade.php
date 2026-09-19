<!-- resources/views/layouts/app.blade.php -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ERP') — Álvaro Rugama Make Up Studio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 font-sans antialiased text-gray-900">

    <nav class="bg-white border-b border-gray-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                <!-- Marca -->
                <a href="{{ url('/') }}" class="flex items-center space-x-3 shrink-0">
                    <span class="flex items-center justify-center w-9 h-9 rounded-lg bg-emerald-700 text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </span>
                    <div class="leading-tight">
                        <span class="font-display text-lg font-semibold text-gray-900 tracking-tight">Álvaro Rugama</span>
                        <span class="block text-[10px] font-bold text-emerald-700 uppercase tracking-[0.2em]">Make Up Studio</span>
                    </div>
                </a>

                <!-- Navegación -->
                <div class="hidden lg:flex items-center space-x-1">

                    <a href="{{ url('/') }}" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10"></path></svg>
                        Dashboard
                    </a>

                    @php
                        $grupos = [
                            'Operaciones' => [
                                ['url' => '/agenda', 'label' => 'Agenda Diaria', 'icon' => 'calendar'],
                                ['url' => '/pos', 'label' => 'Caja (POS)', 'icon' => 'cart'],
                                ['url' => '/caja/arqueo', 'label' => 'Turnos y Arqueo', 'icon' => 'lock'],
                            ],
                            'Administración' => [
                                ['url' => '/clientes', 'label' => 'Clientes', 'icon' => 'users'],
                                ['url' => '/servicios', 'label' => 'Servicios', 'icon' => 'scissors'],
                                ['url' => '/formulas', 'label' => 'Fórmulas / Recetas', 'icon' => 'flask'],
                                ['sep' => true],
                                ['url' => '/inventario', 'label' => 'Inventario', 'icon' => 'box'],
                                ['url' => '/inventario/comprar', 'label' => 'Compras a Proveedor', 'icon' => 'cart'],
                                ['url' => '/proveedores', 'label' => 'Proveedores', 'icon' => 'truck'],
                                ['sep' => true],
                                ['url' => '/backups', 'label' => 'Respaldos', 'icon' => 'save'],
                            ],
                            'Finanzas' => [
                                ['url' => '/historial-ventas', 'label' => 'Historial Ventas', 'icon' => 'receipt'],
                                ['url' => '/bancos', 'label' => 'Bancos y Depósitos', 'icon' => 'bank'],
                                ['url' => '/mesa-cambio', 'label' => 'Mesa de Cambio', 'icon' => 'exchange'],
                                ['url' => '/caja-chica', 'label' => 'Caja Chica', 'icon' => 'wallet'],
                                ['url' => '/retiros', 'label' => 'Retiros del Propietario', 'icon' => 'user'],
                                ['url' => '/contabilidad', 'label' => 'Gasto', 'icon' => 'banknote'],
                                ['sep' => true],
                                ['url' => '/adelantos', 'label' => 'Cuentas por Cobrar', 'icon' => 'handshake'],
                                ['url' => '/cuentas-por-pagar', 'label' => 'Cuentas por Pagar', 'icon' => 'credit'],
                                ['sep' => true],
                                ['url' => '/contabilidad', 'label' => 'Libro Diario', 'icon' => 'book'],
                                ['url' => '/contabilidad/mayor', 'label' => 'Libro Mayor', 'icon' => 'book'],
                                ['url' => '/contabilidad/resultados', 'label' => 'Estado de Resultados', 'icon' => 'chart'],
                                ['url' => '/reportes', 'label' => 'Reportes', 'icon' => 'chart'],
                            ],
                            'RRHH' => [
                                ['url' => '/empleados', 'label' => 'Colaboradores', 'icon' => 'users'],
                                ['url' => '/nomina', 'label' => 'Nómina (Planillas)', 'icon' => 'banknote'],
                                ['url' => '/asistencia', 'label' => 'Asistencia', 'icon' => 'clock'],
                            ],
                        ];
                    @endphp

                    @foreach($grupos as $titulo => $items)
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" @click.outside="open = false"
                                class="flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition cursor-pointer">
                                {{ $titulo }}
                                <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div x-show="open" x-transition @click.outside="open = false" style="display: none;"
                                class="absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50">
                                @foreach($items as $item)
                                    @if(isset($item['sep']))
                                        <div class="border-t border-gray-100 my-1"></div>
                                    @else
                                        <a href="{{ url($item['url']) }}"
                                            class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition">
                                            <x-nav-icon :name="$item['icon']" class="w-4 h-4 text-gray-400" />
                                            {{ $item['label'] }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Usuario y Salir -->
                <div class="flex items-center space-x-3">
                    @auth
                        <div class="hidden sm:flex flex-col text-right">
                            <span class="text-sm font-bold text-gray-900 leading-tight">{{ auth()->user()->name }}</span>
                            <span class="text-[10px] uppercase font-bold text-emerald-700 tracking-widest">{{ auth()->user()->role }}</span>
                        </div>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button type="submit"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-gray-500 hover:text-red-600 hover:bg-red-50 transition cursor-pointer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                                <span class="hidden sm:inline">Salir</span>
                            </button>
                        </form>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <!-- Contenido Principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    <!-- Notificaciones (Toasts) -->
    <div x-data="{ show: false, message: '' }"
         x-on:notify.window="message = $event.detail; show = true; clearTimeout(timeout); timeout = setTimeout(() => show = false, 3500)"
         class="fixed bottom-6 right-6 z-50 w-auto max-w-sm"
         style="display: none;" x-show="show"
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2">
        <div class="flex items-center gap-3 bg-gray-900 text-white pl-4 pr-6 py-4 rounded-xl shadow-2xl border border-gray-700">
            <svg class="w-6 h-6 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span class="font-semibold text-sm" x-text="message"></span>
        </div>
    </div>

    <!-- Modal Global de Confirmación -->
    <div x-data="{ showConfirm: false, confirmMessage: '', confirmAction: null }"
         @open-confirm.window="confirmMessage = $event.detail.message; confirmAction = $event.detail.action; showConfirm = true;"
         x-show="showConfirm" style="display: none;" class="fixed inset-0 z-[100] overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div x-show="showConfirm" @click="showConfirm = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
            <div x-show="showConfirm" x-transition:enter="ease-out duration-200" x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100" x-transition:leave="ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="relative bg-white rounded-2xl shadow-xl w-full max-w-md z-10 overflow-hidden">
                <div class="px-6 pt-6 pb-2">
                    <div class="flex items-start gap-4">
                        <div class="flex-shrink-0 flex items-center justify-center h-11 w-11 rounded-full bg-amber-100">
                            <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Confirmar Acción</h3>
                            <p class="mt-1 text-sm text-gray-600" x-text="confirmMessage"></p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 flex flex-row-reverse gap-3 bg-gray-50">
                    <button @click="if(confirmAction) confirmAction(); showConfirm = false;" type="button" class="btn btn-primary">Sí, Continuar</button>
                    <button @click="showConfirm = false" type="button" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>