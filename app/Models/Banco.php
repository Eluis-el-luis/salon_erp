<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banco extends Model
{
    protected $table = 'bancos';
    protected $fillable = ['codigo', 'nombre', 'activo'];

    // Relación: Un banco puede tener varias cuentas bancarias del negocio
    public function cuentas()
    {
        return $this->hasMany(CuentaBancaria::class, 'banco_id');
    }
}