<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetalleVenta extends Model
{
    use HasFactory;

    protected $table = 'sale_details';

    protected $fillable = [
        'sale_id',
        'item_id',
        'service_id',
        'service_variant_id',
        'stylist_id',
        'quantity',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'sale_id');
    }

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }


    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'service_id');
    }

    public function estilista()
    {
        return $this->belongsTo(Usuario::class, 'stylist_id');
    }

    // FASE 1: variante de precio facturada (Corto/Medio/Largo...)
    public function variante()
    {
        return $this->belongsTo(ServicioVariante::class, 'service_variant_id');
    }

    // FASE 1 (BOM dinámico): consumo real de insumos capturado en el POS
    public function consumos()
    {
        return $this->hasMany(ConsumoVenta::class, 'sale_detail_id');
    }
}
