<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transferencia extends Model
{
    protected $table = 'transferencias';

    protected $fillable = [
        'origen_tipo', 'origen_id',
        'destino_tipo', 'destino_id',
        'monto', 'moneda_id', 'concepto', 'referencia_bancaria', 'comision',
        'fecha', 'usuario_id', 'estado',
    ];

    protected $casts = ['monto' => 'float', 'comision' => 'float'];

    // Relación polimórfica con columnas explícitas: la tabla usa
    // origen_tipo/origen_id y destino_tipo/destino_id (no los *_type por defecto).
    public function origen()
    {
        return $this->morphTo(__FUNCTION__, 'origen_tipo', 'origen_id');
    }

    public function destino()
    {
        return $this->morphTo(__FUNCTION__, 'destino_tipo', 'destino_id');
    }

    public function moneda()
    {
        return $this->belongsTo(Moneda::class, 'moneda_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
