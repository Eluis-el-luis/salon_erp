<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'time_in',
        'aseo',
        'uniforme',
    ];

    // Relación: Una asistencia pertenece a un empleado (User)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}