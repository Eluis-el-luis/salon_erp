<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperacionCambio extends Model
{
    protected $table = 'operaciones_cambio';
    protected $fillable = [
        'fecha', 'tipo', 'moneda_origen_id', 'moneda_destino_id', 
        'monto_origen', 'monto_destino', 'tasa_aplicada', 'tipo_cambio_id', 
        'origen_pago_tipo', 'origen_pago_id', 'cliente_id', 'usuario_id', 'estado'
    ];

    // Polimórfico: De qué caja o cuenta bancaria se sacaron los córdobas para comprar los dólares[cite: 5]
    public function origenPago()
    {
        return $this->morphTo();
    }
}