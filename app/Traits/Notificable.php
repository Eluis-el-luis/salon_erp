<?php

namespace App\Traits;

use App\Services\NotificationService;
use App\Models\Venta;
use App\Models\FacturaCompra;
use App\Models\Articulo;
use App\Models\Cita;
use App\Models\Ausencia;
use App\Models\Usuario;
use App\Models\SesionCaja;
use Illuminate\Support\Facades\Auth;

/**
 * TRAIT NOTIFICABLE - Disparadores automáticos de notificaciones.
 * 
 * Úsalo en controladores/servicios con: use Notificable;
 * Luego llama a $this->notifyEvent(...) con el evento correspondiente.
 */
trait Notificable
{
    /**
     * Obtiene el servicio de notificaciones (resuelve desde container).
     */
    protected function notificationService(): NotificationService
    {
        return app(NotificationService::class);
    }

    // ================================================================
    // VENTAS
    // ================================================================

    /** Venta creada y retenida para aprobación */
    protected function notifyVentaPendiente(Venta $venta): void
    {
        $this->notificationService()->notifyVentaPendienteAprobacion($venta);
    }

    /** Venta aprobada por supervisor */
    protected function notifyVentaAprobada(Venta $venta, ?int $aprobadorId = null): void
    {
        $this->notificationService()->notifyVentaAprobada($venta, $aprobadorId ?? Auth::id());
    }

    /** Venta rechazada por supervisor */
    protected function notifyVentaRechazada(Venta $venta, string $motivo, ?int $aprobadorId = null): void
    {
        $this->notificationService()->notifyVentaRechazada($venta, $motivo, $aprobadorId ?? Auth::id());
    }

    // ================================================================
    // COMPRAS
    // ================================================================

    /** Compra retenida para aprobación */
    protected function notifyCompraPendiente(FacturaCompra $compra): void
    {
        $this->notificationService()->notifyCompraPendienteAprobacion($compra);
    }

    // ================================================================
    // INVENTARIO / STOCK
    // ================================================================

    /** Stock crítico (≤ stock_min) */
    protected function notifyStockCritico(Articulo $articulo): void
    {
        $this->notificationService()->notifyStockCritico($articulo);
    }

    /** Stock agotado (0) */
    protected function notifyStockAgotado(Articulo $articulo): void
    {
        $this->notificationService()->notifyStockAgotado($articulo);
    }

    // ================================================================
    // CAJA
    // ================================================================

    /** Caja sin cerrar al final del día */
    protected function notifyCajaSinCerrar(int $userId): void
    {
        $this->notificationService()->notifyCajaSinCerrar($userId);
    }

    /** Diferencia en arqueo */
    protected function notifyCajaDiferencia(int $userId, float $diferencia): void
    {
        $this->notificationService()->notifyCajaDiferencia($userId, $diferencia);
    }

    // ================================================================
    // CITAS / AGENDA
    // ================================================================

    /** Nueva cita agendada */
    protected function notifyCitaNueva(Cita $cita): void
    {
        $this->notificationService()->notifyCitaNueva($cita);
    }

    /** Cita cancelada */
    protected function notifyCitaCancelada(Cita $cita, string $motivo = ''): void
    {
        $this->notificationService()->notifyCitaCancelada($cita, $motivo);
    }

    /** Cita reagendada */
    protected function notifyCitaReagendada(Cita $cita, \Carbon\Carbon $fechaAnterior): void
    {
        $this->notificationService()->notifyCitaReagendada($cita, $fechaAnterior);
    }

    // ================================================================
    // CUENTAS POR PAGAR / COBRAR
    // ================================================================

    /** CxP vencida */
    protected function notifyCxpVencida(int $proveedorId, float $monto, int $diasVencida): void
    {
        $this->notificationService()->notifyCxpVencida($proveedorId, $monto, $diasVencida);
    }

    /** CxP por vencer (3 días) */
    protected function notifyCxpPorVencer(int $proveedorId, float $monto, int $dias): void
    {
        $this->notificationService()->notifyCxpPorVencer($proveedorId, $monto, $dias);
    }

    /** CxC vencida */
    protected function notifyCxcVencida(int $clienteId, float $monto, int $diasVencida): void
    {
        $this->notificationService()->notifyCxcVencida($clienteId, $monto, $diasVencida);
    }

    // ================================================================
    // RRHH
    // ================================================================

    /** Ausencia pendiente de aprobación */
    protected function notifyAusenciaPendiente(Ausencia $ausencia): void
    {
        $this->notificationService()->notifyAusenciaPendiente($ausencia);
    }

    /** Nómina pendiente */
    protected function notifyNominaPendiente(): void
    {
        $this->notificationService()->notifyNominaPendiente();
    }

    // ================================================================
    // COMISIONES / PROPINAS
    // ================================================================

    /** Comisión ganada */
    protected function notifyComisionGanada(int $empleadoUserId, float $monto, int $ventaId): void
    {
        $this->notificationService()->notifyComisionGanada($empleadoUserId, $monto, $ventaId);
    }

    /** Comisión pagada */
    protected function notifyComisionPagada(int $empleadoUserId, float $monto, int $nominaId): void
    {
        $this->notificationService()->notifyComisionPagada($empleadoUserId, $monto, $nominaId);
    }

    /** Propina recibida */
    protected function notifyPropinaRecibida(int $empleadoUserId, float $monto): void
    {
        $this->notificationService()->notifyPropinaRecibida($empleadoUserId, $monto);
    }

    // ================================================================
    // SISTEMA
    // ================================================================

    /** Respaldo faltante */
    protected function notifyRespaldoFaltante(int $diasSinBackup): void
    {
        $this->notificationService()->notifyRespaldoFaltante($diasSinBackup);
    }

    /** Respaldo completado */
    protected function notifyRespaldoCompletado(string $archivo, float $tamanoMB): void
    {
        $this->notificationService()->notifyRespaldoCompletado($archivo, $tamanoMB);
    }

    /** Conciliación bancaria pendiente */
    protected function notifyConciliacionPendiente(int $cuentaBancariaId, int $diasSinConciliar): void
    {
        $this->notificationService()->notifyConciliacionPendiente($cuentaBancariaId, $diasSinConciliar);
    }

    /** Cierre contable mensual */
    protected function notifyCierreContable(int $mes, int $anio): void
    {
        $this->notificationService()->notifyCierreContable($mes, $anio);
    }

    /** Alerta crítica */
    protected function notifyAlertaCritica(string $titulo, string $mensaje, array $data = []): void
    {
        $this->notificationService()->notifyAlertaCritica($titulo, $mensaje, $data);
    }
}