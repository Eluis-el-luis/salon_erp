<?php

namespace Tests\Feature;

use App\Services\{AccountingEventAuthorizationService as Auth,AccountingEventIdentityService as Identity,AccountingPolicyService};
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingEventFixtures;
use Tests\TestCase;

class AccountingEventDocumentationTest extends TestCase
{
    use DatabaseTransactions,AccountingEventFixtures;
    protected function setUp(): void {
        parent::setUp();$this->policyActor();
        DB::connection()->beforeExecuting(function($sql){if(preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i',$sql)
            &&!preg_match('/^(?:insert into|update|delete from) `accounting_(policy_versions|account_bindings|policy_audits|policy_authorizations|event_operations|entry_line_resolutions)`/i',$sql))throw new \LogicException('Solo documentos de ensayo; ningún escritor financiero o DDL.');});
    }
    private function rejected(callable $f): void {try{$f();$this->fail('Debía rechazarse.');}catch(ValidationException|\LogicException $e){$this->assertNotSame('',$e->getMessage());}}
    private function sqlRejected(callable $f,array $codes=[1452,3819,1062]): void {try{DB::transaction($f);$this->fail('SQL debía rechazar.');}catch(QueryException $e){$this->assertContains($e->errorInfo[1],$codes);}}
    public function test_approval_enablement_and_revocation_are_durable_separate_documents(): void {
        $g=$this->simulationGrant();$s=app(Auth::class);$before=DB::table('accounting_policy_authorizations')->whereIn('id',[$g->id,$g->parent_id])->orderBy('id')->get()->toJson();
        $this->assertSame('simulation',$g->purpose);$this->assertSame('testing',$g->environment);$this->assertSame((int)auth()->id(),(int)$g->actor_id);
        $this->assertSame($g->id,DB::transaction(fn()=>$s->inspectSimulation($g->id,new DateTimeImmutable('2032-01-15T00:00:00Z')))->id);
        $r=$s->revokeForSimulation($g->id,'SYNTHETIC-WITHDRAWAL','Retirada documental');$this->assertSame('revocation',$r->action);
        $this->assertSame($before,DB::table('accounting_policy_authorizations')->whereIn('id',[$g->id,$g->parent_id])->orderBy('id')->get()->toJson());
        $this->rejected(fn()=>DB::transaction(fn()=>$s->inspectSimulation($g->id,new DateTimeImmutable('2032-01-15T00:00:00Z'))));
        $this->rejected(fn()=>$s->revokeForSimulation($g->id,'REPEAT','No duplicar'));
        foreach(DB::table('accounting_policy_authorizations')->get() as $row)$this->assertSame($row->evidence_hash,hash('sha256',AccountingPolicyService::canonical(json_decode($row->evidence,true))));
        $this->assertNotNull($r->recorded_at);
    }
    public function test_published_synthetic_policy_is_never_business_authority(): void {
        $g=$this->simulationGrant();$this->rejected(fn()=>app(Auth::class)->assertBusinessAuthority($g->id));
        $this->rejected(fn()=>app(Identity::class)->confirm());$this->rejected(fn()=>app(\App\Services\AccountingAccountResolver::class)->resolveForPosting());
        $v=$this->readyPolicy();$v=app(AccountingPolicyService::class)->publish($v->id,$v->content_hash,'SYNTHETIC','No empresa');
        $this->rejected(fn()=>app(Auth::class)->approveForSimulation($v->id,$g->bank_account_id,'SYNTHETIC','Sin cobertura piloto'));
    }
    public function test_uuid_and_normalization_prepare_only_and_detect_content_conflict(): void {
        $g=$this->simulationGrant();$i=app(Identity::class);$input=$this->pilotInput();$at=new DateTimeImmutable('2032-01-15T00:00:00Z');
        $a=$i->prepareSimulation($g->id,$input,$at);$input['amount']='00050.0';$input['uuid']=strtoupper($input['uuid']);
        $b=$i->prepareSimulation($g->id,array_reverse($input,true),$at);$this->assertSame($a->id,$b->id);$this->assertSame($a->content_hash,$b->content_hash);
        $this->assertSame('prepared',$a->status);$this->assertNull($a->result_movement_id);$this->assertNull($a->result_asiento_id);
        $input['amount']='50.01';$this->rejected(fn()=>$i->prepareSimulation($g->id,$input,$at));
        $this->rejected(fn()=>$i->confirmedResult($a->operation_uuid));$this->assertSame(1,DB::table('accounting_event_operations')->count());
    }
    public static function invalidAmounts(): array {return [[''],['0'],['-1'],['50.001'],['1e2'],['NaN'],['10000000000000'],[50.0]];}
    #[DataProvider('invalidAmounts')]
    public function test_invalid_precision_or_values_never_reach_pdo(mixed $amount): void {
        $input=$this->pilotInput();$input['amount']=$amount;$this->rejected(fn()=>app(Identity::class)->normalize($input));
    }
    public function test_explicit_classification_document_and_authoritative_fields(): void {
        $i=app(Identity::class);$p=$this->pilotInput();
        foreach([['classification'=>''],['reference'=>''],['recognition_date'=>'2032-02-31'],['recognition_date'=>'0001-01-01'],['uuid'=>'not-a-uuid'],['actor_id'=>auth()->id()],['account_id'=>1],['policy_hash'=>str_repeat('a',64)]] as $change)$this->rejected(fn()=>$i->normalize(array_replace($p,$change)));
        $this->assertSame('commission',$i->normalize(array_replace($p,['classification'=>'commission']))['classification']);
    }
    public function test_manipulated_evidence_and_parent_revocation_reject(): void {
        $g=$this->simulationGrant();$s=app(Auth::class);
        DB::table('accounting_policy_authorizations')->where('id',$g->id)->update(['document_reference'=>'TAMPERED']);
        $this->rejected(fn()=>DB::transaction(fn()=>$s->inspectSimulation($g->id,new DateTimeImmutable('2032-01-15T00:00:00Z'))));
        DB::table('accounting_policy_authorizations')->where('id',$g->id)->update(['document_reference'=>$g->document_reference]);
        $s->revokeForSimulation($g->parent_id,'SYNTHETIC','Retirar aprobación');
        $this->rejected(fn()=>DB::transaction(fn()=>$s->inspectSimulation($g->id,new DateTimeImmutable('2032-01-15T00:00:00Z'))));
    }
    public function test_sql_restricts_cross_scope_parent_uuid_and_legacy_results(): void {
        $g=$this->simulationGrant();$row=(array)$g;unset($row['id']);$row['bank_account_id']=PHP_INT_MAX;
        $this->sqlRejected(fn()=>DB::table('accounting_policy_authorizations')->insert($row));
        $this->sqlRejected(fn()=>DB::table('accounting_policy_authorizations')->where('id',$g->parent_id)->delete(),[1451]);
        $p=$this->pilotInput();$op=app(Identity::class)->prepareSimulation($g->id,$p,new DateTimeImmutable('2032-01-15T00:00:00Z'));
        $copy=(array)$op;unset($copy['id'],$copy['authorization_action']);$this->sqlRejected(fn()=>DB::table('accounting_event_operations')->insert($copy),[1062]);
        $move=DB::table('movimientos_bancarios')->whereNotNull('asiento_contable_id')->first();$this->assertNotNull($move);
        $this->sqlRejected(fn()=>DB::table('accounting_event_operations')->where('id',$op->id)->update(['status'=>'confirmed','result_movement_id'=>$move->id,'result_asiento_id'=>$move->asiento_contable_id]));
        $this->sqlRejected(fn()=>DB::table('accounting_event_operations')->where('id',$op->id)->update(['status'=>'confirmed']),[3819]);
    }
    public function test_line_resolution_has_composite_restrict_foreign_keys_without_writing_entries(): void {
        $keys=DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',DB::connection()->getDatabaseName())->where('TABLE_NAME','accounting_entry_line_resolutions')->get();
        $this->assertCount(3,$keys);foreach($keys as $k){$this->assertSame('RESTRICT',$k->DELETE_RULE);$this->assertSame('RESTRICT',$k->UPDATE_RULE);}
        foreach(['resolution_operation_fk'=>7,'resolution_detail_fk'=>3,'resolution_binding_fk'=>6] as $fk=>$count)$this->assertSame($count,DB::table('information_schema.KEY_COLUMN_USAGE')->where('CONSTRAINT_SCHEMA',DB::connection()->getDatabaseName())->where('CONSTRAINT_NAME',$fk)->count());
        $this->sqlRejected(fn()=>DB::table('accounting_entry_line_resolutions')->insert(['operation_id'=>PHP_INT_MAX,'policy_version_id'=>1,'policy_hash'=>str_repeat('a',64),'authorization_id'=>1,'bank_account_id'=>1,'movement_id'=>1,'asiento_id'=>1,'detail_id'=>1,'binding_id'=>1,'account_id'=>1,'semantic_role'=>'gasto.cargo_bancario','contract_version'=>1,'recorded_at'=>now()]),[1452]);
        $this->assertSame(0,DB::table('accounting_entry_line_resolutions')->count());
    }
    public function test_failure_does_not_leave_partial_authorization_and_legacy_entries_stay_null(): void {
        [$v,$bank]=$this->pilotPolicy();$a=app(Auth::class)->approveForSimulation($v->id,$bank->id,'SYNTHETIC','Aprobación de ensayo');$n=DB::table('accounting_policy_authorizations')->count();
        DB::connection()->beforeExecuting(function($sql,$bindings){if(str_starts_with($sql,'insert into `accounting_policy_authorizations`')&&in_array('enablement',$bindings,true))throw new \RuntimeException('AC04B1 injected');});
        try{app(Auth::class)->enableForSimulation($a->id,'SYNTHETIC','Fallar');$this->fail('Debía fallar');}catch(\RuntimeException $e){$this->assertSame('AC04B1 injected',$e->getMessage());}
        $this->assertSame($n,DB::table('accounting_policy_authorizations')->count());
        $this->assertTrue(Schema::hasColumn('asientos_contables','accounting_policy_version_id'));
        $this->assertSame(0,DB::table('asientos_contables')->whereNotNull('accounting_policy_version_id')->orWhereNotNull('accounting_policy_hash')->orWhereNotNull('accounting_authorization_id')->count());
    }
    public function test_changed_document_columns_and_incomplete_rehashed_evidence_reject(): void {
        $g=$this->simulationGrant();$i=app(Identity::class);$p=$this->pilotInput();$at=new DateTimeImmutable('2032-01-15T00:00:00Z');$op=$i->prepareSimulation($g->id,$p,$at);
        DB::table('accounting_event_operations')->where('id',$op->id)->update(['amount'=>'51.00']);$this->rejected(fn()=>$i->prepareSimulation($g->id,$p,$at));
        $e=AccountingPolicyService::canonical(['version'=>1,'authorization'=>[]]);
        DB::table('accounting_policy_authorizations')->where('id',$g->id)->update(['evidence'=>$e,'evidence_hash'=>hash('sha256',$e)]);
        $this->rejected(fn()=>DB::transaction(fn()=>app(Auth::class)->inspectSimulation($g->id,$at)));
    }
    public function test_composite_keys_prevent_existing_resource_crosses_and_synthetic_adoption(): void {
        $g=$this->simulationGrant();$other=DB::table('cuentas_bancarias')->where('id','<>',$g->bank_account_id)->value('id');$this->assertNotNull($other);
        $r=(array)$g;unset($r['id']);$r['action']='revocation';$r['parent_action']='enablement';$r['parent_id']=$g->id;$r['bank_account_id']=$other;
        $this->sqlRejected(fn()=>DB::table('accounting_policy_authorizations')->insert($r),[1452]);
        $op=app(Identity::class)->prepareSimulation($g->id,$this->pilotInput(),new DateTimeImmutable('2032-01-15T00:00:00Z'));
        $c=(array)$op;unset($c['id'],$c['authorization_action']);$c['operation_uuid']=(string)\Illuminate\Support\Str::uuid();$c['purpose']='business';
        $this->sqlRejected(fn()=>DB::table('accounting_event_operations')->insert($c),[1452]);
    }
    public function test_simulation_writers_reject_local_environment_before_querying(): void {
        $input=$this->pilotInput();$this->app->instance('env','local');
        DB::connection()->beforeExecuting(function(){if(app()->environment('local'))throw new \LogicException('No SQL autorizado fuera de testing');});
        try {
            foreach([fn()=>app(Auth::class)->approveForSimulation(1,1,'REF','reason'),fn()=>app(Auth::class)->enableForSimulation(1,'REF','reason'),
                fn()=>app(Auth::class)->revokeForSimulation(1,'REF','reason'),fn()=>app(Identity::class)->prepareSimulation(1,$input,new DateTimeImmutable())] as $call) {
                try{$call();$this->fail('Debía rechazar fuera de testing');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
            }
        } finally {$this->app->instance('env','testing');}
    }
}
