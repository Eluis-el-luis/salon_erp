<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Merma extends Model
{
    use HasFactory;

    protected $table = 'mermas';

    protected $fillable = [
        'item_id',
        'fecha',
        'tipo',
        'cantidad',
        'valor',
        'motivo',
        'user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cantidad' => 'float',
        'valor' => 'float',
    ];

    public function articulo()
    {
        return $this->belongsTo(Articulo::class, 'item_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}