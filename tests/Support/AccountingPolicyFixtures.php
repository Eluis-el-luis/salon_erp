<?php

namespace Tests\Support;

use App\Models\{CuentaContable,Usuario};
use App\Services\AccountingPolicyService;
use App\Support\AccountingPolicyRoles;

/** Asociaciones hipotéticas explícitas en test. No seed ni configuración empresarial. */
trait AccountingPolicyFixtures
{
    protected function policyActor(): Usuario
    {
        $actor=Usuario::where('is_active',true)->whereIn('role',['alvaro','contador'])->orderBy('id')->firstOrFail();
        $this->actingAs($actor);return $actor;
    }
    protected function policySlots(): array
    {
        return array_map(fn($role)=>['role'=>$role,'scope_type'=>'global','resource_id'=>null],AccountingPolicyRoles::CORE);
    }
    protected function policyBindings(): array
    {
        $codes=['inventario.reventa'=>'1.1.4','inventario.insumos'=>'1.1.5','costo.producto'=>'5.1','costo.servicio'=>'5.2',
            'ingreso.producto'=>'4.4','ingreso.servicio'=>'4.1'];
        return array_map(fn($s)=>$s+['account_id'=>(int)CuentaContable::where('codigo',$codes[$s['role']])->value('id')],$this->policySlots());
    }
    protected function policyDraft(array $extra=[]): object
    {
        return app(AccountingPolicyService::class)->createDraft(array_replace(['kind'=>'enterprise','label'=>'AC03A HYPOTHETICAL TEST ONLY',
            'reason'=>'Fixture sin autorización empresarial ni activación operativa','valid_from'=>'2070-01-01T00:00:00Z',
            'valid_until'=>'2070-02-01T00:00:00Z','coverage'=>$this->policySlots()],$extra));
    }
    protected function readyPolicy(array $extra=[]): object
    {
        $v=$this->policyDraft($extra);
        return app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$this->policyBindings(),'Fixture de asociaciones de ensayo');
    }
}
