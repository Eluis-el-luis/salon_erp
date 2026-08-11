<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaldoMoneda extends Model
{
    protected $table = 'saldos_moneda';
    protected $fillable = [
        'moneda_id', 'ubicacion_tipo', 'ubicacion_id', 
        'saldo_actual', 'costo_promedio_ponderado', 'fecha_actualizacion'
    ];

    // Magia polimórfica: Apunta a la Caja o al Banco donde están guardados los dólares[cite: 5]
    public function ubicacion()
    {
        return $this->morphTo();
    }
}