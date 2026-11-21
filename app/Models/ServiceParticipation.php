<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceParticipation extends Model
{
    protected $table = 'service_participations';
    protected $fillable = ['sale_detail_id', 'sale_id', 'user_id', 'commission_resolution_snapshot'];
    protected $casts = ['commission_resolution_snapshot' => 'array'];
    public function usuario() { return $this->belongsTo(Usuario::class, 'user_id'); }
    public function detalle() { return $this->belongsTo(DetalleVenta::class, 'sale_detail_id'); }
}
