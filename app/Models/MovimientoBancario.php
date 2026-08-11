<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MovimientoBancario extends Model
{
    protected $table = 'movimientos_bancarios';
    protected $fillable = [
        'cuenta_bancaria_id', 'tipo', 'monto', 'fecha', 
        'concepto', 'modulo_origen', 'referencia_id', 'conciliado'
    ];

    public function cuentaBancaria()
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }
}