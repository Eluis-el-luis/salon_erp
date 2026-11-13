<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    use HasFactory;

    protected $table = 'appointments';

    protected $fillable = [
        'client_id',
        'service_id',
        'appointment_date',
        'duration_minutes',
        'status',
        'notes',
    ];

    // La cita pertenece a un cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    // La cita es atendida por UNO O VARIOS estilistas
    public function estilistas()
    {
        return $this->belongsToMany(Usuario::class, 'appointment_user', 'appointment_id', 'user_id');
    }   

    // El servicio comercial pertenece al catálogo services.
    public function servicio()
    {
        return $this->belongsTo(Servicio::class, 'service_id');
    }


}
