<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Adelanto extends Model
{
    use HasFactory;

    protected $table = 'advances';

    protected $fillable = [
        'user_id',
        'client_id', 
        'date',
        'amount',
        'type',      
        'description',
    ];

    // Relación con el Empleado
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    // Relación con el Cliente
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }
}