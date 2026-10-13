<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turno extends Model
{
    protected $table = 'shifts';

    protected $fillable = ['nombre', 'hora_entrada', 'hora_salida', 'tolerancia_minutos', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function asignaciones()
    {
        return $this->hasMany(AsignacionTurno::class, 'shift_id');
    }
}
