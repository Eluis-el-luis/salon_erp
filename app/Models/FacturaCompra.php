<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FACTURA DE COMPRA (cabecera): documento de ingreso de mercadería del
 * proveedor. Es la fuente de verdad de compras: alimenta el kárdex
 * (stock_ledger_entries), los lotes y el asiento contable COM-*.
 * El costo de las líneas es LANDED: descuento y gastos de envío se reparten
 * proporcionalmente y se capitalizan al inventario.
 */
class FacturaCompra extends Model
{
    use HasFactory;

    protected $table = 'purchase_invoices';

    public const ESTADO_REGISTRADA = 'registrada';

    public const ESTADO_ANULADA = 'anulada';

    protected $fillable = [
        'provider_id',
        'cuenta_por_pagar_id',
        'numero_documento',
        'fecha_factura',
        'condicion_pago',
        'metodo_pago_contado',
        'subtotal',
        'descuento',
        'gastos_envio',
        'impuestos',
        'total',
        'estado',
        'asiento_contable_id',
        'notas',
        'usuario_id',
    ];

    protected $casts = [
        'fecha_factura' => 'date',
        'subtotal' => 'float',
        'descuento' => 'float',
        'gastos_envio' => 'float',
        'impuestos' => 'float',
        'total' => 'float',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'provider_id');
    }

    public function lineas()
    {
        return $this->hasMany(LineaFacturaCompra::class, 'purchase_invoice_id');
    }

    public function cuentaPorPagar()
    {
        return $this->belongsTo(CuentaPorPagar::class, 'cuenta_por_pagar_id');
    }

    public function asiento()
    {
        return $this->belongsTo(AsientoContable::class, 'asiento_contable_id');
    }
}
