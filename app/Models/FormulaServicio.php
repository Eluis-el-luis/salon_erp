<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormulaServicio extends Model
{
    protected $table = 'service_formulas';
    protected $fillable = ['service_id', 'item_id', 'quantity_used'];

    // Una fórmula pertenece a un Servicio
    public function servicio()
    {
        return $this->belongsTo(Servicio::class);
    }

    // Una fórmula usa un Producto (Articulo)
    public function articulo()
    {
        return $this->belongsTo(Articulo::class);
    }
}