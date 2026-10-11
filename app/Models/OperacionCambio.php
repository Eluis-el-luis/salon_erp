<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperacionCambio extends Model
{
    protected $table = 'operaciones_cambio';

    protected $fillable = [
        'fecha', 'tipo', 'moneda_origen_id', 'moneda_destino_id',
        'monto_origen', 'monto_destino', 'tasa_aplicada', 'tasa_oficial',
        'tasa_costo_usd', 'diferencial_costo', 'diferencial_oficial',
        'tipo_cambio_id', 'origen_pago_tipo', 'origen_pago_id',
        'destino_pago_tipo', 'destino_pago_id',
        'cliente_id', 'usuario_id', 'estado', 'asiento_contable_id',
    ];

    protected $casts = [
        'monto_origen' => 'float',
        'monto_destino' => 'float',
        'tasa_aplicada' => 'float',
        'tasa_oficial' => 'float',
        'tasa_costo_usd' => 'float',
        'diferencial_costo' => 'float',
        'diferencial_oficial' => 'float',
    ];

    // Polimórfico con prefijo explícito: la tabla usa origen_pago_tipo / origen_pago_id
    public function origenPago()
    {
        return $this->morphTo(__FUNCTION__, 'origen_pago_tipo', 'origen_pago_id');
    }

    // Bóveda donde entra la moneda recibida (puede ser distinta de la entrega)
    public function destinoPago()
    {
        return $this->morphTo(__FUNCTION__, 'destino_pago_tipo', 'destino_pago_id');
    }

    public function asientoContable()
    {
        return $this->belongsTo(AsientoContable::class, 'asiento_contable_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function monedaOrigen()
    {
        return $this->belongsTo(Moneda::class, 'moneda_origen_id');
    }

    public function monedaDestino()
    {
        return $this->belongsTo(Moneda::class, 'moneda_destino_id');
    }
}
