<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComisionGenerada extends Model
{
    protected $table = 'comisiones_generadas';
    protected $fillable = [
        'empleado_id', 'venta_id', 'monto_venta', 
        'porcentaje_aplicado', 'monto_comision', 
        'fecha', 'periodo_nomina_id', 'estado'
    ];

    public function empleado()
    {
        return $this->belongsTo(User::class, 'empleado_id');
    }

    public function venta()
    {
        return $this->belongsTo(Sale::class, 'venta_id');
    }
}