<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentroCosto extends Model
{
    protected $table = 'centros_costo';
    
    protected $fillable = ['codigo', 'nombre', 'activo'];

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }
}