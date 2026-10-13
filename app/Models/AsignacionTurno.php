<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsignacionTurno extends Model
{
    protected $table = 'employee_shifts';

    protected $fillable = ['employee_id', 'shift_id', 'vigente_desde', 'vigente_hasta'];

    protected $casts = [
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'shift_id');
    }
}
