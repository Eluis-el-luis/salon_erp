<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Services\{AccountingAccountResolver,AccountingPolicyService};
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** Solo documentos hipotéticos; no escrituras físicas/contables ni DDL. */
class AccountingAccountResolverTest extends TestCase
{
    use DatabaseTransactions,AccountingPolicyFixtures;
    private bool $reading=false;
    protected function setUp(): void
    {
        parent::setUp();$this->policyActor();
        DB::connection()->beforeExecuting(function($sql) {
            if($this->reading && (!preg_match('/^\s*(select|show)\b/i',$sql) || preg_match('/\b(GET_LOCK|RELEASE_LOCK)\s*\(/i',$sql)))throw new \LogicException('El resolutor solo puede leer, sin mutex administrativo.');
            if(preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i',$sql)
                && !preg_match('/^(?:insert into|update|delete from) `(accounting_policy_versions|accounting_account_bindings|accounting_policy_audits|cuentas_contables|users)`/i',$sql))throw new \LogicException('Escritura fuera del fixture autorizado.');
        });
    }
    private function published(array $data=[]): object
    {
        $v=$this->readyPolicy($data);return app(AccountingPolicyService::class)->publish($v->id,$v->content_hash,'AC03B-TEST-NOT-BUSINESS','Publicación hipotética para consultar');
    }
    private function inspect(?int $id=null,?array $requests=null,string $at='2070-01-15T00:00:00Z'): array
    {
        $this->reading=true;
        try{return app(AccountingAccountResolver::class)->inspect($requests??[$this->policySlots()[0]],new DateTimeImmutable($at),$id);}
        finally{$this->reading=false;}
    }
    private function rejected(callable $f): void
    {
        try{$f();$this->fail('Debía rechazarse.');}
        catch(ValidationException|\App\Exceptions\ContabilidadException $e){$this->assertNotSame('',$e->getMessage());}
    }
    public function test_global_resolution_returns_evidence_but_never_posting_authority(): void
    {
        $v=$this->published();$r=$this->inspect($v->id,$this->policySlots());
        $this->assertSame('technical_simulation',$r['mode']);$this->assertFalse($r['posting_authorized']);
        $this->assertSame((int)$v->id,$r['policy_version_id']);$this->assertSame($v->content_hash,$r['policy_hash']);
        $this->assertCount(6,$r['resolved']);$this->assertNotEmpty($r['publication_audit_id']);
        foreach($r['resolved'] as $row){$this->assertSame((int)$v->id,$row['policy_version_id']);$this->assertSame('global',$row['scope_key']);$this->assertGreaterThan(0,$row['binding_id']);}
        $this->assertSame($r,$this->inspect(null,$this->policySlots()));
    }
    public function test_specific_resource_requires_exact_scope_and_has_no_global_fallback(): void
    {
        $i=Articulo::where('is_fractionable',true)->firstOrFail();$slots=$this->policySlots();$bindings=$this->policyBindings();
        foreach($slots as &$s)if($s['role']==='inventario.insumos'){$s['scope_type']='item';$s['resource_id']=$i->id;}unset($s);
        foreach($bindings as &$s)if($s['role']==='inventario.insumos'){$s['scope_type']='item';$s['resource_id']=$i->id;}unset($s);
        $v=$this->policyDraft(['coverage'=>$slots]);$v=app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'Fixture específico');
        $v=app(AccountingPolicyService::class)->publish($v->id,$v->content_hash,'AC03B-SPECIFIC','Hipótesis de ámbito');
        $r=$this->inspect($v->id,[['role'=>'inventario.insumos','scope_type'=>'item','resource_id'=>$i->id]]);
        $this->assertSame('item:'.$i->id,$r['resolved'][0]['scope_key']);
        $this->rejected(fn()=>$this->inspect($v->id,[['role'=>'inventario.insumos','scope_type'=>'global','resource_id'=>null]]));
        $this->rejected(fn()=>$this->inspect($v->id,[['role'=>'inventario.insumos','scope_type'=>'item','resource_id'=>PHP_INT_MAX]]));
    }
    public static function badContexts(): array
    {
        return [[['role'=>'unknown.role','scope_type'=>'global','resource_id'=>null]],
            [['role'=>'costo.servicio','scope_type'=>'item','resource_id'=>1]],
            [['role'=>'inventario.reventa','scope_type'=>'global','resource_id'=>1]],
            [['role'=>'inventario.reventa','scope_type'=>'item','resource_id'=>'1']],
            [['role'=>'inventario.reventa','scope_type'=>'global']],
            [['role'=>'inventario.reventa','scope_type'=>'global','resource_id'=>null,'account_id'=>1]],
            [['role'=>'inventario.reventa','scope_type'=>'global','resource_id'=>null,'policy_version_id'=>1]]];
    }
    #[DataProvider('badContexts')]
    public function test_incompatible_or_client_authoritative_context_is_rejected(array $context): void
    {
        $this->rejected(fn()=>$this->inspect(null,[$context]));
    }
    public function test_uncovered_role_does_not_resolve_by_account_prefix(): void
    {
        $v=$this->published();$this->rejected(fn()=>$this->inspect($v->id,[['role'=>'fondos.banco','scope_type'=>'bank_account','resource_id'=>1]]));
    }
    public function test_draft_demo_missing_and_out_of_interval_versions_are_rejected(): void
    {
        $draft=$this->readyPolicy();$demo=$this->readyPolicy(['kind'=>'demo']);
        $this->rejected(fn()=>$this->inspect($draft->id));$this->rejected(fn()=>$this->inspect($demo->id));
        $v=$this->published();$this->rejected(fn()=>$this->inspect(PHP_INT_MAX));
        $this->rejected(fn()=>$this->inspect($v->id,null,'2069-12-31T23:59:59Z'));
        $this->rejected(fn()=>$this->inspect($v->id,null,'2070-02-01T00:00:00Z'));
        $this->assertSame($v->id,$this->inspect($v->id,null,'2069-12-31T18:00:00-06:00')['policy_version_id']);
    }
    public function test_existing_synthetic_2099_publication_is_only_technically_readable(): void
    {
        $v=DB::table('accounting_policy_versions')->where('status','published')->where('valid_from','2099-01-01 00:00:00')->first();
        $this->assertNotNull($v);$r=$this->inspect((int)$v->id,null,'2099-01-15T00:00:00Z');$this->assertFalse($r['posting_authorized']);
        $this->reading=true;
        try{app(AccountingAccountResolver::class)->resolveForPosting();$this->fail('Contabilización no autorizada');}
        catch(\LogicException $e){$this->assertStringContainsString('no habilita contabilización',$e->getMessage());}
        finally{$this->reading=false;}
    }
    public function test_two_eligible_versions_are_rejected_even_with_explicit_id(): void
    {
        $v=$this->published();$copy=(array)$v;unset($copy['id']);$copy['version_number']=(int)DB::table('accounting_policy_versions')->max('version_number')+1;
        // Corrupción simulada vía SQL privilegiado, nunca una publicación del servicio.
        DB::table('accounting_policy_versions')->insert($copy);
        $this->rejected(fn()=>$this->inspect());$this->rejected(fn()=>$this->inspect($v->id));
    }
    public static function badAccounts(): array {return [[['activa'=>false]],[['permite_movimiento'=>false]],[['nombre'=>'Cambio posterior']],[['tipo'=>'gasto']],[['naturaleza'=>'acreedora']]];}
    #[DataProvider('badAccounts')]
    public function test_account_state_or_snapshot_change_rejects_new_resolution(array $change): void
    {
        $v=$this->published();DB::table('cuentas_contables')->where('id',$this->policyBindings()[0]['account_id'])->update($change);
        $this->rejected(fn()=>$this->inspect($v->id));
    }
    public function test_bad_hash_or_missing_publication_audit_rejected_without_writes(): void
    {
        $v=$this->published();DB::table('accounting_policy_versions')->where('id',$v->id)->update(['content_hash'=>str_repeat('a',64)]);
        $this->rejected(fn()=>$this->inspect($v->id));
        DB::table('accounting_policy_versions')->where('id',$v->id)->update(['content_hash'=>$v->content_hash]);
        DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->where('event','published')->delete();
        $this->rejected(fn()=>$this->inspect($v->id));
    }
    public function test_resolution_uses_one_version_for_all_roles_at_a_boundary(): void
    {
        $a=$this->published();$b=$this->published(['valid_from'=>'2070-02-01T00:00:00Z','valid_until'=>'2070-03-01T00:00:00Z']);
        $this->assertSame($a->id,$this->inspect(null,$this->policySlots(),'2070-01-31T23:59:59Z')['policy_version_id']);
        $rows=$this->inspect(null,$this->policySlots(),'2070-02-01T00:00:00Z')['resolved'];
        $this->assertSame([$b->id],array_values(array_unique(array_column($rows,'policy_version_id'))));
    }
    public function test_historical_entries_remain_unattributed_and_unchanged(): void
    {
        $before=DB::table('asientos_contables')->orderBy('id')->get()->toJson();$lines=DB::table('detalle_asientos')->orderBy('id')->get()->toJson();
        $v=$this->published();$this->inspect($v->id,$this->policySlots());
        $this->assertSame($before,DB::table('asientos_contables')->orderBy('id')->get()->toJson());
        $this->assertSame($lines,DB::table('detalle_asientos')->orderBy('id')->get()->toJson());
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('asientos_contables','accounting_policy_version_id'));
        $this->assertSame(0,DB::table('asientos_contables')->whereNotNull('accounting_policy_version_id')->count());
    }
    public function test_non_accounting_identity_cannot_inspect(): void
    {
        DB::table('users')->where('id',auth()->id())->update(['role'=>'recepcion']);
        try{$this->inspect();$this->fail('Debe rechazar identidad.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    /** Simula corrupción con privilegios SQL incluso si alguien recalculó el hash. No es una API de aplicación. */
    private function restampCorruptFixture(int $id): void
    {
        $v=DB::table('accounting_policy_versions')->find($id);$content=app(AccountingPolicyService::class)->contentForInspection($v);
        $hash=hash('sha256',AccountingPolicyService::canonical($content));
        DB::table('accounting_policy_versions')->where('id',$id)->update(['content_hash'=>$hash]);
        $audit=DB::table('accounting_policy_audits')->where('policy_version_id',$id)->where('event','published')->first();
        $e=json_decode($audit->evidence,true);$e['after']=$content;
        DB::table('accounting_policy_audits')->where('id',$audit->id)->update(['after_hash'=>$hash,'evidence'=>AccountingPolicyService::canonical($e)]);
    }
    public function test_incomplete_core_rejected_even_if_content_and_audit_hashes_match(): void
    {
        $v=$this->published();$slots=array_values(array_filter($this->policySlots(),fn($s)=>$s['role']!=='costo.producto'));
        DB::table('accounting_policy_versions')->where('id',$v->id)->update(['coverage_manifest'=>AccountingPolicyService::canonical($slots)]);
        DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->where('semantic_role','costo.producto')->delete();
        $this->restampCorruptFixture($v->id);$this->rejected(fn()=>$this->inspect($v->id));
    }
    public function test_ambiguous_global_and_specific_rejected_even_with_matching_hash(): void
    {
        $v=$this->published();$item=Articulo::where('is_fractionable',true)->firstOrFail();
        $row=(array)DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->where('semantic_role','inventario.insumos')->first();
        unset($row['id'],$row['scope_key']);$row['scope_type']='item';$row['item_id']=$item->id;
        DB::table('accounting_account_bindings')->insert($row);
        $slots=$this->policySlots();$slots[]=['role'=>'inventario.insumos','scope_type'=>'item','resource_id'=>$item->id];
        DB::table('accounting_policy_versions')->where('id',$v->id)->update(['coverage_manifest'=>AccountingPolicyService::canonical($slots)]);
        $this->restampCorruptFixture($v->id);$this->rejected(fn()=>$this->inspect($v->id));
    }
    public function test_incompatible_rules_rejected_even_with_matching_hash(): void
    {
        $v=$this->published();$row=DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->first();
        $rules=json_decode($row->rules_snapshot,true);$rules['type']='gasto';
        DB::table('accounting_account_bindings')->where('id',$row->id)->update(['rules_snapshot'=>AccountingPolicyService::canonical($rules)]);
        $this->restampCorruptFixture($v->id);$this->rejected(fn()=>$this->inspect($v->id));
    }
    public function test_incomplete_audit_rejected_as_validation_error(): void
    {
        $v=$this->published();DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->where('event','published')
            ->update(['evidence'=>json_encode(['version'=>1,'approval_reference'=>$v->approval_reference,'after'=>'invalid'])]);
        $this->rejected(fn()=>$this->inspect($v->id));
    }
}
