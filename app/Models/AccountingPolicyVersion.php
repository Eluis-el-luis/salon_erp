<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPolicyVersion extends Model
{
    protected $table='accounting_policy_versions';
    protected $guarded=['*'];
    protected $casts=['coverage_manifest'=>'array'];
    protected static function booted(): void
    {
        static::saving(fn () => throw new \LogicException('Use AccountingPolicyService; no se modifica directamente una versión.'));
        static::deleting(fn () => throw new \LogicException('La evidencia de versión no se elimina.'));
    }
    public function bindings() {return $this->hasMany(AccountingAccountBinding::class,'policy_version_id');}
    public function audits() {return $this->hasMany(AccountingPolicyAudit::class,'policy_version_id');}
}
