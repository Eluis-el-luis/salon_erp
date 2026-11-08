<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;

/**
 * SERVICIO DE NOTIFICACIONES (noviembre 2026).
 *
 * Centraliza la creación y gestión de notificaciones del sistema.
 * Soporta dos tipos de notificaciones:
 *  1. GENERALES (campanita en navbar): alertas personales por rol/usuario
 *  2. CONTEXTUALES (badges en menú): contadores visuales en items del menú
 */
class NotificationService
{
    // ================================================================
    // TIPOS DE NOTIFICACIÓN ESTÁNDAR
    // ================================================================
    public const TYPE_VENTA_PENDIENTE_APROBACION = 'venta_pendiente_aprobacion';
    public const TYPE_CAJA_SIN_CERRAR = 'caja_sin_cerrar';
    public const TYPE_CXP_VENCIDA = 'cxp_vencida';
    public const TYPE_CXC_VENCIDA = 'cxc_vencida';
    public const TYPE_STOCK_CRITICO = 'stock_critico';
    public const TYPE_COMPRA_PENDIENTE_APROBACION = 'compra_pendiente_aprobacion';
    public const TYPE_NOMINA_PENDIENTE = 'nomina_pendiente';
    public const TYPE_AUSENCIA_PENDIENTE = 'ausencia_pendiente';
    // Notificaciones para el cajero que creó la venta
    public const TYPE_VENTA_APROBADA = 'venta_aprobada';
    public const TYPE_VENTA_RECHAZADA = 'venta_rechazada';

    // Mapeo de tipos a roles que deben recibirlos
    protected array $typeToRoles = [
        self::TYPE_VENTA_PENDIENTE_APROBACION => ['alvaro'],
        self::TYPE_COMPRA_PENDIENTE_APROBACION => ['alvaro'],
        self::TYPE_CAJA_SIN_CERRAR => ['recepcion', 'contador', 'admin', 'alvaro'],
        self::TYPE_CXP_VENCIDA => ['admin', 'contador', 'alvaro'],
        self::TYPE_CXC_VENCIDA => ['recepcion', 'contador', 'admin', 'alvaro'],
        self::TYPE_STOCK_CRITICO => ['admin', 'alvaro'],
        self::TYPE_NOMINA_PENDIENTE => ['admin', 'alvaro'],
        self::TYPE_AUSENCIA_PENDIENTE => ['admin', 'alvaro'],
        // Venta aprobada/rechazada: va al cajero específico (se maneja en createForCashier)
        self::TYPE_VENTA_APROBADA => [],
        self::TYPE_VENTA_RECHAZADA => [],
    ];

    // Mapeo de tipos a items de menú para badges contextuales
    protected array $typeToMenuItem = [
        self::TYPE_VENTA_PENDIENTE_APROBACION => 'aprobaciones',
        self::TYPE_COMPRA_PENDIENTE_APROBACION => 'aprobaciones',
        self::TYPE_CAJA_SIN_CERRAR => 'caja/arqueo',
        self::TYPE_CXP_VENCIDA => 'cuentas-por-pagar',
        self::TYPE_CXC_VENCIDA => 'adelantos',
        self::TYPE_STOCK_CRITICO => 'inventario',
        self::TYPE_NOMINA_PENDIENTE => 'nomina',
        self::TYPE_AUSENCIA_PENDIENTE => 'ausencias',
        self::TYPE_VENTA_APROBADA => 'mis-aprobaciones',
        self::TYPE_VENTA_RECHAZADA => 'mis-aprobaciones',
    ];

    /**
     * Crea una notificación para un usuario específico.
     */
    public function createForUser(int $userId, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
            'priority' => $priority,
            'read' => false,
        ]);
    }

    /**
     * Crea notificaciones para TODOS los usuarios con ciertos roles.
     */
    public function createForRoles(array $roles, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): int
    {
        $users = Usuario::whereIn('role', $roles)->where('is_active', true)->get();

        $count = 0;
        foreach ($users as $user) {
            $this->createForUser($user->id, $type, $title, $message, $data, $priority);
            $count++;
        }

        return $count;
    }

    /**
     * Crea notificación usando el mapeo automático de roles por tipo.
     */
    public function createByType(string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): int
    {
        $roles = $this->typeToRoles[$type] ?? ['alvaro'];
        return $this->createForRoles($roles, $type, $title, $message, $data, $priority);
    }

    /**
     * Crea notificación para el cajero específico que realizó la venta.
     * Usado cuando una venta es aprobada o rechazada.
     */
    public function createForCashier(int $cashierId, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): Notification
    {
        // Solo para tipos de venta aprobada/rechazada
        if (!in_array($type, [self::TYPE_VENTA_APROBADA, self::TYPE_VENTA_RECHAZADA])) {
            throw new \InvalidArgumentException("Tipo de notificación no válido para cajero: $type");
        }

        return $this->createForUser($cashierId, $type, $title, $message, $data, $priority);
    }

    /**
     * Obtiene notificaciones no leídas del usuario autenticado.
     */
    public function getUnreadForAuth(int $limit = 10): Collection
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Obtiene todas las notificaciones del usuario autenticado (para dropdown).
     */
    public function getAllForAuth(int $limit = 20): Collection
    {
        return Notification::where('user_id', auth()->id())
            ->orderByDesc('read')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Cuenta notificaciones no leídas del usuario autenticado.
     */
    public function countUnreadForAuth(): int
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->count();
    }

    /**
     * Cuenta notificaciones no leídas por tipo (para badges en menú).
     * Retorna array: ['menu_item_url' => count]
     */
    public function getContextualCounts(): array
    {
        $unread = Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->get();

        $counts = [];
        foreach ($unread as $notification) {
            $menuItem = $this->typeToMenuItem[$notification->type] ?? null;
            if ($menuItem) {
                $counts[$menuItem] = ($counts[$menuItem] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Marca notificaciones como leídas.
     */
    public function markAsRead(array $notificationIds): int
    {
        return Notification::whereIn('id', $notificationIds)
            ->where('user_id', auth()->id())
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);
    }

    /**
     * Marca todas como leídas.
     */
    public function markAllAsRead(): int
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);
    }

    /**
     * Limpia notificaciones antiguas (ej. > 30 días leídas).
     */
    public function pruneOld(int $days = 30): int
    {
        return Notification::where('read', true)
            ->where('read_at', '<', now()->subDays($days))
            ->delete();
    }
}