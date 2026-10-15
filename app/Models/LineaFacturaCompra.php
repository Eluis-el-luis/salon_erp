<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * LÍNEA DE FACTURA DE COMPRA: cantidad, costo comprado y costo LANDED final
 * (con descuento/envío repartido), impuesto de compra y datos del lote.
 * costo_unitario_final es el costo que entra a lotes y kárdex.
 */
class LineaFacturaCompra extends Model
{
    use HasFactory;

    protected $table = 'purchase_invoice_lines';

    protected $fillable = [
        'purchase_invoice_id',
        'item_id',
        'descripcion',
        'cantidad',
        'costo_unitario',
        'costo_unitario_final',
        'impuesto_porcentaje',
        'impuesto_monto',
        'valor_neto',
        'total_linea',
        'lote',
        'fecha_vencimiento',
    ];

    protected $casts = [
        'cantidad' => 'float',
        'costo_unitario' => 'float',
        'costo_unitario_final' => 'float',
        'impuesto_porcentaje' => 'float',
        'impuesto_monto' => 'float',
        'valor_neto' => 'float',
        'total_linea' => 'float',
        'fecha_vencimiento' => 'date',
    ];

    public function factura()
    {
        return $this->belongsTo(FacturaCompra::class, 'purchase_invoice_id');
    }

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }

    public function lotes()
    {
        return $this->hasMany(Lote::class, 'purchase_invoice_line_id');
    }
}
