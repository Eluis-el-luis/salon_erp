<?php

namespace App\Services;

use App\Models\Moneda;
use App\Models\SaldoMoneda;
use Carbon\Carbon;

/**
 * MANDAMIENTO MULTIMONEDA:
 * Centraliza el cálculo de Costo Promedio Ponderado del inventario de divisas
 * (USD) con precisión de 6 decimales (decimal(15,6)) para evitar descuadres.
 * Es reutilizado por la Mesa de Cambio y por las ventas cobradas en USD.
 */
class DivisaService
{
    /**
     * El salón RECIBE moneda extranjera (ej. vende y cobra en USD, o compra USD).
     * Recalcula el costo promedio ponderado a 6 decimales.
     */
    public function ingresarDivisas(string $codigoMoneda, string $ubicacionTipo, int $ubicacionId, float $montoMoneda, float $tasa): SaldoMoneda
    {
        $monedaId = Moneda::idPorCodigo($codigoMoneda);

        // Candado de concurrencia: este método siempre se invoca dentro de una transacción
        $saldo = SaldoMoneda::where('moneda_id', $monedaId)
            ->where('ubicacion_tipo', $ubicacionTipo)
            ->where('ubicacion_id', $ubicacionId)
            ->lockForUpdate()
            ->first();

        if (! $saldo) {
            $saldo = SaldoMoneda::create([
                'moneda_id' => $monedaId,
                'ubicacion_tipo' => $ubicacionTipo,
                'ubicacion_id' => $ubicacionId,
                'saldo_actual' => 0,
                'costo_promedio_ponderado' => 0,
            ]);
        }

        $valorTotalAntiguo = $saldo->saldo_actual * $saldo->costo_promedio_ponderado;
        $valorNuevoAgregado = $montoMoneda * $tasa;
        $nuevoSaldo = $saldo->saldo_actual + $montoMoneda;

        $saldo->saldo_actual = $nuevoSaldo;
        $saldo->costo_promedio_ponderado = round(($valorTotalAntiguo + $valorNuevoAgregado) / $nuevoSaldo, 6);
        $saldo->fecha_actualizacion = Carbon::now();
        $saldo->save();

        return $saldo;
    }

    /**
     * El salón ENTREGA moneda extranjera (ej. venta de USD en mesa de cambio).
     * El costo promedio no cambia; solo se restan billetes.
     *
     * @return float Diferencial cambiario: (tasa venta - costo promedio) * monto. Positivo = ganancia.
     */
    public function egresarDivisas(string $codigoMoneda, string $ubicacionTipo, int $ubicacionId, float $montoMoneda, float $tasaVenta): float
    {
        $monedaId = Moneda::idPorCodigo($codigoMoneda);

        // Candado de concurrencia: evita vender los mismos billetes dos veces
        // cuando dos cajeros operan la mesa al mismo tiempo.
        $saldo = SaldoMoneda::where('moneda_id', $monedaId)
            ->where('ubicacion_tipo', $ubicacionTipo)
            ->where('ubicacion_id', $ubicacionId)
            ->lockForUpdate()
            ->first();

        if (! $saldo || $saldo->saldo_actual < $montoMoneda) {
            $disponible = $saldo ? (float) $saldo->saldo_actual : 0;
            throw new \Exception(
                'No hay suficientes divisas físicas para realizar la operación. '.
                'Disponible: $'.number_format($disponible, 2).' — Solicitado: $'.number_format($montoMoneda, 2).'.'
            );
        }

        $diferencial = round(($tasaVenta - $saldo->costo_promedio_ponderado) * $montoMoneda, 2);

        $saldo->saldo_actual -= $montoMoneda;
        $saldo->fecha_actualizacion = Carbon::now();
        $saldo->save();

        return $diferencial;
    }

    public function saldoDivisas(string $codigoMoneda, string $ubicacionTipo, int $ubicacionId): array
    {
        $monedaId = Moneda::idPorCodigo($codigoMoneda);
        $saldo = SaldoMoneda::where('moneda_id', $monedaId)
            ->where('ubicacion_tipo', $ubicacionTipo)
            ->where('ubicacion_id', $ubicacionId)
            ->first();

        return [
            'saldo' => $saldo->saldo_actual ?? 0,
            'costo_promedio' => $saldo->costo_promedio_ponderado ?? 0,
        ];
    }

    /**
     * Bóvedas con saldo de una divisa (para el dashboard de Mesa de Cambio):
     * devuelve el saldo y costo promedio agrupado por ubicación polimórfica.
     */
    public function saldosPorUbicacion(string $codigoMoneda): array
    {
        $monedaId = Moneda::idPorCodigo($codigoMoneda);

        return SaldoMoneda::where('moneda_id', $monedaId)
            ->where('saldo_actual', '>', 0)
            ->orderBy('ubicacion_tipo')
            ->get()
            ->map(fn (SaldoMoneda $s) => [
                'saldo_moneda_id' => $s->id,
                'ubicacion_tipo' => $s->ubicacion_tipo,
                'ubicacion_id' => $s->ubicacion_id,
                'saldo' => (float) $s->saldo_actual,
                'costo_promedio' => (float) $s->costo_promedio_ponderado,
                'valor_libro_nio' => round((float) $s->saldo_actual * (float) $s->costo_promedio_ponderado, 2),
                'ubicacion' => $s->ubicacion, // resuelto vía morphTo
            ])
            ->all();
    }
}
