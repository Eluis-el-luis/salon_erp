<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Usuario;
use App\Models\Venta;
use App\Models\FacturaCompra;
use App\Models\Articulo;
use App\Models\Cita;
use App\Models\Ausencia;
use App\Models\SesionCaja;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

/**
 * SERVICIO DE NOTIFICACIONES DINÁMICAS POR ROL (noviembre 2026).
 *
 * Sistema inteligente de notificaciones con:
 *  1. TIPOS ESPECÍFICOS POR ROL - Cada rol recibe solo lo relevante
 *  2. EVENTOS AUTOMÁTICOS - Se disparan desde controladores/servicios
 *  3. FILTRADO INTELIGENTE - No notifica a Álvaro sus propias acciones
 *  4. CANALES DUAL - Campanita (dropdown) + Badges contextuales (menú)
 *  4. PRIORIDADES - low, normal, high, urgent
 */
class NotificationService
{
    // ================================================================
    // TIPOS DE NOTIFICACIÓN POR CATEGORÍA/ROL
    // ================================================================
    
    // ---- ÁLVARO (Propietario) - Control total, SIN auto-notificaciones ----
    public const TYPE_VENTA_PENDIENTE_APROBACION = 'venta_pendiente_aprobacion';
    public const TYPE_COMPRA_PENDIENTE_APROBACION = 'compra_pendiente_aprobacion';
    public const TYPE_VENTA_RECHAZADA_ALVARO = 'venta_rechazada_alvaro';       // Cuando Álvaro rechaza
    public const TYPE_COMPRA_RECHAZADA_ALVARO = 'compra_rechazada_alvaro';
    public const TYPE_ALERTA_CRITICA = 'alerta_critica';                       // Stock 0, caja $0, etc.
    public const TYPE_REPORTE_DIARIO = 'reporte_diario';                       // Resumen fin de día
    
    // ---- ADMINISTRADOR - Operativo (Inventario, RRHH, Proveedores, Respaldos) ----
    public const TYPE_STOCK_CRITICO = 'stock_critico';
    public const TYPE_STOCK_AGOTADO = 'stock_agotado';
    public const TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN = 'compra_pendiente_aprobacion_admin';
    public const TYPE_PROVEEDOR_NUEVO = 'proveedor_nuevo';
    public const TYPE_CAJA_SIN_CERRAR = 'caja_sin_cerrar';
    public const TYPE_CAJA_DIFERENCIA = 'caja_diferencia';
    public const TYPE_EMPLEADO_NUEVO = 'empleado_nuevo';
    public const TYPE_AUSENCIA_PENDIENTE = 'ausencia_pendiente';
    public const TYPE_NOMINA_PENDIENTE = 'nomina_pendiente';
    public const TYPE_RESPALDO_FALTANTE = 'respaldo_faltante';
    public const TYPE_RESPALDO_COMPLETADO = 'respaldo_completado';
    public const TYPE_MERMA_REGISTRADA = 'merma_registrada';
    
    // ---- CONTADOR - Finanzas (Bancos, CxP, Contabilidad, Conciliación) ----
    public const TYPE_CXP_VENCIDA = 'cxp_vencida';
    public const TYPE_CXP_POR_VENCER = 'cxp_por_vencer';
    public const TYPE_CXC_VENCIDA = 'cxc_vencida';
    public const TYPE_CXC_POR_VENCER = 'cxc_por_vencer';
    public const TYPE_CONCILIACION_PENDIENTE = 'conciliacion_pendiente';
    public const TYPE_CIERRE_CONTABLE = 'cierre_contable';
    public const TYPE_ASIENTO_DESBALANCEADO = 'asiento_desbalanceado';
    public const TYPE_BANCO_MOVIMIENTO = 'banco_movimiento';
    public const TYPE_IVA_DECLARACION = 'iva_declaracion';
    
    // ---- RECEPCIÓN/CAJA - POS, Ventas, Clientes, Agenda ----
    public const TYPE_VENTA_APROBADA = 'venta_aprobada';
    public const TYPE_VENTA_RECHAZADA = 'venta_rechazada';
    public const TYPE_CITA_NUEVA = 'cita_nueva';
    public const TYPE_CITA_CANCELADA = 'cita_cancelada';
    public const TYPE_CITA_REAGENDADA = 'cita_reagendada';
    public const TYPE_CLIENTE_CXC_VENCIDA = 'cliente_cxc_vencida';
    public const TYPE_CLIENTE_NUEVO = 'cliente_nuevo';
    public const TYPE_COTIZACION_SOLICITADA = 'cotizacion_solicitada';
    
    // ---- ESTILISTA - Agenda, Servicios, Comisiones ----
    public const TYPE_CITA_ASIGNADA = 'cita_asignada';
    public const TYPE_CITA_MODIFICADA = 'cita_modificada';
    public const TYPE_COMISION_GANADA = 'comision_ganada';
    public const TYPE_COMISION_PAGADA = 'comision_pagada';
    public const TYPE_SERVICIO_NUEVO = 'servicio_nuevo';
    public const TYPE_PROPINA_RECIBIDA = 'propina_recibida';

    // ================================================================
    // MAPEO TIPO → ROLES DESTINATARIOS (vacío = solo por evento específico)
    // ================================================================
    protected array $typeToRoles = [
        // Álvaro
        self::TYPE_VENTA_PENDIENTE_APROBACION => ['alvaro'],
        self::TYPE_COMPRA_PENDIENTE_APROBACION => ['alvaro'],
        self::TYPE_VENTA_RECHAZADA_ALVARO => ['alvaro'],
        self::TYPE_COMPRA_RECHAZADA_ALVARO => ['alvaro'],
        self::TYPE_ALERTA_CRITICA => ['alvaro'],
        self::TYPE_REPORTE_DIARIO => ['alvaro'],
        
        // Administrador
        self::TYPE_STOCK_CRITICO => ['admin', 'alvaro'],
        self::TYPE_STOCK_AGOTADO => ['admin', 'alvaro'],
        self::TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN => ['admin', 'alvaro'],
        self::TYPE_PROVEEDOR_NUEVO => ['admin', 'alvaro'],
        self::TYPE_CAJA_SIN_CERRAR => ['admin', 'recepcion', 'contador', 'alvaro'],
        self::TYPE_CAJA_DIFERENCIA => ['admin', 'alvaro'],
        self::TYPE_EMPLEADO_NUEVO => ['admin', 'alvaro'],
        self::TYPE_AUSENCIA_PENDIENTE => ['admin', 'alvaro'],
        self::TYPE_NOMINA_PENDIENTE => ['admin', 'alvaro'],
        self::TYPE_RESPALDO_FALTANTE => ['admin', 'alvaro'],
        self::TYPE_RESPALDO_COMPLETADO => ['admin', 'alvaro'],
        self::TYPE_MERMA_REGISTRADA => ['admin', 'alvaro'],
        
        // Contador
        self::TYPE_CXP_VENCIDA => ['contador', 'admin', 'alvaro'],
        self::TYPE_CXP_POR_VENCER => ['contador', 'admin', 'alvaro'],
        self::TYPE_CXC_VENCIDA => ['contador', 'recepcion', 'admin', 'alvaro'],
        self::TYPE_CXC_POR_VENCER => ['contador', 'recepcion', 'admin', 'alvaro'],
        self::TYPE_CONCILIACION_PENDIENTE => ['contador', 'admin', 'alvaro'],
        self::TYPE_CIERRE_CONTABLE => ['contador', 'admin', 'alvaro'],
        self::TYPE_ASIENTO_DESBALANCEADO => ['contador', 'admin', 'alvaro'],
        self::TYPE_BANCO_MOVIMIENTO => ['contador', 'admin', 'alvaro'],
        self::TYPE_IVA_DECLARACION => ['contador', 'admin', 'alvaro'],
        
        // Recepción/Caja
        self::TYPE_VENTA_APROBADA => [], // Se envía al cajero específico
        self::TYPE_VENTA_RECHAZADA => [], // Se envía al cajero específico
        self::TYPE_CITA_NUEVA => ['recepcion', 'admin', 'alvaro'],
        self::TYPE_CITA_CANCELADA => ['recepcion', 'admin', 'alvaro'],
        self::TYPE_CITA_REAGENDADA => ['recepcion', 'admin', 'alvaro'],
        self::TYPE_CLIENTE_CXC_VENCIDA => ['recepcion', 'contador', 'admin', 'alvaro'],
        self::TYPE_CLIENTE_NUEVO => ['recepcion', 'admin', 'alvaro'],
        self::TYPE_COTIZACION_SOLICITADA => ['recepcion', 'admin', 'alvaro'],
        
        // Estilista (se envía al estilista específico)
        self::TYPE_CITA_ASIGNADA => [],
        self::TYPE_CITA_MODIFICADA => [],
        self::TYPE_COMISION_GANADA => [],
        self::TYPE_COMISION_PAGADA => [],
        self::TYPE_SERVICIO_NUEVO => ['estilista', 'recepcion', 'admin', 'alvaro'],
        self::TYPE_PROPINA_RECIBIDA => [],
    ];

    // ================================================================
    // MAPEO TIPO → ITEM DE MENÚ (para badges contextuales)
    // ================================================================
    protected array $typeToMenuItem = [
        self::TYPE_VENTA_PENDIENTE_APROBACION => 'aprobaciones',
        self::TYPE_COMPRA_PENDIENTE_APROBACION => 'aprobaciones',
        self::TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN => 'inventario/comprar',
        self::TYPE_STOCK_CRITICO => 'inventario',
        self::TYPE_STOCK_AGOTADO => 'inventario',
        self::TYPE_CAJA_SIN_CERRAR => 'caja/arqueo',
        self::TYPE_CAJA_DIFERENCIA => 'caja/arqueo',
        self::TYPE_CXP_VENCIDA => 'cuentas-por-pagar',
        self::TYPE_CXP_POR_VENCER => 'cuentas-por-pagar',
        self::TYPE_CXC_VENCIDA => 'adelantos',
        self::TYPE_CXC_POR_VENCER => 'adelantos',
        self::TYPE_CONCILIACION_PENDIENTE => 'bancos',
        self::TYPE_CIERRE_CONTABLE => 'contabilidad',
        self::TYPE_VENTA_APROBADA => 'mis-aprobaciones',
        self::TYPE_VENTA_RECHAZADA => 'mis-aprobaciones',
        self::TYPE_VENTA_RECHAZADA_ALVARO => 'aprobaciones',
        self::TYPE_CITA_NUEVA => 'agenda',
        self::TYPE_CITA_ASIGNADA => 'agenda',
        self::TYPE_CITA_CANCELADA => 'agenda',
        self::TYPE_CLIENTE_CXC_VENCIDA => 'adelantos',
        self::TYPE_AUSENCIA_PENDIENTE => 'ausencias',
        self::TYPE_NOMINA_PENDIENTE => 'nomina',
        self::TYPE_RESPALDO_FALTANTE => 'backups',
        self::TYPE_CONCILIACION_PENDIENTE => 'bancos',
        self::TYPE_COMISION_GANADA => 'portal/comisiones',
        self::TYPE_STOCK_CRITICO => 'inventario',
    ];

    // ================================================================
    // PRIORIDADES POR TIPO (para ordenamiento)
    // ================================================================
    protected array $typePriority = [
        self::TYPE_ALERTA_CRITICA => 'urgent',
        self::TYPE_STOCK_AGOTADO => 'urgent',
        self::TYPE_ASIENTO_DESBALANCEADO => 'urgent',
        self::TYPE_CAJA_DIFERENCIA => 'high',
        self::TYPE_CXP_VENCIDA => 'high',
        self::TYPE_CXC_VENCIDA => 'high',
        self::TYPE_CONCILIACION_PENDIENTE => 'high',
        self::TYPE_VENTA_PENDIENTE_APROBACION => 'high',
        self::TYPE_COMPRA_PENDIENTE_APROBACION => 'high',
        self::TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN => 'high',
        self::TYPE_CIERRE_CONTABLE => 'high',
        self::TYPE_VENTA_RECHAZADA => 'high',
        self::TYPE_VENTA_RECHAZADA_ALVARO => 'high',
        self::TYPE_NOMINA_PENDIENTE => 'normal',
        self::TYPE_AUSENCIA_PENDIENTE => 'normal',
        self::TYPE_STOCK_CRITICO => 'normal',
        self::TYPE_CAJA_SIN_CERRAR => 'normal',
        self::TYPE_CITA_NUEVA => 'normal',
        self::TYPE_VENTA_APROBADA => 'normal',
        self::TYPE_PROVEEDOR_NUEVO => 'low',
        self::TYPE_EMPLEADO_NUEVO => 'low',
        self::TYPE_CLIENTE_NUEVO => 'low',
        self::TYPE_RESPALDO_FALTANTE => 'low',
    ];

    // ================================================================
    // ICONOS POR TIPO (para UI)
    // ================================================================
    protected array $typeIcons = [
        // Ventas/Compras
        self::TYPE_VENTA_PENDIENTE_APROBACION => 'shield',
        self::TYPE_VENTA_APROBADA => 'check-circle',
        self::TYPE_VENTA_RECHAZADA => 'x-circle',
        self::TYPE_VENTA_RECHAZADA_ALVARO => 'x-circle',
        self::TYPE_COMPRA_PENDIENTE_APROBACION => 'shopping-cart',
        self::TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN => 'shopping-cart',
        
        // Inventario
        self::TYPE_STOCK_CRITICO => 'alert-triangle',
        self::TYPE_STOCK_AGOTADO => 'alert-octagon',
        self::TYPE_MERMA_REGISTRADA => 'trash-2',
        self::TYPE_PROVEEDOR_NUEVO => 'truck',
        
        // Finanzas
        self::TYPE_CXP_VENCIDA => 'credit-card',
        self::TYPE_CXP_POR_VENCER => 'clock',
        self::TYPE_CXC_VENCIDA => 'dollar-sign',
        self::TYPE_CXC_POR_VENCER => 'clock',
        self::TYPE_CONCILIACION_PENDIENTE => 'refresh-cw',
        self::TYPE_CIERRE_CONTABLE => 'lock',
        self::TYPE_ASIENTO_DESBALANCEADO => 'alert-triangle',
        self::TYPE_BANCO_MOVIMIENTO => 'bank',
        self::TYPE_IVA_DECLARACION => 'file-text',
        
        // Caja
        self::TYPE_CAJA_SIN_CERRAR => 'lock',
        self::TYPE_CAJA_DIFERENCIA => 'alert-triangle',
        
        // RRHH
        self::TYPE_EMPLEADO_NUEVO => 'user-plus',
        self::TYPE_AUSENCIA_PENDIENTE => 'calendar',
        self::TYPE_NOMINA_PENDIENTE => 'banknote',
        self::TYPE_COMISION_GANADA => 'dollar-sign',
        self::TYPE_COMISION_PAGADA => 'check-circle',
        self::TYPE_PROPINA_RECIBIDA => 'gift',
        
        // Agenda/Clientes
        self::TYPE_CITA_NUEVA => 'calendar',
        self::TYPE_CITA_ASIGNADA => 'calendar',
        self::TYPE_CITA_CANCELADA => 'x-circle',
        self::TYPE_CITA_REAGENDADA => 'refresh-cw',
        self::TYPE_CLIENTE_NUEVO => 'user-plus',
        self::TYPE_CLIENTE_CXC_VENCIDA => 'alert-triangle',
        self::TYPE_COTIZACION_SOLICITADA => 'file-text',
        
        // Sistema
        self::TYPE_ALERTA_CRITICA => 'alert-octagon',
        self::TYPE_REPORTE_DIARIO => 'bar-chart-2',
        self::TYPE_RESPALDO_FALTANTE => 'database',
        self::TYPE_RESPALDO_COMPLETADO => 'check-circle',
        self::TYPE_ASIENTO_DESBALANCEADO => 'alert-triangle',
        self::TYPE_IVA_DECLARACION => 'file-text',
    ];

    // ================================================================
    // MÉTODOS CORE
    // ================================================================

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
     * Opcionalmente excluye un usuario (para evitar auto-notificación de Álvaro).
     */
    public function createForRoles(array $roles, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal', ?int $excludeUserId = null): int
    {
        $query = Usuario::whereIn('role', $roles)->where('is_active', true);
        
        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }
        
        $users = $query->get();
        $count = 0;
        
        foreach ($users as $user) {
            $this->createForUser($user->id, $type, $title, $message, $data, $priority);
            $count++;
        }
        
        return $count;
    }

    /**
     * Crea notificación usando el mapeo automático de roles por tipo.
     * Si $excludeActor = true y el usuario actual es Álvaro, no se le notifica.
     */
    public function createByType(string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal', bool $excludeActor = true): int
    {
        $roles = $this->typeToRoles[$type] ?? ['alvaro'];
        $excludeId = ($excludeActor && auth()->check() && auth()->user()->role === 'alvaro') ? auth()->id() : null;
        
        return $this->createForRoles($roles, $type, $title, $message, $data, $priority, $excludeId);
    }

    /**
     * Crea notificación para el cajero específico que realizó la venta.
     */
    public function createForCashier(int $cashierId, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): ?Notification
    {
        $allowedTypes = [
            self::TYPE_VENTA_APROBADA, 
            self::TYPE_VENTA_RECHAZADA,
            self::TYPE_COMISION_GANADA,
            self::TYPE_COMISION_PAGADA,
            self::TYPE_PROPINA_RECIBIDA,
        ];
        
        if (!in_array($type, $allowedTypes)) {
            return null;
        }

        return $this->createForUser($cashierId, $type, $title, $message, $data, $priority);
    }

    /**
     * Crea notificación para un estilista específico.
     */
    public function createForStylist(int $stylistUserId, string $type, string $title, ?string $message = null, array $data = [], string $priority = 'normal'): ?Notification
    {
        $allowedTypes = [
            self::TYPE_CITA_ASIGNADA,
            self::TYPE_CITA_MODIFICADA,
            self::TYPE_COMISION_GANADA,
            self::TYPE_COMISION_PAGADA,
            self::TYPE_PROPINA_RECIBIDA,
        ];
        
        if (!in_array($type, $allowedTypes)) {
            return null;
        }

        return $this->createForUser($stylistUserId, $type, $title, $message, $data, $priority);
    }

    // ================================================================
    // MÉTODOS DE EVENTOS AUTOMÁTICOS (Llamar desde controladores/servicios)
    // ================================================================

    /** Venta creada y retenida para aprobación */
    public function notifyVentaPendienteAprobacion(Venta $venta): void
    {
        $this->createByType(
            self::TYPE_VENTA_PENDIENTE_APROBACION,
            'Nueva venta pendiente de aprobación',
            "Venta #{$venta->id} por C$ {$venta->total} requiere tu autorización.",
            ['sale_id' => $venta->id, 'url' => "/aprobaciones", 'monto' => $venta->total],
            'high'
        );
    }

    /** Venta aprobada por Álvaro → notificar al cajero */
    public function notifyVentaAprobada(Venta $venta, int $aprobadorId): void
    {
        // 1. Notificar al cajero
        $this->createForCashier(
            $venta->cashier_id,
            self::TYPE_VENTA_APROBADA,
            'Venta aprobada',
            "Tu venta #{$venta->id} por C$ {$venta->total} fue aprobada y contabilizada.",
            ['sale_id' => $venta->id, 'url' => "/mis-aprobaciones", 'monto' => $venta->total],
            'high'
        );

        // 2. NO notificar a Álvaro si él la aprobó (auto-notificación filtrada)
        // Pero SÍ notificar a otros admins/contadores si es relevante
        if ($aprobadorId !== $venta->cashier_id) {
            $this->createForRoles(
                ['admin', 'contador'],
                self::TYPE_VENTA_APROBADA,
                'Venta aprobada por supervisor',
                "Venta #{$venta->id} aprobada por " . (Usuario::find($aprobadorId)?->name ?? 'supervisor'),
                ['sale_id' => $venta->id, 'url' => "/historial-ventas"],
                'normal'
            );
        }
    }

    /** Venta rechazada por Álvaro → notificar al cajero + Álvaro (auditoría) */
    public function notifyVentaRechazada(Venta $venta, string $motivo, int $aprobadorId): void
    {
        // 1. Notificar al cajero
        $this->createForCashier(
            $venta->cashier_id,
            self::TYPE_VENTA_RECHAZADA,
            'Venta rechazada',
            "Tu venta #{$venta->id} por C$ {$venta->total} fue rechazada. Motivo: {$motivo}",
            ['sale_id' => $venta->id, 'url' => "/mis-aprobaciones", 'monto' => $venta->total, 'motivo' => $motivo],
            'high'
        );

        // 2. Notificar a Álvaro para auditoría (tipo específico, no se auto-filtra porque es auditoría)
        $this->createForUser(
            $aprobadorId,
            self::TYPE_VENTA_RECHAZADA_ALVARO,
            'Venta rechazada (auditoría)',
            "Rechazaste venta #{$venta->id}. Motivo: {$motivo}",
            ['sale_id' => $venta->id, 'url' => "/aprobaciones", 'motivo' => $motivo],
            'high'
        );
    }

    /** Compra retenida para aprobación (notifica a Álvaro + Admin) */
    public function notifyCompraPendienteAprobacion(FacturaCompra $compra): void
    {
        $this->createByType(
            self::TYPE_COMPRA_PENDIENTE_APROBACION,
            'Compra pendiente de aprobación',
            "Compra #{$compra->id} por C$ {$compra->total} requiere autorización.",
            ['purchase_id' => $compra->id, 'url' => "/aprobaciones", 'monto' => $compra->total],
            'high'
        );
        
        // También a admin para seguimiento
        $this->createForRoles(
            ['admin'],
            self::TYPE_COMPRA_PENDIENTE_APROBACION_ADMIN,
            'Compra pendiente (seguimiento)',
            "Compra #{$compra->id} del proveedor {$compra->proveedor->name} pendiente.",
            ['purchase_id' => $compra->id, 'url' => "/inventario/comprar"],
            'normal'
        );
    }

    /** Stock crítico (≤ stock_min) */
    public function notifyStockCritico(Articulo $articulo): void
    {
        $this->createByType(
            self::TYPE_STOCK_CRITICO,
            'Stock crítico',
            "{$articulo->producto} tiene {$articulo->existencia_actual} {$articulo->unit_measure} (mín: {$articulo->stock_min}).",
            ['item_id' => $articulo->id, 'url' => "/inventario?stock=critico"],
            'normal'
        );
    }

    /** Stock agotado (0) */
    public function notifyStockAgotado(Articulo $articulo): void
    {
        $this->createByType(
            self::TYPE_STOCK_AGOTADO,
            '⚠ STOCK AGOTADO',
            "{$articulo->producto} se ha agotado completamente. Urgente reponer.",
            ['item_id' => $articulo->id, 'url' => "/inventario?stock=agotado"],
            'urgent'
        );
    }

    /** Caja sin cerrar al final del día */
    public function notifyCajaSinCerrar(int $userId): void
    {
        $this->createForUser(
            $userId,
            self::TYPE_CAJA_SIN_CERRAR,
            'Caja sin cerrar',
            'Tienes un turno de caja abierto. Recuerda cerrarlo con arqueo antes de irte.',
            ['url' => "/caja/arqueo"],
            'normal'
        );
    }

    /** Diferencia en arqueo */
    public function notifyCajaDiferencia(int $userId, float $diferencia): void
    {
        $tipo = $diferencia < 0 ? 'FALTANTE' : 'SOBRANTE';
        $this->createForUser(
            $userId,
            self::TYPE_CAJA_DIFERENCIA,
            "Arqueo con {$tipo}",
            "Diferencia de C$ " . number_format(abs($diferencia), 2) . " en tu arqueo.",
            ['url' => "/caja/arqueo", 'diferencia' => $diferencia],
            'high'
        );
        
        // También a Álvaro/Admin
        $this->createForRoles(
            ['admin', 'alvaro'],
            self::TYPE_CAJA_DIFERENCIA,
            "Arqueo con {$tipo} - Cajero: " . (Usuario::find($userId)?->name),
            "Diferencia de C$ " . number_format(abs($diferencia), 2),
            ['url' => "/caja/arqueo", 'cajero_id' => $userId, 'diferencia' => $diferencia],
            'high'
        );
    }

    /** CxP vencida */
    public function notifyCxpVencida(int $proveedorId, float $monto, int $diasVencida): void
    {
        $this->createByType(
            self::TYPE_CXP_VENCIDA,
            'Cuenta por pagar VENCIDA',
            "Proveedor tiene CxP de C$ {$monto} vencida hace {$diasVencida} días.",
            ['proveedor_id' => $proveedorId, 'url' => "/cuentas-por-pagar", 'monto' => $monto],
            'high'
        );
    }

    /** CxP por vencer (3 días) */
    public function notifyCxpPorVencer(int $proveedorId, float $monto, int $dias): void
    {
        $this->createByType(
            self::TYPE_CXP_POR_VENCER,
            'Cuenta por pagar por vencer',
            "Proveedor tiene CxP de C$ {$monto} que vence en {$dias} días.",
            ['proveedor_id' => $proveedorId, 'url' => "/cuentas-por-pagar", 'monto' => $monto],
            'normal'
        );
    }

    /** CxC vencida */
    public function notifyCxcVencida(int $clienteId, float $monto, int $diasVencida): void
    {
        $this->createByType(
            self::TYPE_CXC_VENCIDA,
            'Cuenta por cobrar VENCIDA',
            "Cliente tiene CxC de C$ {$monto} vencida hace {$diasVencida} días.",
            ['client_id' => $clienteId, 'url' => "/adelantos", 'monto' => $monto],
            'high'
        );
    }

    /** Cita nueva/agendada */
    public function notifyCitaNueva(Cita $cita): void
    {
        $this->createByType(
            self::TYPE_CITA_NUEVA,
            'Nueva cita agendada',
            "Cita para {$cita->cliente->name} - {$cita->servicio->name} el {$cita->appointment_date->format('d/m/Y H:i')}.",
            ['appointment_id' => $cita->id, 'url' => "/agenda"],
            'normal'
        );
        
        // Si tiene estilista asignado, notificarle
        if ($cita->estilistas->count() > 0) {
            foreach ($cita->estilistas as $estilista) {
                if ($estilista->user_id) {
                    $this->createForStylist(
                        $estilista->user_id,
                        self::TYPE_CITA_ASIGNADA,
                        'Nueva cita asignada',
                        "Tienes cita con {$cita->cliente->name} el {$cita->appointment_date->format('d/m/Y H:i')}.",
                        ['appointment_id' => $cita->id, 'url' => "/agenda"],
                        'normal'
                    );
                }
            }
        }
    }

    /** Cita cancelada */
    public function notifyCitaCancelada(Cita $cita, string $motivo = ''): void
    {
        $this->createByType(
            self::TYPE_CITA_CANCELADA,
            'Cita cancelada',
            "Cita de {$cita->cliente->name} ({$cita->servicio->name}) cancelada. {$motivo}",
            ['appointment_id' => $cita->id, 'url' => "/agenda"],
            'normal'
        );
        
        if ($cita->estilistas->count() > 0) {
            foreach ($cita->estilistas as $estilista) {
                if ($estilista->user_id) {
                    $this->createForStylist(
                        $estilista->user_id,
                        self::TYPE_CITA_MODIFICADA,
                        'Cita cancelada',
                        "Tu cita con {$cita->cliente->name} fue cancelada.",
                        ['appointment_id' => $cita->id, 'url' => "/agenda"],
                        'normal'
                    );
                }
            }
        }
    }

    /** Cita reagendada */
    public function notifyCitaReagendada(Cita $cita, Carbon $fechaAnterior): void
    {
        $this->createByType(
            self::TYPE_CITA_REAGENDADA,
            'Cita reagendada',
            "Cita de {$cita->cliente->name} movida de {$fechaAnterior->format('d/m/Y H:i')} a {$cita->appointment_date->format('d/m/Y H:i')}.",
            ['appointment_id' => $cita->id, 'url' => "/agenda"],
            'normal'
        );
        
        if ($cita->estilistas->count() > 0) {
            foreach ($cita->estilistas as $estilista) {
                if ($estilista->user_id) {
                    $this->createForStylist(
                        $estilista->user_id,
                        self::TYPE_CITA_MODIFICADA,
                        'Cita reagendada',
                        "Tu cita con {$cita->cliente->name} cambió a {$cita->appointment_date->format('d/m/Y H:i')}.",
                        ['appointment_id' => $cita->id, 'url' => "/agenda"],
                        'normal'
                    );
                }
            }
        }
    }

    /** Comisión ganada por venta */
    public function notifyComisionGanada(int $empleadoUserId, float $monto, int $ventaId): void
    {
        $this->createForUser(
            $empleadoUserId,
            self::TYPE_COMISION_GANADA,
            'Nueva comisión generada',
            "Ganaste C$ {$monto} de comisión por venta #{$ventaId}.",
            ['sale_id' => $ventaId, 'monto' => $monto, 'url' => "/portal/comisiones"],
            'normal'
        );
    }

    /** Comisión pagada en nómina */
    public function notifyComisionPagada(int $empleadoUserId, float $monto, int $nominaId): void
    {
        $this->createForUser(
            $empleadoUserId,
            self::TYPE_COMISION_PAGADA,
            'Comisión pagada',
            "Se pagaron C$ {$monto} de comisiones en nómina #{$nominaId}.",
            ['nomina_id' => $nominaId, 'monto' => $monto, 'url' => "/portal/nominas"],
            'normal'
        );
    }

    /** Propina recibida */
    public function notifyPropinaRecibida(int $empleadoUserId, float $monto): void
    {
        $this->createForUser(
            $empleadoUserId,
            self::TYPE_PROPINA_RECIBIDA,
            'Propina recibida',
            "Recibiste C$ {$monto} de propina. Queda en custodia hasta nómina.",
            ['monto' => $monto, 'url' => "/portal/comisiones"],
            'normal'
        );
    }

    /** Ausencia pendiente de aprobación */
    public function notifyAusenciaPendiente(Ausencia $ausencia): void
    {
        $this->createByType(
            self::TYPE_AUSENCIA_PENDIENTE,
            'Solicitud de ausencia pendiente',
            "{$ausencia->empleado->user->name} solicita {$ausencia->tipoAusencia->nombre} del {$ausencia->fecha_inicio->format('d/m/Y')} al {$ausencia->fecha_fin->format('d/m/Y')}.",
            ['ausencia_id' => $ausencia->id, 'url' => "/ausencias"],
            'normal'
        );
    }

    /** Nómina pendiente de procesar */
    public function notifyNominaPendiente(): void
    {
        $this->createByType(
            self::TYPE_NOMINA_PENDIENTE,
            'Nómina pendiente de procesar',
            'Es hora de procesar la nómina del período actual.',
            ['url' => "/nomina/wizard"],
            'normal'
        );
    }

    /** Respaldos: faltante (no hay backup en 7 días) */
    public function notifyRespaldoFaltante(int $diasSinBackup): void
    {
        $this->createByType(
            self::TYPE_RESPALDO_FALTANTE,
            'Respaldo faltante',
            "No se ha realizado respaldo de base de datos en {$diasSinBackup} días. Riesgo de pérdida de datos.",
            ['url' => "/backups"],
            'low'
        );
    }

    /** Respaldo completado */
    public function notifyRespaldoCompletado(string $archivo, float $tamanoMB): void
    {
        $this->createByType(
            self::TYPE_RESPALDO_COMPLETADO,
            'Respaldo completado',
            "Backup generado: {$archivo} ({$tamanoMB} MB).",
            ['url' => "/backups"],
            'low'
        );
    }

    /** Conciliación bancaria pendiente */
    public function notifyConciliacionPendiente(int $cuentaBancariaId, int $diasSinConciliar): void
    {
        $this->createByType(
            self::TYPE_CONCILIACION_PENDIENTE,
            'Conciliación bancaria pendiente',
            "Cuenta bancaria sin conciliar hace {$diasSinConciliar} días.",
            ['cuenta_bancaria_id' => $cuentaBancariaId, 'url' => "/bancos"],
            'high'
        );
    }

    /** Cierre contable mensual */
    public function notifyCierreContable(int $mes, int $anio): void
    {
        $this->createByType(
            self::TYPE_CIERRE_CONTABLE,
            'Cierre contable mensual',
            "Es hora de realizar el cierre contable de {$mes}/{$anio}.",
            ['url' => "/periodos", 'mes' => $mes, 'anio' => $anio],
            'high'
        );
    }

    /** Alerta crítica genérica (stock 0, caja $0, etc.) */
    public function notifyAlertaCritica(string $titulo, string $mensaje, array $data = []): void
    {
        $this->createByType(
            self::TYPE_ALERTA_CRITICA,
            "🚨 {$titulo}",
            $mensaje,
            $data,
            'urgent'
        );
    }

    /** Reporte diario para Álvaro (resumen fin de día) */
    public function notifyReporteDiario(array $resumen): void
    {
        $mensaje = "Ventas: C$ {$resumen['ventas']} | Cobros: C$ {$resumen['cobros']} | Citas: {$resumen['citas']} | Stock crítico: {$resumen['stock_critico']}";
        $this->createByType(
            self::TYPE_REPORTE_DIARIO,
            '📊 Reporte diario',
            $mensaje,
            ['url' => "/", 'resumen' => $resumen],
            'normal'
        );
    }

    // ================================================================
    // CONSULTAS
    // ================================================================

    public function getUnreadForAuth(int $limit = 10): Collection
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getAllForAuth(int $limit = 20): Collection
    {
        return Notification::where('user_id', auth()->id())
            ->orderByDesc('read')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function countUnreadForAuth(): int
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->count();
    }

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

    public function markAsRead(array $notificationIds): int
    {
        return Notification::whereIn('id', $notificationIds)
            ->where('user_id', auth()->id())
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);
    }

    public function markAllAsRead(): int
    {
        return Notification::where('user_id', auth()->id())
            ->where('read', false)
            ->update(['read' => true, 'read_at' => now()]);
    }

    public function pruneOld(int $days = 30): int
    {
        return Notification::where('read', true)
            ->where('read_at', '<', now()->subDays($days))
            ->delete();
    }

    // ================================================================
    // HELPERS PARA UI
    // ================================================================

    public function getPriority(string $type): string
    {
        return $this->typePriority[$type] ?? 'normal';
    }

    public function getIcon(string $type): string
    {
        return $this->typeIcons[$type] ?? 'bell';
    }

    public function getMenuItemForType(string $type): ?string
    {
        return $this->typeToMenuItem[$type] ?? null;
    }
}