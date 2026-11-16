<?php

namespace Tests\Feature;

use App\Models\{AccountingPolicyVersion,AccountingAccountBinding,AccountingPolicyAudit,CuentaContable};
use App\Services\{AccountingPolicyService,AccountIntegrityService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

class AccountingPolicyTest extends TestCase
{
    use DatabaseTransactions,AccountingPolicyFixtures;
    protected function setUp(): void
    {
        parent::setUp();$this->policyActor();
        DB::connection()->beforeExecuting(function($sql){
            if(preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i',$sql)
                && !preg_match('/^(?:insert into|update|delete from) `(accounting_policy_versions|accounting_account_bindings|accounting_policy_audits|cuentas_contables|users)`/i',$sql)) {
                throw new \LogicException('La política no puede emitir operaciones físicas/financieras o DDL.');
            }
        });
    }
    private function rejected(callable $callback): void
    {
        try{$callback();$this->fail('Debía rechazarse.');}catch(ValidationException|\App\Exceptions\ContabilidadException $e){$this->assertNotSame('',$e->getMessage());}
    }
    private function publish(object $v): object
    {
        return app(AccountingPolicyService::class)->publish($v->id,$v->content_hash,'TEST-APPROVAL-NOT-BUSINESS','Publicación hipotética, sin uso operativo');
    }
    public function test_publish_and_audit_preserve_history_and_exact_canonical_hash(): void
    {
        $v=$this->readyPolicy();$actor=auth()->id();$p=$this->publish($v);
        $this->assertSame('published',$p->status);$this->assertSame($actor,(int)$p->created_by);$this->assertSame($actor,(int)$p->approved_by);
        $this->assertNotSame($v->content_hash,$p->content_hash); // Incluye evidencia e identidad de publicación.
        $events=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->orderBy('event_sequence')->get();
        $this->assertSame(['created','bindings_changed','published'],$events->pluck('event')->all());
        foreach($events as $event){$data=json_decode($event->evidence,true);$this->assertSame($event->after_hash,hash('sha256',AccountingPolicyService::canonical($data['after'])));}
        $this->assertCount(6,AccountingPolicyVersion::find($v->id)->bindings);
        $this->assertNotNull($p->published_at);
    }
    public function test_draft_header_change_is_audited_and_demo_cannot_be_promoted(): void
    {
        $v=$this->readyPolicy(['kind'=>'demo']);$this->rejected(fn()=>$this->publish($v));
        $service=app(AccountingPolicyService::class);
        $revised=$service->reviseDraft($v->id,$v->content_hash,['label'=>'Reviewed fixture','reason'=>'Cambio de borrador']);
        $this->assertNotSame($v->content_hash,$revised->content_hash);$this->assertSame('demo',$revised->kind);
        $this->rejected(fn()=>$service->reviseDraft($revised->id,$revised->content_hash,['kind'=>'enterprise','reason'=>'No permitido']));
        $this->assertTrue(DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->where('event','draft_changed')->exists());
    }
    public function test_published_versions_and_bindings_have_no_direct_model_write_path(): void
    {
        $v=$this->publish($this->readyPolicy());
        $this->rejected(fn()=>app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,[],'No permitido'));
        $this->rejected(fn()=>app(AccountingPolicyService::class)->reviseDraft($v->id,$v->content_hash,['reason'=>'No permitido']));
        foreach([AccountingPolicyVersion::find($v->id),AccountingAccountBinding::where('policy_version_id',$v->id)->first(),AccountingPolicyAudit::where('policy_version_id',$v->id)->first()] as $model) {
            try{$model->forceFill(['id'=>$model->id])->save();$this->fail('Modelo debía ser de lectura.');}catch(\LogicException $e){$this->assertNotSame('',$e->getMessage());}
            try{$model->delete();$this->fail('Modelo debía preservar evidencia.');}catch(\LogicException $e){$this->assertNotSame('',$e->getMessage());}
        }
    }
    public function test_missing_bindings_ambiguous_scopes_and_stale_hash_are_rejected(): void
    {
        $v=$this->policyDraft();$this->rejected(fn()=>$this->publish($v));
        $bindings=$this->policyBindings();$bindings[]=$bindings[0];
        $this->rejected(fn()=>app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'Duplicado'));
        $bindings=$this->policyBindings();$bindings[]=$bindings[0];$bindings[6]['scope_type']='item';$bindings[6]['resource_id']=1;
        $this->rejected(fn()=>app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'Ambiguo'));
        $this->rejected(fn()=>app(AccountingPolicyService::class)->replaceBindings($v->id,str_repeat('a',64),[],'Hash obsoleto'));
        $this->assertSame(0,DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->count());
    }
    public static function accountProblems(): array {return [['missing'],['inactive'],['group'],['type'],['nature']];}
    #[DataProvider('accountProblems')]
    public function test_invalid_accounts_cannot_be_bound(string $case): void
    {
        $v=$this->policyDraft();$bindings=$this->policyBindings();$id=$bindings[0]['account_id'];
        if($case==='missing')$bindings[0]['account_id']=PHP_INT_MAX;
        else DB::table('cuentas_contables')->where('id',$id)->update(match($case){
            'inactive'=>['activa'=>false],'group'=>['permite_movimiento'=>false],'type'=>['tipo'=>'gasto'],'nature'=>['naturaleza'=>'acreedora']});
        $this->rejected(fn()=>app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'Cuenta inválida'));
        $this->assertSame(0,DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->count());
    }
    public function test_account_change_requires_explicit_refresh_and_dependencies_block_deactivation(): void
    {
        $v=$this->readyPolicy();$a=CuentaContable::findOrFail($this->policyBindings()[0]['account_id']);
        $this->assertContains('accounting_account_bindings',app(AccountIntegrityService::class)->dependencies($a->id));
        app(AccountIntegrityService::class)->save(['nombre'=>'Nombre cambiado durante preparación'],$a->id);
        $this->rejected(fn()=>$this->publish($v));
        $v=app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$this->policyBindings(),'Revisión explícita de cuenta');
        $this->assertSame('published',$this->publish($v)->status);
    }
    public function test_non_system_bound_account_cannot_be_deactivated_or_deleted(): void
    {
        $v=$this->readyPolicy();$id=$this->policyBindings()[0]['account_id'];
        DB::table('cuentas_contables')->where('id',$id)->update(['is_system_account'=>false]);
        $this->rejected(fn()=>app(AccountIntegrityService::class)->save(['nombre'=>'No cambiar','tipo'=>'activo','activa'=>false],$id));
        try{app(AccountIntegrityService::class)->remove($id);$this->fail('No se debe borrar.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_half_open_validity_accepts_consecutive_and_rejects_overlap(): void
    {
        $this->publish($this->readyPolicy());
        $overlap=$this->readyPolicy(['valid_from'=>'2070-01-15T00:00:00Z','valid_until'=>'2070-02-15T00:00:00Z']);
        $this->rejected(fn()=>$this->publish($overlap));
        $next=$this->readyPolicy(['valid_from'=>'2070-02-01T00:00:00Z','valid_until'=>'2070-03-01T00:00:00Z']);
        $this->assertSame('published',$this->publish($next)->status);
    }
    public function test_audit_failure_rolls_back_publication_and_keeps_draft(): void
    {
        $v=$this->readyPolicy();$count=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->count();
        DB::connection()->beforeExecuting(function($sql,$bindings){if(str_starts_with($sql,'insert into `accounting_policy_audits`') && in_array('published',$bindings,true))throw new \RuntimeException('AC03A injected audit failure');});
        try{$this->publish($v);$this->fail('Debía fallar.');}catch(\RuntimeException $e){$this->assertSame('AC03A injected audit failure',$e->getMessage());}
        $row=DB::table('accounting_policy_versions')->find($v->id);
        $this->assertSame('draft',$row->status);$this->assertNull($row->approved_by);$this->assertNull($row->published_at);
        $this->assertSame($count,DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->count());
        $this->assertSame($v->content_hash,$row->content_hash);
    }
    public function test_client_authoritative_fields_and_non_accounting_identity_rejected(): void
    {
        $this->rejected(fn()=>app(AccountingPolicyService::class)->createDraft(['kind'=>'enterprise','approved_by'=>auth()->id()]));
        DB::table('users')->where('id',auth()->id())->update(['role'=>'recepcion']);
        try{$this->policyDraft();$this->fail('Identidad no contable.');}
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    private function sqlRejected(callable $action,array $codes): void
    {
        try{DB::transaction($action);$this->fail('SQL debía rechazar.');}catch(QueryException $e){$this->assertContains($e->errorInfo[1],$codes);}
    }
    public function test_sql_foreign_unique_check_and_restrict_constraints(): void
    {
        $v=$this->readyPolicy();$row=(array)DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->first();
        unset($row['id'],$row['scope_key']);
        $this->sqlRejected(fn()=>DB::table('accounting_account_bindings')->insert($row),[1062]);
        $this->sqlRejected(fn()=>DB::table('accounting_account_bindings')->insert(array_replace($row,['semantic_role'=>'missing.role','account_id'=>PHP_INT_MAX])),[1452]);
        $this->sqlRejected(fn()=>DB::table('accounting_account_bindings')->insert(array_replace($row,['semantic_role'=>'bad.scope','scope_type'=>'item'])),[3819]);
        $this->sqlRejected(fn()=>DB::table('accounting_policy_versions')->where('id',$v->id)->delete(),[1451]);
        $this->sqlRejected(fn()=>DB::table('accounting_policy_versions')->where('id',$v->id)->update(['status'=>'published']),[3819]);
        $this->sqlRejected(fn()=>DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->update(['event'=>'invalid']),[3819]);
        $fks=DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME',['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'])->get();
        $this->assertCount(8,$fks);foreach($fks as $fk){$this->assertSame('RESTRICT',$fk->DELETE_RULE);$this->assertSame('RESTRICT',$fk->UPDATE_RULE);}
    }
    public function test_binding_order_and_key_order_do_not_change_the_hash(): void
    {
        $v=$this->readyPolicy();$rows=array_map(fn($r)=>array_reverse($r,true),array_reverse($this->policyBindings()));
        $same=app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$rows,'Mismo contenido normalizado');
        $this->assertSame($v->content_hash,$same->content_hash);
        $rows=$this->policyBindings();$rows[2]['account_id']=$rows[3]['account_id']; // Dos destinos de tipo costo, hipótesis sin activar.
        $changed=app(AccountingPolicyService::class)->replaceBindings($v->id,$same->content_hash,$rows,'Cambio hipotético de cuenta');
        $this->assertNotSame($same->content_hash,$changed->content_hash);
    }
    public function test_item_scope_references_real_resource_without_mutating_inventory(): void
    {
        $item=\App\Models\Articulo::where('is_fractionable',true)->firstOrFail();
        $before=$item->getRawOriginal();$coverage=$this->policySlots();$rows=$this->policyBindings();
        foreach($coverage as &$s)if($s['role']==='inventario.insumos'){$s['scope_type']='item';$s['resource_id']=$item->id;}unset($s);
        foreach($rows as &$s)if($s['role']==='inventario.insumos'){$s['scope_type']='item';$s['resource_id']=$item->id;}unset($s);
        $v=$this->policyDraft(['coverage'=>$coverage]);$v=app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$rows,'Ámbito de artículo, solo documento');
        $this->assertSame('published',$this->publish($v)->status);
        $this->assertSame($before,$item->refresh()->getRawOriginal());
        $this->assertSame('item:'.$item->id,DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->where('semantic_role','inventario.insumos')->value('scope_key'));
    }
    public function test_opening_binding_remains_unpublishable_without_choosing_a_capital_account(): void
    {
        $slot=['role'=>'apertura.inventario.contrapartida','scope_type'=>'global','resource_id'=>null];
        $coverage=$this->policySlots();$coverage[]=$slot;$rows=$this->policyBindings();
        // Marcador de borrador usando un activo: no se decide una cuenta patrimonial ni se publica.
        $rows[]=$slot+['account_id'=>$rows[0]['account_id']];
        $v=$this->policyDraft(['coverage'=>$coverage]);$v=app(AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$rows,'Pendiente H01-B2B');
        $this->rejected(fn()=>$this->publish($v));
        $this->assertSame('draft',DB::table('accounting_policy_versions')->find($v->id)->status);
    }
}
