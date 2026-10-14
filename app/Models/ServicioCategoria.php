<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN: categoría de servicios (Ej. Cabello, Maquillaje, Uñas).
 * Agrupa el catálogo para reportes de venta por línea de negocio.
 */
class ServicioCategoria extends Model
{
    protected $table = 'service_categories';

    protected $fillable = [
        'name',
        'description',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function servicios()
    {
        return $this->hasMany(Servicio::class, 'service_category_id');
    }
}
