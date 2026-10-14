<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FASE 1 ADMINISTRACIÓN: línea de la receta ESTÁNDAR (BOM) de un servicio.
 * Define el consumo estimado para calcular márgenes y sirve como default en el
 * POS; el consumo REAL facturado se fotografía en sale_detail_consumptions.
 */
class FormulaServicio extends Model
{
    use SoftDeletes;

    protected $table = 'service_formulas';
    protected $fillable = ['service_id', 'item_id', 'quantity_used', 'notes', 'sort_order'];

    // Una fórmula pertenece a un Servicio
    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'service_id');
    }

    // Una fórmula usa un Producto (Articulo)
    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }

    /**
     * COSTO ESTIMADO de la línea: consumo estándar × costo de compra actual por
     * unidad de medida (ml/oz o unidad) según los lotes del artículo.
     * 0 cuando el insumo no tiene costo registrado (se avisa en la interfaz).
     */
    public function costoEstimado(): float
    {
        $costoPorMedida = $this->articulo?->costoPorUnidadMedida();

        if ($costoPorMedida === null) {
            return 0.0;
        }

        return round((float) $this->quantity_used * $costoPorMedida, 6);
    }
}
