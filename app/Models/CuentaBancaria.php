<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaBancaria extends Model
{
    protected $table = 'cuentas_bancarias';
    protected $fillable = [
        'banco_id', 'numero_cuenta', 'tipo_cuenta', 
        'moneda_id', 'saldo_actual', 'activa'
    ];

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'banco_id');
    }

    // Relación: Cada cuenta acumula todos sus movimientos históricos
    public function movimientos()
    {
        return $this->hasMany(MovimientoBancario::class, 'cuenta_bancaria_id');
    }
}