<?php

namespace Tests\Support;

use App\Services\AccountingPolicyService;

trait AccountingAdministrationFixtures
{
    use AccountingEventFixtures;
    protected function administrationFixture(string $month='2042-01',bool $publish=true,?int $expenseId=null): array {
        $this->policyActor();
        $bank=\App\Models\CuentaBancaria::where('activa',true)->where('alias','like','AC04B0%')->whereHas('moneda',fn($q)=>$q->where('codigo','NIO'))->orderBy('id')->firstOrFail();
        $slots=$this->policySlots();$bindings=$this->policyBindings();
        foreach(['fondos.banco','gasto.cargo_bancario'] as $role){$s=['role'=>$role,'scope_type'=>'bank_account','resource_id'=>$bank->id];$slots[]=$s;
            $bindings[]=$s+['account_id'=>$role==='fondos.banco'?(int)$bank->cuenta_contable_id:($expenseId??(int)\App\Models\CuentaContable::where('codigo','6.9')->value('id'))];}
        $from=$month.'-01T00:00:00Z';$until=\Carbon\Carbon::parse($month.'-01')->addMonth()->format('Y-m-d').'T00:00:00Z';$service=app(AccountingPolicyService::class);
        $v=$service->createAdministrativeDraft(['kind'=>'enterprise','label'=>'AC04B2B1 SYNTHETIC DOCUMENTARY ONLY','reason'=>'No aprobación económica ni consumidor financiero',
            'valid_from'=>$from,'valid_until'=>$until,'coverage'=>$slots]);
        $v=$service->replaceBindings($v->id,$v->content_hash,$bindings,'Asociaciones hipotéticas expresas');
        if($publish)$v=$service->publish($v->id,$v->content_hash,'SYNTHETIC-TECHNICAL','Publicación de ensayo');
        return [$v,$bank,$from,$until];
    }
}
