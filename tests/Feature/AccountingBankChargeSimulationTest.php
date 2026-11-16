<?php

namespace Tests\Feature;

use App\Services\{AccountingBankChargeSimulation,AccountingEventAuthorizationService as Auth,AccountingEventIdentityService as Identity};
use App\Support\AccountingSimulationBoundary as Boundary;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingPilotFixtures;
use Tests\TestCase;

class AccountingBankChargeSimulationTest extends TestCase
{
    use DatabaseTransactions,AccountingPilotFixtures;
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    private function reject(callable $f): void {
        try{$f();}catch(\Illuminate\Validation\ValidationException|\LogicException|\RuntimeException|\App\Exceptions\ContabilidadException $e){$this->assertNotSame('',$e->getMessage());return;}$this->fail('Debía rechazarse.');
    }
    public function test_fifty_charge_matches_legacy_book_and_accounting_projection(): void {
        [$b,$g,$p]=$this->pilotCase();$legacy=$this->bankFixture();$before=DB::table('asientos_contables')->count();
        $beforeExpense=(float)$this->get('/contabilidad/resultados?mes=2034-01')->viewData('gastosOperativos');
        $beforeAsset=(float)$this->get('/contabilidad/balance?fecha_corte=2034-01-31')->viewData('totalActivo');
        $beforeMajor=$this->get('/contabilidad/mayor?cuenta_id='.$b->cuenta_contable_id)->viewData('totales')['saldo'];
        $op=$this->runPilot($g,$p);$this->assertSame('confirmed',$op->status);$this->assertSame(950.0,(float)$b->refresh()->saldo_actual);
        $this->assertEquals($beforeExpense+50,(float)$this->get('/contabilidad/resultados?mes=2034-01')->viewData('gastosOperativos'));
        $this->assertEquals($beforeAsset-50,(float)$this->get('/contabilidad/balance?fecha_corte=2034-01-31')->viewData('totalActivo'));
        $this->assertEquals($beforeMajor-50,$this->get('/contabilidad/mayor?cuenta_id='.$b->cuenta_contable_id)->viewData('totales')['saldo']);
        $this->assertSame(2,DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->count());
        $this->post('/bancos/cargo',['cuenta_bancaria_id'=>$legacy->id,'cuenta_contable_id'=>$this->charge($legacy)['cuenta_contable_id'],'monto'=>'50.00','concepto'=>$p['description'],'fecha'=>$p['recognition_date'],'referencia_banco'=>$p['reference']])->assertSessionHas('success');
        $old=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$legacy->id)->first();
        $projection=fn($id)=>DB::table('detalle_asientos')->where('asiento_id',$id)->orderBy('cuenta_id')->get(['cuenta_id','moneda_id','debe','haber'])->toJson();
        $this->assertSame($projection($old->asiento_contable_id),$projection($op->result_asiento_id));$this->assertSame(950.0,(float)$legacy->refresh()->saldo_actual);
        $this->assertEquals($beforeExpense+100,(float)$this->get('/contabilidad/resultados?mes=2034-01')->viewData('gastosOperativos'));
        $this->assertEquals($beforeAsset-100,(float)$this->get('/contabilidad/balance?fecha_corte=2034-01-31')->viewData('totalActivo'));
        $this->assertBankReconciled($b,1000);$this->assertBankReconciled($legacy,1000);$this->assertSame($before+2,DB::table('asientos_contables')->count());
        $h=DB::table('asientos_contables')->find($op->result_asiento_id);$this->assertSame($op->policy_hash,$h->accounting_policy_hash);
        $this->assertSame((int)$g->id,(int)$h->accounting_authorization_id);$this->assertNull(DB::table('asientos_contables')->find($old->asiento_contable_id)->accounting_policy_version_id);
        $this->assertSame(['fondos.banco','gasto.cargo_bancario'],DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->orderBy('semantic_role')->pluck('semantic_role')->all());
        $this->assertSame(1,DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->distinct()->count('recorded_at'));
        $this->assertSame($before+2,$this->get('/contabilidad')->viewData('asientos')->count());$this->get('/contabilidad/mayor?cuenta_id='.$b->cuenta_contable_id)->assertOk();
        $this->get('/contabilidad/resultados?mes=2034-01')->assertOk();$this->get('/contabilidad/balance?fecha_corte=2034-01-31')->assertOk();$this->get('/bancos/'.$b->id.'/libro')->assertOk();
        $this->assertSame(950.0,(float)$this->get('/bancos/'.$b->id.'/libro')->viewData('movimientos')->first()->saldo_corriendo);
    }
    public function test_same_uuid_returns_original_result_new_uuid_is_independent_and_changes_conflict(): void {
        [$b,$g,$p]=$this->pilotCase();$a=$this->runPilot($g,$p);$same=$this->runPilot($g,array_replace($p,['amount'=>'00050.0','uuid'=>strtoupper($p['uuid'])]));
        $this->assertSame($a->result_asiento_id,$same->result_asiento_id);$this->assertSame($a->result_movement_id,$same->result_movement_id);$this->assertSame(950.0,(float)$b->refresh()->saldo_actual);
        $this->reject(fn()=>$this->runPilot($g,array_replace($p,['amount'=>'50.01'])));
        $p2=array_replace($p,['uuid'=>(string)\Illuminate\Support\Str::uuid(),'classification'=>'commission']);$second=$this->runPilot($g,$p2);$this->assertNotSame($a->id,$second->id);$this->assertSame('commission',$second->classification);
        $this->assertSame(900.0,(float)$b->refresh()->saldo_actual);$this->assertSame($a->id,$this->runPilot($g,$p)->id);$this->assertBankReconciled($b,1000);
    }
    public function test_revocation_keeps_original_posting_and_replay_but_rejects_new_operation(): void {
        [$b,$g,$p]=$this->pilotCase();$a=$this->runPilot($g,$p);$before=DB::table('detalle_asientos')->where('asiento_id',$a->result_asiento_id)->get()->toJson();
        app(Auth::class)->revokeForSimulation($g->id,'SYNTHETIC','Retirada después de ensayo');
        $this->assertSame($a->id,$this->runPilot($g,$p)->id);$this->reject(fn()=>$this->runPilot($g,array_replace($p,['uuid'=>(string)\Illuminate\Support\Str::uuid()])));
        $this->assertSame($before,DB::table('detalle_asientos')->where('asiento_id',$a->result_asiento_id)->get()->toJson());$this->assertSame(950.0,(float)$b->refresh()->saldo_actual);
    }
    public static function faults(): array {return [['after_movement'],['after_header'],['after_first_line'],['after_resolutions'],['engine_error']];}
    #[DataProvider('faults')]
    public function test_failure_rolls_back_all_effects_and_preserves_outer_transaction(string $stage): void {
        [$b,$g,$p]=$this->pilotCase();$tables=['movimientos_bancarios','asientos_contables','detalle_asientos','accounting_event_operations','accounting_entry_line_resolutions'];$counts=[];foreach($tables as $t)$counts[$t]=DB::table($t)->count();$level=DB::transactionLevel();$line=0;
        DB::connection()->beforeExecuting(function($sql)use($stage,&$line){$isLine=str_starts_with($sql,'insert into `detalle_asientos`');
            if($stage==='engine_error'&&$isLine)throw new \Error('PILOT injected Error');
            if(($stage==='after_movement'&&str_starts_with($sql,'insert into `asientos_contables`'))||($stage==='after_header'&&$isLine)
                ||($stage==='after_first_line'&&$isLine&&++$line===2)||($stage==='after_resolutions'&&str_starts_with($sql,'update `cuentas_bancarias`')))throw new \RuntimeException('PILOT injected failure');});
        $caught=null;try{$this->runPilot($g,$p);}catch(\Throwable $e){$caught=$e;}$this->assertNotNull($caught);$this->assertStringContainsString('PILOT injected',$caught->getMessage());
        foreach($counts as $t=>$c)$this->assertSame($c,DB::table($t)->count(),$t);$this->assertSame($level,DB::transactionLevel());$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);
    }
    public static function invalidCases(): array {return [['inactive'],['group'],['bank_link'],['binding_tamper'],['ambiguous_policy'],['closed_period'],['outside_window'],['bad_grant'],['incomplete_prepared']];}
    #[DataProvider('invalidCases')]
    public function test_rejections_do_not_apply_financial_effects(string $case): void {
        [$b,$g,$p]=$this->pilotCase();$n=DB::table('movimientos_bancarios')->count();$entries=DB::table('asientos_contables')->count();
        if(in_array($case,['inactive','group']))DB::table('cuentas_contables')->where('id',$this->charge($b)['cuenta_contable_id'])->update($case==='inactive'?['activa'=>false]:['permite_movimiento'=>false]);
        if($case==='bank_link')$b->update(['cuenta_contable_id'=>DB::table('cuentas_contables')->where('codigo','1.1.2.1')->value('id')]);
        if($case==='binding_tamper')DB::table('accounting_account_bindings')->where('policy_version_id',$g->policy_version_id)->where('semantic_role','gasto.cargo_bancario')->update(['account_id'=>$b->cuenta_contable_id]);
        if($case==='ambiguous_policy'){$v=(array)DB::table('accounting_policy_versions')->find($g->policy_version_id);unset($v['id']);$v['version_number']=(int)DB::table('accounting_policy_versions')->max('version_number')+1;DB::table('accounting_policy_versions')->insert($v);}
        if($case==='closed_period')DB::table('periodos_contables')->where('fecha_inicio','2034-01-01')->update(['estado'=>'cerrado']);
        if($case==='outside_window')Carbon::setTestNow('2034-02-01 12:00:00');
        if($case==='bad_grant')$g=(object)['id'=>PHP_INT_MAX];
        if($case==='incomplete_prepared'){$a=app(Identity::class)->prepareSimulation($g->id,$p,\DateTimeImmutable::createFromInterface(now('UTC')));DB::table('accounting_event_operations')->where('id',$a->id)->update(['content_hash'=>str_repeat('a',64)]);}
        $this->reject(fn()=>$this->runPilot($g,$p));$this->assertSame($n,DB::table('movimientos_bancarios')->count());$this->assertSame($entries,DB::table('asientos_contables')->count());$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);
    }
    public function test_boundary_rejects_other_database_environment_or_contract_without_access(): void {
        [$b,$g,$p]=$this->pilotCase();$saved=config('database.connections.mysql.database');config(['database.connections.mysql.database'=>'salon_erp']);
        try{$this->reject(fn()=>$this->runPilot($g,$p));}finally{config(['database.connections.mysql.database'=>$saved]);}
        $this->app->instance('env','local');try{$this->reject(fn()=>$this->runPilot($g,$p));}finally{$this->app->instance('env','testing');}
        $this->reject(fn()=>app(AccountingBankChargeSimulation::class)->executeForInternalHarness($g->id,$p,'true'));
        $this->assertSame('salon_erp_test',DB::selectOne('SELECT DATABASE() AS d')->d);$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);
    }
    public function test_normal_http_cannot_activate_simulation_and_manual_reversal_stays_blocked(): void {
        [$b,$g,$p]=$this->pilotCase();$before=DB::table('accounting_event_operations')->count();
        $this->post('/bancos/cargo',$this->charge($b)+['contract'=>Boundary::CONTRACT,'authorization_id'=>$g->id,'uuid'=>$p['uuid']])->assertSessionHas('success');
        $this->assertSame($before,DB::table('accounting_event_operations')->count());$old=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->first();
        $this->assertNull(DB::table('asientos_contables')->find($old->asiento_contable_id)->accounting_policy_version_id);
        $op=$this->runPilot($g,$p);$this->post('/asientos/'.$op->result_asiento_id.'/reversar')->assertSessionHasErrors();
        $this->assertSame(900.0,(float)$b->refresh()->saldo_actual);
    }
    public function test_demo_or_missing_pilot_coverage_cannot_issue_a_simulation_grant(): void {
        [$b,$g,$p]=$this->pilotCase();$service=app(\App\Services\AccountingPolicyService::class);
        $demo=$this->readyPolicy(['kind'=>'demo']);$this->reject(fn()=>app(Auth::class)->approveForSimulation($demo->id,$b->id,'SYNTHETIC','No demo'));
        $core=$this->readyPolicy();$core=$service->publish($core->id,$core->content_hash,'SYNTHETIC','Solo CORE');
        $this->reject(fn()=>app(Auth::class)->approveForSimulation($core->id,$b->id,'SYNTHETIC','Sin cobertura'));
        $this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);$this->assertSame(0,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->count());
    }
}
