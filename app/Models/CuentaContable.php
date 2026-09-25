<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaContable extends Model
{
    protected $table = 'cuentas_contables';

    protected $fillable = ['codigo', 'nombre', 'tipo', 'naturaleza', 'cuenta_padre_id', 'nivel', 'permite_movimiento', 'activa', 'is_system_account'];

    protected $casts = [
        'permite_movimiento' => 'boolean',
        'activa' => 'boolean',
        'is_system_account' => 'boolean',
    ];

    // Relación para obtener las subcuentas
    public function subcuentas()
    {
        return $this->hasMany(CuentaContable::class, 'cuenta_padre_id');
    }

    public function cuentaPadre()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_padre_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleAsiento::class, 'cuenta_id');
    }
}