<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    use HasFactory;

    protected $table = 'attendances';

    protected $fillable = [
        'user_id',
        'date',
        'shift_id',
        'time_in',
        'time_out',
        'minutos_tardanza',
        'horas_trabajadas',
        'horas_extras',
        'estado',
        'aseo',
        'uniforme',
    ];

    protected $casts = [
        'aseo' => 'boolean',
        'uniforme' => 'boolean',
    ];

    // Relación: Una asistencia pertenece a un empleado (Usuario)
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'shift_id');
    }
}
