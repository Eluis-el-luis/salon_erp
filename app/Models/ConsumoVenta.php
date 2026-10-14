<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN (BOM dinámico): consumo REAL de insumos por línea de
 * venta. La recepción puede editar cuánto gastó el estilista antes de facturar;
 * la fórmula del servicio queda como quantity_standard para auditar la
 * desviación. InventarioService usa quantity_actual como fuente de verdad para
 * descontar inventario y revertirlo exactamente al anular la factura.
 */
class ConsumoVenta extends Model
{
    protected $table = 'sale_detail_consumptions';

    protected $fillable = [
        'sale_detail_id',
        'item_id',
        'quantity_standard',
        'quantity_actual',
    ];

    protected $casts = [
        'quantity_standard' => 'float',
        'quantity_actual' => 'float',
    ];

    public function detalleVenta()
    {
        return $this->belongsTo(DetalleVenta::class, 'sale_detail_id');
    }

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }

    /** Desviación del consumo real contra la fórmula estándar (para reportes de mermas futuros) */
    public function desviacion(): float
    {
        return round((float) $this->quantity_actual - (float) $this->quantity_standard, 2);
    }
}
