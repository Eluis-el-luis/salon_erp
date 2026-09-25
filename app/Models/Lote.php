<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lote extends Model
{
    use HasFactory;

    protected $table = 'lotes';

    protected $fillable = [
        'item_id',
        'lote',
        'fecha_entrada',
        'fecha_vencimiento',
        'cantidad_entrada',
        'cantidad_disponible',
        'costo_unitario',
    ];

    protected $casts = [
        'fecha_entrada' => 'date',
        'fecha_vencimiento' => 'date',
        'cantidad_entrada' => 'float',
        'cantidad_disponible' => 'float',
        'costo_unitario' => 'float',
    ];

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }
}