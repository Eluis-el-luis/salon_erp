<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceFormula extends Model
{
    protected $fillable = ['service_id', 'item_id', 'quantity_used'];

    // Una fórmula pertenece a un Servicio
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // Una fórmula usa un Producto (Item)
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}