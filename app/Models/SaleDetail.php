<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'item_id',
        'service_id',
        'stylist_id',
        'quantity',
        'unit_price',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function stylist()
    {
        return $this->belongsTo(User::class, 'stylist_id');
    }
}