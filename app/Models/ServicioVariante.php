<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN: variante de precio de un servicio.
 * Un servicio (Ej. "Alisado de Keratina") puede cobrarse distinto según una
 * variable (Corto/Medio/Largo). La variante puede además definir su propia
 * duración de ejecución para la agenda.
 */
class ServicioVariante extends Model
{
    protected $table = 'service_variants';

    protected $fillable = [
        'service_id',
        'name',
        'price',
        'duration',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'float',
        'duration' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'service_id');
    }

    public function detallesVenta()
    {
        return $this->hasMany(DetalleVenta::class, 'service_variant_id');
    }
}
