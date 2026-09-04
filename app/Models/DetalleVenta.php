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
        'stylist_id',
        'quantity',
        'unit_price',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class);
    }

    public function articulo()
    {
        return $this->belongsTo(Articulo::class);
    }

    
    public function servicio()
    {
        return $this->belongsTo(Servicio::class);
    }

    public function estilista()
    {
        return $this->belongsTo(Usuario::class, 'stylist_id');
    }
}