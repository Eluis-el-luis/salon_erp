<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN: servicio con inteligencia de precios.
 * Precio base + variantes (Corto/Medio/Largo), categoría para reportes,
 * plantilla de impuestos, duración de ejecución separada del buffer de
 * limpieza, y costo dinámico de insumos calculado desde su fórmula (BOM).
 */
class Servicio extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'name',
        'price',
        'duration',
        'description',
        'is_active',
        'service_category_id',
        'tax_template_id',
        'cleanup_minutes',
    ];

    protected $casts = [
        'price' => 'float',
        'duration' => 'integer',
        'cleanup_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function formulas()
    {
        return $this->hasMany(FormulaServicio::class, 'service_id');
    }

    public function categoria()
    {
        return $this->belongsTo(ServicioCategoria::class, 'service_category_id');
    }

    public function plantillaImpuesto()
    {
        return $this->belongsTo(PlantillaImpuesto::class, 'tax_template_id');
    }

    public function variantes()
    {
        return $this->hasMany(ServicioVariante::class, 'service_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * COSTO DINÁMICO DE INSUMOS: suma de la receta estándar valorada al costo
     * de compra actual de cada mililitro/unidad (promedio ponderado de lotes).
     * Permite conocer el margen bruto ANTES de fijar el precio de venta.
     */
    public function costoInsumos(): float
    {
        $this->loadMissing('formulas.articulo');

        return round($this->formulas->sum(fn (FormulaServicio $formula) => $formula->costoEstimado()), 2);
    }

    public function margenBruto(): float
    {
        return round((float) $this->price - $this->costoInsumos(), 2);
    }

    /** Margen en % sobre el precio de venta; null si no hay costo registrado */
    public function margenPorcentaje(): ?float
    {
        $costo = $this->costoInsumos();

        if ($costo <= 0) {
            return null;
        }

        return round(($this->price - $costo) / $costo * 100, 1);
    }

    /** Duración total que ocupa la silla: ejecución + limpieza (padding de agenda) */
    public function duracionTotal(): int
    {
        return (int) $this->duration + (int) $this->cleanup_minutes;
    }

    /** Rango de precios de las variantes activas [min, max]; null si no tiene */
    public function rangoPrecios(): ?array
    {
        $this->loadMissing('variantes');

        $activas = $this->variantes->where('is_active', true);

        if ($activas->isEmpty()) {
            return null;
        }

        return [(float) $activas->min('price'), (float) $activas->max('price')];
    }
}
