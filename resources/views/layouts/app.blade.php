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

                @php
                    // Marca redirige según rol: Dashboard para alvaro/admin/contador, POS para recepcion/caja/estilista
                    $brandUrl = in_array(auth()->user()->role ?? '', ['alvaro', 'admin', 'contador']) ? '/' : '/pos';
                @endphp
                <!-- Marca -->
                <a href="{{ $brandUrl }}" class="flex items-center space-x-3 shrink-0">
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

                    @php
                        // VISIBILIDAD POR ROL (RBAC): Dashboard solo para alvaro, admin, contador
                        $rolUsuario = auth()->user()->role ?? null;
                        $verDashboard = in_array($rolUsuario, ['alvaro', 'admin', 'contador'], true);
                    @endphp

                    @if($verDashboard)
                    <a href="{{ url('/') }}" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h3a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1h3a1 1 0 001-1V10"></path></svg>
                        Dashboard
                    </a>
                    @endif

                    @php
                        // VISIBILIDAD POR ROL (RBAC): 'roles' lista los roles con
                        // acceso a la ruta. Sin 'roles' = todos los roles. El rol
                        // supremo 'alvaro' ve todo (bypass); 'admin' es operativo y
                        // NO accede a la contabilidad (alvaro + contador).
                        $puedeVer = function (array $item) use ($rolUsuario): bool {
                            if (!isset($item['roles'])) return true;
                            if ($rolUsuario === 'alvaro') return true;
                            return in_array($rolUsuario, $item['roles'], true);
                        };

                        $grupos = [
                            'Operaciones' => [
                                ['url' => '/agenda', 'label' => 'Agenda Diaria', 'icon' => 'calendar'],
                                ['url' => '/pos', 'label' => 'Caja (POS)', 'icon' => 'cart', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/caja/arqueo', 'label' => 'Turnos y Arqueo', 'icon' => 'lock', 'roles' => ['recepcion', 'contador', 'admin']],
                            ],
                            'Administración' => [
                                ['url' => '/clientes', 'label' => 'Clientes', 'icon' => 'users'],
                                ['url' => '/servicios', 'label' => 'Servicios', 'icon' => 'scissors', 'roles' => ['admin']],
                                ['url' => '/formulas', 'label' => 'Fórmulas / Recetas', 'icon' => 'flask', 'roles' => ['admin']],
                                ['sep' => true],
                                ['url' => '/inventario', 'label' => 'Inventario', 'icon' => 'box', 'roles' => ['admin']],
                                ['url' => '/inventario/kardex', 'label' => 'Kárdex Inmutable', 'icon' => 'chart', 'roles' => ['admin']],
                                ['url' => '/inventario/comprar', 'label' => 'Ingreso de Mercadería', 'icon' => 'cart', 'roles' => ['admin']],
                                ['url' => '/mermas', 'label' => 'Mermas y Ajustes', 'icon' => 'trash', 'roles' => ['admin']],
                                ['url' => '/proveedores', 'label' => 'Proveedores', 'icon' => 'truck', 'roles' => ['admin']],
                                ['sep' => true],
                                ['url' => '/backups', 'label' => 'Respaldos', 'icon' => 'save', 'roles' => ['admin']],
                            ],
                            'Finanzas' => [
                                ['url' => '/historial-ventas', 'label' => 'Historial Ventas', 'icon' => 'receipt', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/mis-aprobaciones', 'label' => 'Mis Aprobaciones', 'icon' => 'clock', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/aprobaciones', 'label' => 'Aprobaciones Pendientes', 'icon' => 'shield', 'roles' => ['alvaro']],
                                ['url' => '/bancos', 'label' => 'Bancos y Conciliación', 'icon' => 'bank', 'roles' => ['admin', 'contador']],
                                ['url' => '/mesa-cambio', 'label' => 'Mesa de Cambio', 'icon' => 'exchange', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/caja-chica', 'label' => 'Caja Chica', 'icon' => 'wallet', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/retiros', 'label' => 'Retiros del Propietario', 'icon' => 'user', 'roles' => ['admin', 'contador']],
                                ['url' => '/contabilidad', 'label' => 'Registro de Gastos', 'icon' => 'banknote', 'roles' => ['alvaro', 'contador']],
                                ['sep' => true],
                                ['url' => '/adelantos', 'label' => 'Cuentas por Cobrar', 'icon' => 'handshake', 'roles' => ['recepcion', 'contador', 'admin']],
                                ['url' => '/cuentas-por-pagar', 'label' => 'Cuentas por Pagar', 'icon' => 'credit', 'roles' => ['admin', 'contador']],
                                ['sep' => true],
                                ['url' => '/contabilidad', 'label' => 'Libro Diario', 'icon' => 'book', 'roles' => ['alvaro', 'contador']],
                                ['url' => '/contabilidad/mayor', 'label' => 'Libro Mayor', 'icon' => 'book', 'roles' => ['alvaro', 'contador']],
                                ['url' => '/contabilidad/resultados', 'label' => 'Estado de Resultados', 'icon' => 'chart', 'roles' => ['alvaro', 'contador']],
                                ['url' => '/contabilidad/balance', 'label' => 'Balance General', 'icon' => 'chart', 'roles' => ['alvaro', 'contador']],
                                ['sep' => true],
                                ['url' => '/catalogo', 'label' => 'Catálogo de Cuentas', 'icon' => 'book', 'roles' => ['alvaro', 'contador']],
                                ['url' => '/asientos', 'label' => 'Asientos Manuales', 'icon' => 'book', 'roles' => ['alvaro', 'contador']],
                                ['url' => '/periodos', 'label' => 'Periodos y Cierre Fiscal', 'icon' => 'lock', 'roles' => ['alvaro', 'contador']],
                                ['sep' => true],
                                ['url' => '/reportes', 'label' => 'Reportes', 'icon' => 'chart', 'roles' => ['admin', 'contador']],
                            ],
                            'RRHH' => [
                                ['url' => '/rrhh', 'label' => 'Dashboard RRHH', 'icon' => 'chart', 'roles' => ['admin']],
                                ['url' => '/empleados', 'label' => 'Colaboradores', 'icon' => 'users', 'roles' => ['admin']],
                                ['url' => '/rrhh/turnos', 'label' => 'Turnos', 'icon' => 'clock', 'roles' => ['admin']],
                                ['url' => '/asistencia', 'label' => 'Asistencia', 'icon' => 'clock'],
                                ['url' => '/ausencias', 'label' => 'Ausencias y Permisos', 'icon' => 'calendar', 'roles' => ['admin']],
                                ['url' => '/rrhh/propinas', 'label' => 'Propinas', 'icon' => 'banknote', 'roles' => ['admin']],
                                ['sep' => true],
                                ['url' => '/nomina', 'label' => 'Nómina (Corridas)', 'icon' => 'banknote', 'roles' => ['admin']],
                                ['url' => '/nomina/estructuras', 'label' => 'Estructuras Salariales', 'icon' => 'book', 'roles' => ['admin']],
                            ],
                        ];

                        // Filtra los grupos según el rol, limpiando separadores
                        // huérfanos (al inicio, al final o duplicados).
                        $grupos = collect($grupos)
                            ->map(function ($items) use ($puedeVer) {
                                $resultado = collect();
                                $hayVisible = false;
                                $sepPendiente = false;

                                foreach ($items as $item) {
                                    if (isset($item['sep'])) {
                                        if ($hayVisible) {
                                            $sepPendiente = true;
                                        }
                                        continue;
                                    }
                                    if ($puedeVer($item)) {
                                        if ($sepPendiente) {
                                            $resultado->push(['sep' => true]);
                                            $sepPendiente = false;
                                        }
                                        $resultado->push($item);
                                        $hayVisible = true;
                                    }
                                }

                                return $resultado;
                            })
                            ->filter(fn ($items) => $items->isNotEmpty())
                            ->all();
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
                                        @php
                                            // Obtener contador contextual para este item del menú
                                            $notificationService = app(\App\Services\NotificationService::class);
                                            $contextualCounts = $notificationService->getContextualCounts();
                                            $itemUrl = ltrim($item['url'], '/');
                                            $badgeCount = $contextualCounts[$itemUrl] ?? 0;
                                        @endphp
                                        <a href="{{ url($item['url']) }}"
                                            class="flex items-center gap-3 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 hover:text-gray-900 transition relative">
                                            <x-nav-icon :name="$item['icon']" class="w-4 h-4 text-gray-400" />
                                            {{ $item['label'] }}
                                            @if($badgeCount > 0)
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 bg-red-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center">
                                                    {{ $badgeCount > 9 ? '9+' : $badgeCount }}
                                                </span>
                                            @endif
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Campanita de Notificaciones -->
                <div x-data="notificationBell()" class="relative" @notify.window="refreshCount()">
                    <button @click="toggle()" @click.outside="open = false"
                        class="relative p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100 transition cursor-pointer"
                        aria-label="Notificaciones">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                        <span x-show="unreadCount > 0" class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold w-5 h-5 rounded-full flex items-center justify-center">
                            <span x-text="unreadCount > 9 ? '9+' : unreadCount"></span>
                        </span>
                    </button>

                    <!-- Dropdown de notificaciones -->
                    <div x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" style="display: none;"
                        class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-gray-100 z-50 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100 bg-gray-50">
                            <h3 class="font-bold text-gray-900">Notificaciones</h3>
                            <button @click="markAllRead()" class="text-xs text-emerald-600 hover:text-emerald-700 font-semibold">Marcar todas</button>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <template x-for="notif in notifications" :key="notif.id">
                                <a :href="notif.data?.url || '#'" @click="handleNotificationClick(notif)"
                                    class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition border-b border-gray-50 last:border-0"
                                    :class="notif.read ? 'bg-white' : 'bg-blue-50'">
                                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center"
                                        :class="{
                                            'bg-emerald-100 text-emerald-600': notif.type === 'venta_pendiente_aprobacion' || notif.type === 'venta_aprobada',
                                            'bg-red-100 text-red-600': notif.type === 'venta_rechazada',
                                            'bg-amber-100 text-amber-600': notif.type === 'caja_sin_cerrar',
                                            'bg-red-100 text-red-600': notif.type === 'cxp_vencida' || notif.type === 'cxc_vencida',
                                            'bg-blue-100 text-blue-600': notif.type === 'stock_critico',
                                            'bg-purple-100 text-purple-600': notif.type === 'compra_pendiente_aprobacion',
                                            'bg-gray-100 text-gray-600': true
                                        }">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <template x-if="notif.type === 'venta_pendiente_aprobacion' || notif.type === 'compra_pendiente_aprobacion' || notif.type === 'venta_aprobada'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></template>
                                            <template x-if="notif.type === 'venta_rechazada'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></template>
                                            <template x-if="notif.type === 'caja_sin_cerrar'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></template>
                                            <template x-if="notif.type === 'stock_critico'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></template>
                                            <template x-if="notif.type === 'cxp_vencida' || notif.type === 'cxc_vencida'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></template>
                                            <template x-if="notif.type !== 'venta_pendiente_aprobacion' && notif.type !== 'compra_pendiente_aprobacion' && notif.type !== 'venta_aprobada' && notif.type !== 'venta_rechazada' && notif.type !== 'caja_sin_cerrar' && notif.type !== 'stock_critico' && notif.type !== 'cxp_vencida' && notif.type !== 'cxc_vencida'"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></template>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900" x-text="notif.title"></p>
                                        <p class="text-xs text-gray-500 truncate" x-text="notif.message || 'Sin mensaje'"></p>
                                        <p class="text-[10px] text-gray-400 mt-1" x-text="formatTime(notif.created_at)"></p>
                                    </div>
                                    <span x-show="!notif.read" class="flex-shrink-0 w-2 h-2 bg-emerald-500 rounded-full"></span>
                                </a>
                            </template>
                            <div x-show="notifications.length === 0" class="px-4 py-8 text-center text-gray-500 text-sm">
                                No hay notificaciones
                            </div>
                        </div>
                        <div class="px-4 py-3 bg-gray-50 border-t border-gray-100 text-center">
                            <a href="{{ route('notificaciones.index') }}" class="text-sm text-emerald-600 hover:text-emerald-700 font-semibold">Ver todas</a>
                        </div>
                    </div>
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

    @include('components.pin-modal')

    @if(session('info') || session('success') || session('error'))
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if(session('info'))
                window.dispatchEvent(new CustomEvent('notify', { detail: @json(session('info')) }));
            @endif
            @if(session('success'))
                window.dispatchEvent(new CustomEvent('notify', { detail: @json(session('success')) }));
            @endif
            @if(session('error'))
                window.dispatchEvent(new CustomEvent('notify', { detail: @json(session('error')) }));
            @endif
        });
    </script>
    @endif

    @stack('scripts')
</body>
</html>