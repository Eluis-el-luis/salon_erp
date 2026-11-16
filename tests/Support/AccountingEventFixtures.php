<?php

namespace Tests\Support;

use App\Models\{CuentaBancaria,CuentaContable};
use App\Services\{AccountingPolicyService,AccountingEventAuthorizationService};

trait AccountingEventFixtures
{
    use AccountingPolicyFixtures;
    protected function pilotPolicy(string $from='2032-01-01T00:00:00Z',string $until='2032-02-01T00:00:00Z',?CuentaBancaria $bank=null): array {
        $bank??=CuentaBancaria::where('activa',true)->where('alias','like','AC04B0%')->whereHas('moneda',fn($q)=>$q->where('codigo','NIO'))->orderBy('id')->firstOrFail();
        $slots=$this->policySlots();$bindings=$this->policyBindings();
        foreach(['fondos.banco','gasto.cargo_bancario'] as $role) {
            $s=['role'=>$role,'scope_type'=>'bank_account','resource_id'=>$bank->id];$slots[]=$s;
            $bindings[]=$s+['account_id'=>$role==='fondos.banco'?(int)$bank->cuenta_contable_id:CuentaContable::where('codigo','6.9')->firstOrFail()->id];
        }
        $v=$this->policyDraft(['label'=>'AC04B1 SYNTHETIC ONLY - NO BUSINESS APPROVAL','coverage'=>$slots,'valid_from'=>$from,'valid_until'=>$until]);
        $service=app(AccountingPolicyService::class);$v=$service->replaceBindings($v->id,$v->content_hash,$bindings,'Fixture hipotético sin habilitación financiera');
        return [$service->publish($v->id,$v->content_hash,'SYNTHETIC-TECHNICAL-PUBLICATION','No autorización empresarial'),$bank];
    }
    protected function simulationGrant(?object $v=null,?object $bank=null): object {
        if(!$v)[$v,$bank]=$this->pilotPolicy();$s=app(AccountingEventAuthorizationService::class);
        $a=$s->approveForSimulation($v->id,$bank->id,'SYNTHETIC-APPROVAL','Ensayo sin autorización empresarial');
        return $s->enableForSimulation($a->id,'SYNTHETIC-ENABLEMENT','Consumidor financiero cerrado');
    }
    protected function pilotInput(): array {return ['uuid'=>(string)\Illuminate\Support\Str::uuid(),'classification'=>'maintenance','amount'=>'50.00','recognition_date'=>'2032-01-15','reference'=>'SYNTHETIC-REFERENCE','description'=>'Solo contrato documental de ensayo'];}
}
