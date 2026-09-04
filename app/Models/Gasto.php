<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gasto extends Model
{
    use HasFactory;

    protected $table = 'expenses';

    protected $fillable = [
        'user_id',
        'description',
        'amount',
        'currency',
        'payment_method',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}