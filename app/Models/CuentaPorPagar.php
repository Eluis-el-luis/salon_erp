<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaPorPagar extends Model
{
    protected $table = 'cuentas_por_pagar';
    protected $fillable = [
        'provider_id', 'gasto_id', 'fecha_emision', 
        'fecha_vencimiento', 'monto_original', 'saldo_pendiente', 'estado'
    ];

    
    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    
    public function pagos()
    {
        return $this->hasMany(PagoProveedor::class, 'cuenta_por_pagar_id');
    }
}