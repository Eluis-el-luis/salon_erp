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
        'time_in',
        'aseo',
        'uniforme',
    ];

    // Relación: Una asistencia pertenece a un empleado (Usuario)
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}