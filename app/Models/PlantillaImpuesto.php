<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN: plantilla de impuestos enlazable a servicios
 * (Ej. IVA 15% / Exento). Metadato fiscal del catálogo; la determinación del
 * IVA en el asiento de venta se integran en fases posteriores.
 */
class PlantillaImpuesto extends Model
{
    protected $table = 'tax_templates';

    protected $fillable = [
        'name',
        'rate',
        'is_default',
    ];

    protected $casts = [
        'rate' => 'float',
        'is_default' => 'boolean',
    ];

    public function servicios()
    {
        return $this->hasMany(Servicio::class, 'tax_template_id');
    }
}
