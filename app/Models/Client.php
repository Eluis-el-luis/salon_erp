<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
    ];

    // Un cliente puede tener muchas citas a lo largo del tiempo
    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}