<?php

namespace App\Models;

use App\Models\SaldoMoneda;
use Illuminate\Database\Eloquent\Model;


class DiferencialCambiario extends Model
{
    protected $table = 'diferenciales_cambiarios';
    protected $fillable = [
        'fecha', 'moneda_origen_id', 'moneda_destino_id',
        'monto_origen', 'monto_destino', 'tasa_aplicada', 'diferencia'
    ];

    public function monedaOrigen()
    {
        return $this->belongsTo(SaldoMoneda::class, 'moneda_origen_id');
    }

    public function monedaDestino()
    {
        return $this->belongsTo(SaldoMoneda::class, 'moneda_destino_id');
    }
}
