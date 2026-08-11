<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleAsiento extends Model
{
    protected $table = 'detalle_asientos';
    protected $fillable = ['asiento_id', 'cuenta_id', 'centro_costo_id', 'moneda_id', 'tasa_cambio_id', 'debe', 'haber', 'descripcion'];

    public function cuenta()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_id');
    }
}