<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use HasFactory;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}