<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PagoProveedor extends Model
{
    protected $table = 'pagos_proveedor';
    protected $fillable = [
        'cuenta_por_pagar_id', 'fecha', 'monto', 
        'forma_pago', 'origen_pago_id', 'usuario_id'
    ];

    // Relación: Un pago pertenece a una cuenta por pagar específica[cite: 4]
    public function cuentaPorPagar()
    {
        return $this->belongsTo(CuentaPorPagar::class, 'cuenta_por_pagar_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}