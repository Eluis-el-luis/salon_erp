<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'duration',
        'description',
        'is_active'
    ];

    public function formulas()
    {
        return $this->hasMany(ServiceFormula::class);
    }
}