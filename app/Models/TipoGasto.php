<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoGasto extends Model
{
    protected $table = 'tipos_gasto';
    protected $fillable = ['codigo', 'nombre', 'cuenta_contable_id'];

    // Relación con el Motor Contable
    public function cuentaContable()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }
}