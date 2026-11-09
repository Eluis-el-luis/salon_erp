<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\Cita;
use App\Models\FacturaCompra;
use App\Models\Notification;
use App\Models\SesionCaja;
use App\Models\Venta;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * FLUJO DE PRE-APROBACIÓN DE TRANSACCIONES SENSIBLES (octubre 2026).
 *
 * Las operaciones sensibles NO tocan el Libro Diario al crearse: quedan
 * retenidas en estado 'pendiente_aprobacion' (sales / purchase_invoices)
 * y solo un supervisor (Álvaro o Administrador) las despacha a contabilidad.
 *
 * - Ventas retenidas: el documento + detalles + consumos reales ya existen,
 *   pero NO se descuenta inventario, NO se registra libro de pagos, NO se
 *   mueven divisas, NO hay comisiones y NO hay asiento. El payload del libro
 *   de pagos se conserva en sales.payload_aprobacion y se ejecuta íntegro
 *   al aprobar (misma ruta de código que el POS vía LibroPagosService).
 * - Compras retenidas: el documento y sus líneas existen, pero NO hay stock,
 *   lotes, kárdex, CxP ni asiento (los ejecuta CompraService::aprobarFactura).
 * - Rechazar una transacción retenida solo cambia el estado (nunca tuvo
 *   efectos): ventas -> 'anulada' con el motivo como auditoría; compras ->
 *   'anulada'.
 *
 * Umbrales: config('salon.aprobaciones') define los montos (C$) que activan
 * la retención automática; las ventas creadas por un rol supervisor están
 * exentas. Un flag explícito del formulario fuerza la retención siempre.
 */
class AprobacionService
{
    /**
     * ¿Esta venta de mostrador debe retenerse para aprobación?
     * Las ventas creadas por un rol supervisor nunca se retienen por umbral.
     */
    public function requiereAprobacionVenta(float $total, ?string $rolCreador = null, bool $flagExplicito = false): bool
    {
        if ($flagExplicito) {
            return true;
        }

        $umbral = (float) config('salon.aprobaciones.monto_venta', 0);
        if ($umbral <= 0) {
            return false;
        }

        $esSupervisor = $rolCreador && in_array($rolCreador, config('salon.aprobaciones.roles_supervisor', ['alvaro', 'admin']), true);

        return !$esSupervisor && $total >= $umbral;
    }

    /**
     * ¿Esta compra debe retenerse para aprobación? El umbral aplica a
     * cualquier creador (gobernanza: compras grandes las aprueba Álvaro).
     */
    public function requiereAprobacionCompra(float $total, ?string $rolCreador = null, bool $flagExplicito = false): bool
    {
        if ($flagExplicito) {
            return true;
        }

        $umbral = (float) config('salon.aprobaciones.monto_compra', 0);

        return $umbral > 0 && $total >= $umbral;
    }

    /**
     * Cola de supervisión: ventas y compras retenidas con sus relaciones mínimas.
     */
    public function pendientes(): array
    {
        return [
            'ventas' => Venta::with(['cliente:id,name', 'cajero:id,name,role'])
                ->where('status', Venta::ESTADO_PENDIENTE_APROBACION)
                ->orderBy('created_at')
                ->get(),
            'compras' => FacturaCompra::with(['proveedor:id,name', 'usuario:id,name'])
                ->where('estado', FacturaCompra::ESTADO_PENDIENTE_APROBACION)
                ->orderBy('created_at')
                ->get(),
        ];
    }

    /**
     * APRUEBA una venta retenida: ejecuta en UNA transacción atómica el
     * inventario, el libro de pagos (con divisas), el asiento contable y
     * las comisiones; marca el documento como pagada con la auditoría del
     * aprobador.
     */
    public function aprobarVenta(int $ventaId, ?int $aprobadorId = null): Venta
    {
        return DB::transaction(function () use ($ventaId, $aprobadorId) {
            $venta = Venta::lockForUpdate()
                ->with(['detalles'])
                ->findOrFail($ventaId);

            if ($venta->status !== Venta::ESTADO_PENDIENTE_APROBACION) {
                throw new ContabilidadException(
                    'La factura #'.$venta->id.' no está retenida para aprobación (estado: '.$venta->status.').'
                );
            }

            // 1. Inventario físico (insumos por fórmula + productos)
            (new InventarioService)->descontarPorVenta($venta);

            // 2. Libro de pagos + divisas físicas (payload guardado al crear)
            $payload = $venta->payload_aprobacion ?? [];
            $pagosRaw = $payload['payments'] ?? null;

            if (is_array($pagosRaw) && count($pagosRaw) > 0) {
                $tasaReferencia = (float) ($pagosRaw[0]['tasa'] ?? 1);

                // Las divisas cobradas están en el turno del cajero que registró
                // la venta; si no existe, en el turno abierto del aprobador.
                $sesionDivisas = SesionCaja::where('user_id', $venta->cashier_id)->where('estado', 'abierta')->first()
                    ?? SesionCaja::where('user_id', $aprobadorId ?? auth()->id())->where('estado', 'abierta')->first();

                $hayUsdEfectivo = collect($pagosRaw)->contains(
                    fn ($p) => ($p['moneda'] ?? null) === 'usd' && ($p['metodo'] ?? null) === 'efectivo'
                );

                if ($hayUsdEfectivo && !$sesionDivisas) {
                    throw new ContabilidadException(
                        'La factura tiene cobros en dólares y no hay un turno de caja abierto para registrar las divisas. '.
                        'El cajero debe abrir su turno antes de aprobar esta venta.'
                    );
                }

                app(LibroPagosService::class)->registrar(
                    $venta,
                    (float) $venta->total,
                    $pagosRaw,
                    $tasaReferencia,
                    $payload['change_currency'] ?? null,
                    $sesionDivisas
                );
            }

            $venta->load('detalles', 'pagos');

            // 3. Asiento contable del ingreso (el asiento solo existe al aprobar)
            $contabilidad = new ContabilidadService;
            $contabilidad->contabilizarVenta($venta);

            // 4. Comisiones congeladas de la venta
            app(ComisionService::class)->registrarPorVenta($venta);

            // 5. Estado final + auditoría de aprobación
            $venta->update([
                'status' => Venta::ESTADO_PAGADA,
                'aprobada_por' => $aprobadorId ?? auth()->id(),
                'aprobada_at' => now(),
                'payload_aprobacion' => null,
            ]);

            // 6. Marcar notificaciones relacionadas como leídas (venta aprobada)
            Notification::where('data->sale_id', $venta->id)
                ->where('type', NotificationService::TYPE_VENTA_PENDIENTE_APROBACION)
                ->where('read', false)
                ->update(['read' => true, 'read_at' => now()]);

            // 7. NOTIFICACIÓN AL CAJERO: venta aprobada
            app(NotificationService::class)->createForCashier(
                $venta->cashier_id,
                NotificationService::TYPE_VENTA_APROBADA,
                'Venta aprobada',
                "Tu venta #{$venta->id} por C$ {$venta->total} fue aprobada y contabilizada.",
                ['sale_id' => $venta->id, 'url' => "/mis-aprobaciones", 'monto' => $venta->total],
                'high'
            );

            // 8. Citas asociadas: se completan recién al aprobar
            if ($venta->appointment_id) {
                Cita::where('id', $venta->appointment_id)
                    ->where('status', 'pendiente')
                    ->update(['status' => 'completada']);
            }

            return $venta->fresh();
        });
    }

    /**
     * RECHAZA una venta retenida. Nunca tuvo efectos contables ni físicos:
     * el documento pasa a 'anulada' con el motivo como registro de auditoría.
     */
    public function rechazarVenta(int $ventaId, string $motivo, ?int $aprobadorId = null): Venta
    {
        return DB::transaction(function () use ($ventaId, $motivo, $aprobadorId) {
            $venta = Venta::lockForUpdate()->findOrFail($ventaId);

            if ($venta->status !== Venta::ESTADO_PENDIENTE_APROBACION) {
                throw new ContabilidadException(
                    'La factura #'.$venta->id.' no está retenida para aprobación (estado: '.$venta->status.').'
                );
            }

            $venta->update([
                'status' => Venta::ESTADO_ANULADA,
                'anulada_at' => now(),
                'anulada_por' => $aprobadorId ?? auth()->id(),
                'motivo_anulacion' => '[Rechazada en aprobación] '.$motivo,
                'payload_aprobacion' => null,
            ]);

            // NOTIFICACIÓN AL CAJERO: venta rechazada
            app(NotificationService::class)->createForCashier(
                $venta->cashier_id,
                NotificationService::TYPE_VENTA_RECHAZADA,
                'Venta rechazada',
                "Tu venta #{$venta->id} por C$ {$venta->total} fue rechazada. Motivo: {$motivo}",
                ['sale_id' => $venta->id, 'url' => "/mis-aprobaciones", 'monto' => $venta->total, 'motivo' => $motivo],
                'high'
            );

            return $venta->fresh();
        });
    }

    /**
     * APRUEBA una compra retenida (delegado en CompraService, dueño de la
     * transacción): inventario, lotes FIFO, kárdex, CxP y asiento COM-*.
     */
    public function aprobarCompra(int $facturaId, ?int $aprobadorId = null): FacturaCompra
    {
        return app(CompraService::class)->aprobarFactura($facturaId, $aprobadorId ?? auth()->id());
    }

    /**
     * RECHAZA una compra retenida (delegado en CompraService).
     */
    public function rechazarCompra(int $facturaId, string $motivo, ?int $aprobadorId = null): FacturaCompra
    {
        return app(CompraService::class)->rechazarFactura($facturaId, $motivo, $aprobadorId ?? auth()->id());
    }
}
