<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionCaja extends Model
{
    protected $table = 'cash_sessions';
    protected $fillable = [
        'user_id', 'fecha_apertura', 'fecha_cierre', 
        'monto_apertura', 'monto_teorico', 'monto_fisico', 'diferencia', 'estado'
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}