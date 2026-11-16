<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPolicyAudit extends Model
{
    protected $table='accounting_policy_audits';
    public $timestamps=false;
    protected $guarded=['*'];
    protected $casts=['evidence'=>'array'];
    protected static function booted(): void
    {
        static::saving(fn () => throw new \LogicException('La auditoría se agrega transaccionalmente mediante el servicio.'));
        static::deleting(fn () => throw new \LogicException('La auditoría no se elimina.'));
    }
}
