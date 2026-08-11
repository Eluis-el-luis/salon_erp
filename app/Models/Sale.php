<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_id',
        'client_id',
        'subtotal',
        'discount',
        'total',
        'currency',
        'exchange_rate',
        'payment_method',
        'observations',
        'status',
    ];

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function details()
    {
        return $this->hasMany(SaleDetail::class);
    }
}