<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoCajaChica extends Model
{
    protected $table = 'movimientos_caja_chica';
    protected $fillable = ['caja_chica_id', 'tipo_gasto_id', 'descripcion', 'monto', 'fecha', 'comprobante_url'];

    public function cajaChica()
    {
        return $this->belongsTo(CajaChica::class, 'caja_chica_id');
    }

    public function tipoGasto()
    {
        return $this->belongsTo(TipoGasto::class, 'tipo_gasto_id');
    }
}