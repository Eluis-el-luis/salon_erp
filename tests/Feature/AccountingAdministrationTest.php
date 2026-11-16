<?php

namespace Tests\Feature;

use App\Services\{AccountingEventAuthorizationService as Authorizations,AccountingPolicyService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingAdministrationFixtures;
use Tests\TestCase;

class AccountingAdministrationTest extends TestCase
{
    use DatabaseTransactions,AccountingAdministrationFixtures;
    private function denied(callable $f): void {
        try{$f();$this->fail('Debía rechazarse');}catch(ValidationException|\LogicException|\App\Exceptions\ContabilidadException $e){$this->assertNotSame('',$e->getMessage());}
    }
    private function approve(array $fixture): object {
        [$v,$b,$f,$u]=$fixture;return app(Authorizations::class)->approveForAdministration($v->id,$b->id,$v->content_hash,$f,$u,'SYNTHETIC-ACTA','Revisión documental de ensayo');
    }
    public function test_creation_origin_review_approval_enablement_withdrawal_preserve_evidence_without_effects(): void {
        $tables=['asientos_contables','detalle_asientos','movimientos_bancarios','accounting_event_operations','accounting_entry_line_resolutions'];$before=[];foreach($tables as $t)$before[$t]=DB::table($t)->get()->toJson();$banks=DB::table('cuentas_bancarias')->get()->toJson();
        $x=$this->administrationFixture();[$v,$b,$f,$u]=$x;$review=app(Authorizations::class)->reviewForAdministration($v->id,$b->id);
        $this->assertTrue($review['origin']['synthetic']);$this->assertFalse($review['origin']['economic_approval']);$this->assertCount(8,$review['content']['bindings']);
        $a=$this->approve($x);$aBefore=DB::table('accounting_policy_authorizations')->find($a->id);$e=json_decode($a->evidence,true);
        $this->assertSame((int)auth()->id(),(int)$a->actor_id);$this->assertSame('business',$a->purpose);$this->assertFalse($e['administration']['financial_authority']);
        $g=app(Authorizations::class)->enableForAdministration($a->id,$f,$u,'SYNTHETIC','Habilitación documental');
        $this->denied(fn()=>app(Authorizations::class)->enableForAdministration($a->id,$f,$u,'SYNTHETIC','Duplicado'));
        $r=app(Authorizations::class)->revokeForAdministration($g->id,'SYNTHETIC','Retirada documentada');
        $this->assertSame((int)$g->id,(int)$r->parent_id);$this->assertEquals($aBefore,DB::table('accounting_policy_authorizations')->find($a->id));
        $this->denied(fn()=>app(Authorizations::class)->revokeForAdministration($g->id,'SYNTHETIC','Duplicado'));
        $this->denied(fn()=>app(Authorizations::class)->assertBusinessAuthority($g->id));
        $this->denied(fn()=>app(\App\Services\AccountingAccountResolver::class)->resolveForPosting());
        $this->denied(fn()=>DB::transaction(fn()=>app(Authorizations::class)->inspectSimulation($g->id,new \DateTimeImmutable($f))));
        foreach($before as $t=>$value)$this->assertSame($value,DB::table($t)->get()->toJson(),$t);$this->assertSame($banks,DB::table('cuentas_bancarias')->get()->toJson());
    }
    public static function invalid(): array {return array_map(fn($s)=>[$s],['old_origin','tampered_origin','tampered_hash','blank_reference','blank_reason','outside_window','draft','bank_mismatch','inactive_account','expired','client_origin']);}
    #[DataProvider('invalid')]
    public function test_invalid_contract_is_rejected_without_acts(string $case): void {
        $x=$this->administrationFixture('2042-01',$case!=='draft');[$v,$b,$f,$u]=$x;$service=app(Authorizations::class);$count=DB::table('accounting_policy_authorizations')->count();
        if($case==='old_origin'){[$v,$b]=$this->pilotPolicy('2043-01-01T00:00:00Z','2043-02-01T00:00:00Z');$x=[$v,$b,'2043-01-01T00:00:00Z','2043-02-01T00:00:00Z'];}
        if($case==='tampered_origin'){$a=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->where('event_sequence',1)->first();$e=json_decode($a->evidence,true);$e['origin']['created_by']=PHP_INT_MAX;DB::table('accounting_policy_audits')->where('id',$a->id)->update(['evidence'=>json_encode($e)]);}
        if($case==='tampered_hash')$x[0]=clone $v;
        if($case==='tampered_hash')$x[0]->content_hash=str_repeat('0',64);
        if($case==='outside_window')$x[2]='2041-01-01T00:00:00Z';
        if($case==='bank_mismatch')$x[1]=(object)['id'=>PHP_INT_MAX];
        if($case==='inactive_account')DB::table('cuentas_contables')->where('codigo','6.9')->update(['activa'=>false]);
        if($case==='expired')\Carbon\Carbon::setTestNow('2044-01-01');
        try {
            if($case==='blank_reference'||$case==='blank_reason')$this->denied(fn()=>$service->approveForAdministration($v->id,$b->id,$v->content_hash,$f,$u,$case==='blank_reference'?'':'SYNTHETIC',$case==='blank_reason'?'':'Ensayo'));
            elseif($case==='client_origin')$this->denied(fn()=>app(AccountingPolicyService::class)->createAdministrativeDraft(['kind'=>'enterprise','origin'=>['workflow'=>'accounting_administration']]));
            else $this->denied(fn()=>$this->approve($x));
        }finally{\Carbon\Carbon::setTestNow();}
        $this->assertSame($count,DB::table('accounting_policy_authorizations')->count());
    }
    public function test_audit_failure_rolls_back_approval_document(): void {
        $x=$this->administrationFixture();$count=DB::table('accounting_policy_authorizations')->count();$level=DB::transactionLevel();
        DB::connection()->beforeExecuting(function($sql){if(str_starts_with($sql,'select * from `accounting_policy_authorizations` where `id` ='))throw new \RuntimeException('Injected documentary failure');});
        try{$this->approve($x);$this->fail('Debía fallar');}catch(\RuntimeException $e){$this->assertSame('Injected documentary failure',$e->getMessage());}
        $this->assertSame($count,DB::table('accounting_policy_authorizations')->count());$this->assertSame($level,DB::transactionLevel());
    }
    public function test_origin_audit_failure_rolls_back_creation_and_origin_cannot_be_attached_later(): void {
        $this->policyActor();$service=app(AccountingPolicyService::class);$before=DB::table('accounting_policy_versions')->count();$audits=DB::table('accounting_policy_audits')->count();
        DB::connection()->beforeExecuting(function($sql){if(str_starts_with($sql,'insert into `accounting_policy_audits`'))throw new \RuntimeException('Injected origin audit failure');});
        try{$service->createAdministrativeDraft(['kind'=>'enterprise','label'=>'SYNTHETIC','reason'=>'Ensayo','valid_from'=>'2044-01-01T00:00:00Z','valid_until'=>'2044-02-01T00:00:00Z','coverage'=>$this->policySlots()]);$this->fail('Debía fallar');}
        catch(\RuntimeException $e){$this->assertSame('Injected origin audit failure',$e->getMessage());}
        $this->assertSame($before,DB::table('accounting_policy_versions')->count());$this->assertSame($audits,DB::table('accounting_policy_audits')->count());
    }
    public function test_http_session_csrf_permissions_and_documentary_forms(): void {
        $x=$this->administrationFixture();[$v,$b,$f,$u]=$x;$url='/contabilidad/politicas/ensayo/'.$v->id;
        $this->get($url.'?bank='.$b->id)->assertOk()->assertSee('Datos sintéticos')->assertSee($v->content_hash);
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,EnforcedAccountingAdministrationCsrf::class);
        $this->withSession(['_token'=>'test-admin-csrf']);$payload=['bank'=>$b->id,'hash'=>$v->content_hash,'from'=>$f,'until'=>$u,'reference'=>'SYNTHETIC','reason'=>'Ensayo documental'];
        $this->post($url.'/approve',$payload)->assertStatus(419);
        $this->post($url.'/approve',$payload+['_token'=>'test-admin-csrf'])->assertRedirect();
        $this->post($url.'/approve',$payload+['_token'=>'test-admin-csrf','actor_id'=>auth()->id()])->assertSessionHasErrors();
        $a=DB::table('accounting_policy_authorizations')->where('policy_version_id',$v->id)->where('action','approval')->first();
        $this->post('/contabilidad/politicas/ensayo/'.$a->id.'/enable',['_token'=>'test-admin-csrf','from'=>$f,'until'=>$u,'reference'=>'SYNTHETIC','reason'=>'Ensayo habilitación'])->assertRedirect();
        $g=DB::table('accounting_policy_authorizations')->where('parent_id',$a->id)->where('action','enablement')->first();
        $this->post('/contabilidad/politicas/ensayo/'.$g->id.'/revoke',['_token'=>'test-admin-csrf','reference'=>'SYNTHETIC','reason'=>'Ensayo retirada'])->assertRedirect();
        $this->get($url.'?bank='.$b->id)->assertOk()->assertSee('retirado');
        $actor=auth()->user();$old=$actor->role;
        foreach(['admin','estilista','recepcion'] as $role){$actor->update(['role'=>$role]);$this->actingAs($actor);$this->get('/contabilidad/politicas/ensayo')->assertForbidden();
            foreach([fn()=>app(Authorizations::class)->reviewForAdministration($v->id,$b->id),fn()=>$this->approve($x),fn()=>app(Authorizations::class)->enableForAdministration($a->id,$f,$u,'SYNTHETIC','Ensayo'),fn()=>app(Authorizations::class)->revokeForAdministration($g->id,'SYNTHETIC','Ensayo')] as $call)
                try{$call();$this->fail('Sin permiso');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}}
        foreach(['alvaro','contador'] as $role){$actor->update(['role'=>$role]);$this->actingAs($actor);$this->get($url.'?bank='.$b->id)->assertOk();}
        $actor->update(['role'=>$old]);$this->actingAs($actor);$this->app->instance('env','local');
        try{$this->get('/contabilidad/politicas/ensayo')->assertNotFound();}finally{$this->app->instance('env','testing');}
        auth()->logout();$this->get('/contabilidad/politicas/ensayo')->assertRedirect('/login');
    }
}
class EnforcedAccountingAdministrationCsrf extends \Illuminate\Foundation\Http\Middleware\PreventRequestForgery
{
    protected function runningUnitTests(){return false;}
}
