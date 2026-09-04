<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiferencialCambiario extends Model
{
    protected $table = 'diferencial_cambiario';

    protected $fillable = [
        'saldo_moneda_id',
        'fecha',
        'monto_moneda_extranjera',
        'tasa_costo_promedio',
        'tasa_revaluacion',
        'diferencia_calculada',
        'tipo',
        'estado',
    ];

    public function saldoMoneda()
    {
        return $this->belongsTo(SaldoMoneda::class, 'saldo_moneda_id');
    }
}