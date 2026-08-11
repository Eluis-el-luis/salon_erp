<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSession extends Model
{
    protected $fillable = [
        'user_id', 'fecha_apertura', 'fecha_cierre', 
        'monto_apertura', 'monto_teorico', 'monto_fisico', 'diferencia', 'estado'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}