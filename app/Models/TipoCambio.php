<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasa de cambio oficial del día (BCN / caja de referencia) usada por la
 * Mesa de Cambio para comparar la tasa negociada ("de calle") contra la
 * oficial y calcular el diferencial por spread.
 */
class TipoCambio extends Model
{
    protected $table = 'tipos_cambio';

    protected $fillable = [
        'fecha', 'moneda_origen_id', 'moneda_destino_id', 'tasa_compra', 'tasa_venta',
    ];

    protected $casts = ['fecha' => 'date', 'tasa_compra' => 'float', 'tasa_venta' => 'float'];

    /**
     * Tasa oficial USD->NIO de una fecha (hoy por defecto). null si no registrada.
     */
    public static function oficialUsdDelDia(?string $fecha = null): ?self
    {
        $idNio = Moneda::idPorCodigo('NIO');
        $idUsd = Moneda::idPorCodigo('USD');

        return static::whereDate('fecha', $fecha ? Carbon::parse($fecha)->toDateString() : Carbon::today()->toDateString())
            ->where('moneda_origen_id', $idUsd)
            ->where('moneda_destino_id', $idNio)
            ->orderByDesc('id')
            ->first();
    }
}
