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

        $saldo = SaldoMoneda::firstOrCreate(
            [
                'moneda_id' => $monedaId,
                'ubicacion_tipo' => $ubicacionTipo,
                'ubicacion_id' => $ubicacionId,
            ],
            ['saldo_actual' => 0, 'costo_promedio_ponderado' => 0]
        );

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

        $saldo = SaldoMoneda::where('moneda_id', $monedaId)
            ->where('ubicacion_tipo', $ubicacionTipo)
            ->where('ubicacion_id', $ubicacionId)
            ->first();

        if (!$saldo || $saldo->saldo_actual < $montoMoneda) {
            throw new \Exception('No hay suficientes divisas físicas para realizar la operación.');
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
}