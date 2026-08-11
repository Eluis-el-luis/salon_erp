<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id', 
        'date',
        'amount',
        'type',      
        'description',
    ];

    // Relación con el Empleado
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relación con el Cliente
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}