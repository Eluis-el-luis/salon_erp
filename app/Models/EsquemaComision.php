<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EsquemaComision extends Model
{
    protected $table = 'esquemas_comision';
    protected $fillable = ['empleado_id', 'tipo_servicio', 'porcentaje_comision', 'vigente_desde', 'vigente_hasta'];

    public function empleado()
    {
        return $this->belongsTo(Usuario::class, 'empleado_id');
    }

    public function rangos()
    {
        return $this->hasMany(EsquemaRango::class, 'esquema_comision_id')->orderBy('orden');
    }
}