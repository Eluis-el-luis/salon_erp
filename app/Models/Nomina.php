<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Nomina extends Model
{
    use HasFactory;

    protected $table = 'payrolls';

    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'active_salary',
        'services_commission',
        'products_commission',
        'extra_bonus',
        'sunday_bonus',
        'salary_advances',
        'loan_payments',
        'total_to_pay',
        'status',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class);
    }
}