<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'service_id',
        'appointment_date',
        'duration_minutes',
        'status',
        'notes',
    ];

    // La cita pertenece a un cliente
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    // La cita es atendida por UNO O VARIOS estilistas
    public function stylists()
    {
        return $this->belongsToMany(User::class, 'appointment_user', 'appointment_id', 'user_id');
    }   

    // La cita es para un servicio específico (item)
    public function service()
    {
        return $this->belongsTo(Service::class, 'service_id');
    }


}