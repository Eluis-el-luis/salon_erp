<?php

namespace App\Services;

use App\Models\Pago;
use App\Models\SesionCaja;
use App\Models\Venta;

/**
 * LIBRO DE PAGOS (pagos mixtos, vuelto multimoneda y divisas físicas).
 *
 * Extraído de VentaController para que el flujo normal del POS, el cobro de
 * facturas a crédito y la APROBACIÓN de ventas retenidas compartan una única
 * implementación (§5.4 de AGENTS.md):
 *  - Los importes de 'pagos' son SIEMPRE en moneda base NIO (valor_nio).
 *  - El vuelto es una línea tipo 'vuelto' con valor_nio EXACTO.
 *  - Los pagos en USD en efectivo ingresan al inventario de divisas y el
 *    vuelto en USD egresa con validación de saldo físico (DivisaService).
 *
 * La bóveda que recibe/mueve las divisas se resuelve así: la sesión explícita
 * si se indica (aprobaciones usan el turno del cajero que registró la venta);
 * en su defecto, el turno abierto del usuario autenticado.
 */
class LibroPagosService
{
    /**
     * Registra el libro de pagos de una venta y mueve las divisas físicas.
     *
     * @param  array  $pagosRaw  [['metodo','moneda','monto','tasa'], ...]
     * @param  string|null  $changeCurrency  Moneda del vuelto ('nio'|'usd')
     * @param  SesionCaja|null  $cajaDivisas  Turno destino de los movimientos de divisas
     * @return array Líneas insertadas en el libro de pagos
     */
    public function registrar(Venta $venta, float $total, array $pagosRaw, float $tasaReferencia, ?string $changeCurrency = null, ?SesionCaja $cajaDivisas = null): array
    {
        $totalRecibidoNio = 0;
        $pagosAInsertar = [];

        foreach ($pagosRaw as $p) {
            $moneda = $p['moneda'];
            $tasa = (float) $p['tasa'];
            $monto = (float) $p['monto'];

            // Legacy: un único pago sin monto -> se cubre el total
            if ($monto <= 0) {
                $monto = $total;
            }

            $valorNio = $moneda === 'usd' ? round($monto * $tasa, 2) : round($monto, 2);
            $totalRecibidoNio += $valorNio;

            $pagosAInsertar[] = [
                'metodo' => $p['metodo'],
                'moneda' => $moneda,
                'monto' => $monto,
                'tasa' => $tasa,
                'valor_nio' => $valorNio,
                'tipo' => 'pago',
            ];
        }

        // Candado de cobertura: el cobro debe cubrir el total (el vuelto parte del exceso)
        $faltante = round($total - $totalRecibidoNio, 2);
        if ($faltante > 0) {
            throw new \App\Exceptions\ContabilidadException(
                'Los pagos registrados no cubren el total de la factura. Faltan C$ '.number_format($faltante, 2).'.'
            );
        }

        // Vuelto multimoneda: si el cliente pagó de más, se devuelve en efectivo
        // en la moneda solicitada (valor_nio exacto para que el asiento cuadre)
        $vueltoNio = round(max(0, $totalRecibidoNio - $total), 2);
        if ($vueltoNio > 0) {
            $monedaVuelto = $changeCurrency === 'usd' ? 'usd' : 'nio';
            $tasaVuelto = $tasaReferencia > 1 ? $tasaReferencia : 1;

            $pagosAInsertar[] = [
                'metodo' => 'efectivo',
                'moneda' => $monedaVuelto,
                'monto' => $monedaVuelto === 'usd' ? round($vueltoNio / $tasaVuelto, 2) : $vueltoNio,
                'tasa' => $tasaVuelto,
                'valor_nio' => $vueltoNio,
                'tipo' => 'vuelto',
            ];
        }

        foreach ($pagosAInsertar as $pp) {
            Pago::create(['venta_id' => $venta->id] + $pp);
        }

        // Multimoneda: los pagos en USD en efectivo aumentan el inventario físico
        // de dólares y el vuelto entregado en USD lo reduce (primero entra, luego sale).
        $cajaActiva = $cajaDivisas
            ?? SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();
        $divisaService = new DivisaService;

        foreach ($pagosAInsertar as $pp) {
            if ($pp['moneda'] !== 'usd' || $pp['metodo'] !== 'efectivo' || !$cajaActiva) {
                continue;
            }

            try {
                if ($pp['tipo'] === 'pago') {
                    $divisaService->ingresarDivisas(
                        'USD',
                        'App\Models\SesionCaja',
                        $cajaActiva->id,
                        (float) $pp['monto'],
                        (float) $pp['tasa']
                    );
                } else {
                    // Vuelto en USD: valida saldo físico disponible en la caja
                    $divisaService->egresarDivisas(
                        'USD',
                        'App\Models\SesionCaja',
                        $cajaActiva->id,
                        (float) $pp['monto'],
                        (float) $pp['tasa']
                    );
                }
            } catch (\Exception $e) {
                throw new \App\Exceptions\ContabilidadException(
                    'No hay suficientes dólares físicos en caja para entregar el vuelto en USD. '.
                    'Entrega el vuelto en córdobas o registra el ingreso de divisas primero.'
                );
            }
        }

        return $pagosAInsertar;
    }
}
