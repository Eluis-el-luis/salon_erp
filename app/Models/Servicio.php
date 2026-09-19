<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Servicio extends Model
{
    use HasFactory;

    protected $table = 'services';

    protected $fillable = [
        'name',
        'price',
        'duration',
        'description',
        'is_active'
    ];

    public function formulas()
    {
        return $this->hasMany(FormulaServicio::class, 'service_id');
    }
}