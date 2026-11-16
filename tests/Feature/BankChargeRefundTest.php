<?php
namespace Tests\Feature;
use App\Services\{BankChargeRefundSimulation as Refund,AccountingEventAuthorizationService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BankChargeRefundFixtures;
use Tests\TestCase;
class BankChargeRefundTest extends TestCase
{
    use DatabaseTransactions,BankChargeRefundFixtures;
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    private function reject(callable $f): void {try{$f();$this->fail('Debía rechazarse');}catch(\Illuminate\Validation\ValidationException|\LogicException|\App\Exceptions\ContabilidadException|\RuntimeException $e){$this->assertNotSame('',$e->getMessage());}}
    public function test_full_fifty_refund_preserves_original_and_reverses_exact_accounts_and_reports(): void {
        [$b,$o,$p]=$this->refundCase();$old=DB::table('movimientos_bancarios')->find($o->result_movement_id);$entry=DB::table('asientos_contables')->find($o->result_asiento_id);$details=DB::table('detalle_asientos')->where('asiento_id',$entry->id)->orderBy('id')->get();
        $this->assertSame(950.0,(float)$b->refresh()->saldo_actual);$before=(float)$this->get('/contabilidad/resultados?mes=2034-03')->viewData('gastosOperativos');$assets=(float)$this->get('/contabilidad/balance?fecha_corte=2034-03-31')->viewData('totalActivo');
        $d=$this->refund($o,$p);$this->assertSame('confirmed',$d->status);$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);$this->assertEquals($old,DB::table('movimientos_bancarios')->find($old->id));$this->assertEquals($entry,DB::table('asientos_contables')->find($entry->id));
        $inverse=DB::table('detalle_asientos')->where('asiento_id',$d->result_asiento_id)->orderBy('id')->get();$links=DB::table('bank_charge_reversal_lines')->where('reversal_id',$d->id)->orderBy('id')->get();$this->assertCount(2,$links);
        foreach($details as $i=>$line){$this->assertSame($line->debe,$inverse[$i]->haber);$this->assertSame($line->haber,$inverse[$i]->debe);$this->assertSame((int)$line->cuenta_id,(int)$inverse[$i]->cuenta_id);$this->assertSame((int)$line->id,(int)$links[$i]->original_detail_id);$this->assertSame((int)$inverse[$i]->id,(int)$links[$i]->reversal_detail_id);}
        $this->assertEquals($before-50,(float)$this->get('/contabilidad/resultados?mes=2034-03')->viewData('gastosOperativos'));$this->assertEquals($assets+50,(float)$this->get('/contabilidad/balance?fecha_corte=2034-03-31')->viewData('totalActivo'));
        $this->get('/contabilidad')->assertOk();$this->get('/contabilidad/mayor?cuenta_id='.$b->cuenta_contable_id)->assertOk();$this->assertSame(1000.0,(float)$this->get('/bancos/'.$b->id.'/libro')->viewData('movimientos')->first()->saldo_corriendo);$this->assertBankReconciled($b,0);
        $e=json_decode($d->evidence,true);$this->assertTrue($e['synthetic']);$this->assertFalse($e['economic_authority']);$this->assertSame((int)auth()->id(),$e['actor_id']);
        $this->assertNull(DB::table('asientos_contables')->find($d->result_asiento_id)->accounting_policy_version_id);
        $this->post('/asientos/'.$d->result_asiento_id.'/reversar')->assertSessionHasErrors();
    }
    public function test_idempotent_result_and_different_uuid_for_same_original_is_rejected(): void {
        [$b,$o,$p]=$this->refundCase();$d=$this->refund($o,$p);$this->assertSame($d->id,$this->refund($o,array_replace($p,['uuid'=>strtoupper($p['uuid'])]))->id);
        $this->reject(fn()=>$this->refund($o,array_replace($p,['evidence_description'=>'Otro contenido'])));$this->reject(fn()=>$this->refund($o,array_replace($p,['uuid'=>(string)\Illuminate\Support\Str::uuid()])));
        $this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);$this->assertSame(1,DB::table('bank_charge_reversals')->where('original_operation_id',$o->id)->count());
    }
    public function test_inactive_original_expense_and_withdrawn_policy_authority_keep_historical_refund(): void {
        [$b,$o,$p,$g]=$this->refundCase();app(AccountingEventAuthorizationService::class)->revokeForSimulation($g->id,'SYNTHETIC','Retirada posterior al cargo');DB::table('cuentas_contables')->where('codigo','6.9')->update(['activa'=>false]);
        $d=$this->refund($o,$p);$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);$this->assertSame($d->id,$this->refund($o,$p)->id);
    }
    public function test_cleared_original_remains_cleared_refund_is_pending_for_later_cut(): void {
        [$b,$o,$p,$g,$seed]=$this->refundCase();$cut=$this->clearOriginal($b,$o,$seed);$old=json_encode(DB::table('movimientos_bancarios')->find($o->result_movement_id));$d=$this->refund($o,$p);
        $this->assertSame($old,json_encode(DB::table('movimientos_bancarios')->find($o->result_movement_id)));$this->assertEquals($cut,DB::table('conciliaciones_bancarias')->find($cut->id));$this->assertSame('pendiente',DB::table('movimientos_bancarios')->find($d->result_movement_id)->estado_conciliacion);
        $this->post('/bancos/'.$b->id.'/conciliacion',$this->reconciliationPayload('2034-03-16',1000,[$d->result_movement_id],$cut->id))->assertSessionHasNoErrors();$this->assertEquals($cut,DB::table('conciliaciones_bancarias')->find($cut->id));
    }
    public function test_closed_original_period_open_posterior_period_only(): void {
        [$b,$o,$p]=$this->refundCase();$old=DB::table('asientos_contables')->find($o->result_asiento_id);DB::table('periodos_contables')->where('id',$old->periodo_id)->update(['estado'=>'cerrado']);$this->bankDate('2034-04-20');
        $p['effective_date']=$p['recognition_date']='2034-04-15';$d=$this->refund($o,$p);$new=DB::table('asientos_contables')->find($d->result_asiento_id);$this->assertNotSame($old->periodo_id,$new->periodo_id);$this->assertEquals($old,DB::table('asientos_contables')->find($old->id));
    }
    public static function invalidCases(): array {return array_map(fn($x)=>[$x],['bank_inactive','bank_link','bank_usd','wrong_movement','prepared','legacy','closed_destination','closed_cut','missing_proof','wrong_reason','dates_differ','future','authoritative_amount','tampered_document']);}
    #[DataProvider('invalidCases')]
    public function test_invalid_refunds_leave_every_effect_unchanged(string $case): void {
        [$b,$o,$p,$g,$seed]=$this->refundCase();$id=$o->id;
        if($case==='bank_inactive')$b->update(['activa'=>false]);if($case==='bank_link')$b->update(['cuenta_contable_id'=>DB::table('cuentas_contables')->where('codigo','1.1.2.1')->value('id')]);
        if($case==='bank_usd')$b->update(['moneda_id'=>DB::table('monedas')->where('codigo','USD')->value('id')]);
        if($case==='wrong_movement')DB::table('movimientos_bancarios')->where('id',$o->result_movement_id)->update(['tipo'=>'abono']);
        if($case==='prepared'){$n=array_replace($this->pilotInput(),['recognition_date'=>'2034-03-15']);$id=app(\App\Services\AccountingEventIdentityService::class)->prepareSimulation($g->id,$n,new \DateTimeImmutable('2034-03-15T00:00:00Z'))->id;}
        if($case==='legacy')$id=PHP_INT_MAX;
        if($case==='closed_destination')DB::table('periodos_contables')->where('fecha_inicio','2034-03-01')->update(['estado'=>'cerrado']);
        if($case==='closed_cut'){$this->clearOriginal($b,$o,$seed);$p['effective_date']=$p['recognition_date']='2034-03-11';}
        if($case==='missing_proof')$p['bank_reference']='';if($case==='wrong_reason')$p['reason_code']='internal_error';if($case==='dates_differ')$p['effective_date']='2034-03-14';if($case==='future')$p['effective_date']=$p['recognition_date']='2034-03-21';if($case==='authoritative_amount')$p['amount']='500.00';
        if($case==='tampered_document')DB::table('accounting_event_operations')->where('id',$o->id)->update(['content_hash'=>str_repeat('a',64)]);
        $before=[];foreach(['bank_charge_reversals','bank_charge_reversal_lines','movimientos_bancarios','asientos_contables','detalle_asientos','cuentas_bancarias','conciliaciones_bancarias'] as $t)$before[$t]=DB::table($t)->get()->toJson();
        $this->reject(fn()=>app(Refund::class)->execute($id,$p,Refund::CONTRACT));foreach($before as $t=>$rows)$this->assertSame($rows,DB::table($t)->get()->toJson(),$t);
    }
    public static function stages(): array {return array_map(fn($x)=>[$x],['movement','header','first_line','links','balance']);}
    #[DataProvider('stages')]
    public function test_errors_rollback_document_movement_entry_lines_links_and_balance(string $stage): void {
        [$b,$o,$p]=$this->refundCase();$before=[];foreach(['bank_charge_reversals','bank_charge_reversal_lines','movimientos_bancarios','asientos_contables','detalle_asientos','cuentas_bancarias'] as $t)$before[$t]=DB::table($t)->get()->toJson();$line=0;$updated=false;
        DB::connection()->beforeExecuting(function($sql)use($stage,&$line,&$updated){$isLine=str_starts_with($sql,'insert into `detalle_asientos`');
            if($stage==='balance'&&$updated)throw new \Error('Injected refund failure');
            if(($stage==='movement'&&str_starts_with($sql,'insert into `asientos_contables`'))||($stage==='header'&&$isLine)||($stage==='first_line'&&$isLine&&++$line===2)||($stage==='links'&&str_starts_with($sql,'update `cuentas_bancarias`')))throw new \Error('Injected refund failure');
            if(str_starts_with($sql,'update `cuentas_bancarias`'))$updated=true;
        });
        try{$this->refund($o,$p);$this->fail('Debía fallar');}catch(\Error $e){$this->assertSame('Injected refund failure',$e->getMessage());}
        // Desactivar la inyección de lectura posterior al saldo para verificar el rollback.
        $updated=false;foreach($before as $t=>$rows)$this->assertSame($rows,DB::table($t)->get()->toJson(),$t);
    }
    public function test_foreign_keys_checks_and_boundary_without_financial_activation(): void {
        [$b,$o,$p]=$this->refundCase();$d=$this->refund($o,$p);$link=DB::table('bank_charge_reversal_lines')->where('reversal_id',$d->id)->first();
        foreach([fn()=>DB::table('bank_charge_reversal_lines')->where('id',$link->id)->update(['original_detail_id'=>PHP_INT_MAX]),fn()=>DB::table('bank_charge_reversals')->where('id',$d->id)->delete(),fn()=>DB::table('bank_charge_reversal_lines')->where('id',$link->id)->update(['reversal_debe'=>'123.00'])] as $sql){try{DB::transaction($sql);$this->fail('SQL debía rechazar');}catch(\Illuminate\Database\QueryException $e){$this->assertContains($e->errorInfo[1],[1451,1452,3819]);}}
        $this->reject(fn()=>app(Refund::class)->execute($o->id,$p,'true'));$this->app->instance('env','local');try{$this->reject(fn()=>$this->refund($o,$p));}finally{$this->app->instance('env','testing');}
        $this->reject(fn()=>app(AccountingEventAuthorizationService::class)->assertBusinessAuthority($o->authorization_id));
        foreach(app('router')->getRoutes() as $r)$this->assertStringNotContainsString(Refund::class,$r->getActionName());
    }
}
