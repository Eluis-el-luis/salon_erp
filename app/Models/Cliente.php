<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'clients';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
    ];

    // Un cliente puede tener muchas citas a lo largo del tiempo
    public function citas()
    {
        return $this->hasMany(Cita::class, 'client_id');
    }
}