<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CuentaPorPagar extends Model
{
    protected $table = 'cuentas_por_pagar';

    protected $fillable = [
        'provider_id', 'gasto_id', 'purchase_invoice_id', 'numero_documento', 'fecha_emision',
        'fecha_vencimiento', 'monto_original', 'saldo_pendiente', 'estado',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'monto_original' => 'float',
        'saldo_pendiente' => 'float',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function pagos()
    {
        return $this->hasMany(PagoProveedor::class, 'cuenta_por_pagar_id');
    }

    public function facturaCompra()
    {
        return $this->belongsTo(FacturaCompra::class, 'purchase_invoice_id');
    }

    /**
     * Monto ya abonado a esta obligación (original - saldo pendiente).
     */
    public function montoPagado(): float
    {
        return round((float) $this->monto_original - (float) $this->saldo_pendiente, 2);
    }
}
