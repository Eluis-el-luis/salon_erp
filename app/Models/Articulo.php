<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Articulo extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'items';

    protected $fillable = [
        'codigo', 
        'ubicacion', 
        'producto', 
        'categoria', 
        'marca',
        'type', 
        'precio_c', 
        'precio_usd', 
        'existencia_actual', 
        'stock_min',
        'provider_id',
        'is_fractionable',
        'unit_measure',
        'total_volume',
        'current_volume',
    ];

    // Las veces que este articulo ha sido agendado
    public function citas()
    {
        return $this->hasMany(Cita::class, 'service_id');
    }

    public function detallesVenta()
    {
        return $this->hasMany(DetalleVenta::class);
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }
}