<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingAccountBinding extends Model
{
    protected $table='accounting_account_bindings';
    protected $guarded=['*'];
    protected $casts=['rules_snapshot'=>'array','account_snapshot'=>'array'];
    protected static function booted(): void
    {
        static::saving(fn () => throw new \LogicException('Use AccountingPolicyService para editar asociaciones de borrador.'));
        static::deleting(fn () => throw new \LogicException('Use AccountingPolicyService; no se elimina una asociación publicada.'));
    }
}
