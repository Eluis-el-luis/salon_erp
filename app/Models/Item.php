<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use HasFactory;

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

    // Las veces que este servicio ha sido agendado
    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }

    public function saleDetails()
    {
        return $this->hasMany(SaleDetail::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }
}