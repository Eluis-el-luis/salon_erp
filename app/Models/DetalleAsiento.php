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

    public function asiento()
    {
        return $this->belongsTo(AsientoContable::class, 'asiento_id');
    }

    public function centroCosto()
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function moneda()
    {
        return $this->belongsTo(Moneda::class, 'moneda_id');
    }
}