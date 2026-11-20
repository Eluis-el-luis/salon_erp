<?php

namespace Tests\Feature;

use App\Http\Controllers\{ContabilidadController,PeriodoContableController};
use App\Models\{AsientoContable,CuentaContable,DetalleAsiento,PeriodoContable};
use App\Services\{AccountIntegrityService,ContabilidadService,DashboardService,SyntheticAccountingPresentation};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** New, rolled-back fixtures only. No seeders, historical edits or persistent financial races. */
class AccountingReportCharacterizationTest extends TestCase
{
    use DatabaseTransactions, AccountingPolicyFixtures;

    private PeriodoContable $period;
    private array $ids=[];
    private bool $reading=false;

    protected function setUp(): void
    {
        parent::setUp();$this->policyActor();Carbon::setTestNow('2037-04-15 12:00:00');
        $this->period=PeriodoContable::create(['nombre'=>'AC05B1 SYNTHETIC transaction only','fecha_inicio'=>'2037-04-01','fecha_fin'=>'2037-04-30','estado'=>'abierto']);
        DB::connection()->beforeExecuting(function($sql){
            if($this->reading && !preg_match('/^\s*(select|show)\b/i',$sql))throw new \LogicException('Presentation may only read.');
        });
    }

    protected function tearDown(): void
    {
        $this->reading=false;Carbon::setTestNow();parent::tearDown();
    }

    private function account(string $prefix,string $type,string $nature='deudora',array $extra=[]): CuentaContable
    {
        return CuentaContable::create(array_replace(['codigo'=>$prefix.bin2hex(random_bytes(3)),
            'nombre'=>'AC05B1 SYNTHETIC','tipo'=>$type,'naturaleza'=>$nature,'permite_movimiento'=>true,'activa'=>true,'nivel'=>1],$extra));
    }

    private function entry(array $lines,?string $origin='manual',string $state='contabilizado',?int $period=null,string $date='2037-04-15'): AsientoContable
    {
        $entry=AsientoContable::create(['numero_asiento'=>'AC05B1-'.bin2hex(random_bytes(6)),'fecha'=>$date,'concepto'=>'SYNTHETIC READ FIXTURE',
            'modulo_origen'=>$origin,'estado'=>$state,'periodo_id'=>$period??$this->period->id,'usuario_id'=>auth()->id()]);
        $currency=DB::table('monedas')->where('es_base',true)->value('id');
        foreach($lines as [$a,$d,$h])DetalleAsiento::create(['asiento_id'=>$entry->id,'cuenta_id'=>$a->id,'moneda_id'=>$currency,'debe'=>$d,'haber'=>$h]);
        $this->ids[]=$entry->id;return $entry;
    }

    private function results(): array
    {
        return app(ContabilidadController::class)->resultados(Request::create('/contabilidad/resultados','GET',['mes'=>'2037-04']))->getData();
    }

    private function balance(): array
    {
        return app(PeriodoContableController::class)->balanceGeneral(Request::create('/contabilidad/balance','GET',['fecha_corte'=>'2037-04-30']))->getData();
    }

    private function originals(): array
    {
        return DB::table('detalle_asientos as d')->join('asientos_contables as a','a.id','=','d.asiento_id')->whereIn('a.id',$this->ids)
            ->orderBy('d.id')->get(['d.*','a.fecha','a.periodo_id','a.modulo_origen','a.estado'])->map(fn($r)=>[
                'detail_id'=>(int)$r->id,'entry_id'=>(int)$r->asiento_id,'account_id'=>(int)$r->cuenta_id,'period_id'=>(int)$r->periodo_id,
                'date'=>$r->fecha,'origin'=>$r->modulo_origen,'state'=>$r->estado,'debit'=>$r->debe,'credit'=>$r->haber,
                'currency_id'=>(int)$r->moneda_id,'description'=>$r->descripcion])->all();
    }

    private function classified(array $map,string $report='result',array $extra=[]): array
    {
        $c=['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B1-SQL-fixture',
            'assignments'=>array_map(fn($id,$group)=>['account_id'=>$id,'group'=>$group],array_keys($map),array_values($map))];
        return app(SyntheticAccountingPresentation::class)->read($c,$this->originals(),array_replace([
            'report'=>$report,'from'=>$report==='result'?'2037-04-01':null,'until'=>'2037-04-30','null_origin'=>'include',
            'states'=>['contabilizado','borrador','anulado']],$extra));
    }

    public function test_alternative_codes_are_omitted_by_results_and_misclassified_by_balance(): void
    {
        $before=$this->balance();$baseline=$this->results();
        $a=$this->account('ACT-','activo');$e=$this->account('3.','patrimonio','acreedora');
        $i=$this->account('ING-','ingreso','acreedora');$g=$this->account('GAS-','gasto');
        $this->entry([[$a,'100.00','0.00'],[$e,'0.00','100.00']]);
        $this->entry([[$a,'120.00','0.00'],[$i,'0.00','120.00']]);$this->entry([[$g,'30.00','0.00'],[$a,'0.00','30.00']]);
        $this->reading=true;$r=$this->results();$b=$this->balance();
        $this->assertEquals($baseline['utilidadNeta'],$r['utilidadNeta']);
        $this->assertEquals($before['totalActivo'],$b['totalActivo']);
        $c=$this->classified([$a->id=>'asset',$e->id=>'equity',$i->id=>'income',$g->id=>'operating_expense'],'balance');
        $this->assertSame(19000,$c['groups_cents']['asset']);$this->assertSame(9000,$c['pending_result_cents']);
        $this->assertSame(0,$c['balance_difference_cents']);$this->assertSame($this->originals(),array_values($c['details']));
        $diary=app(ContabilidadController::class)->diario()->getData()['asientos']->whereIn('id',$this->ids);
        $this->assertCount(3,$diary);
        $mayor=app(ContabilidadController::class)->mayor(Request::create('/','GET',['cuenta_id'=>$a->id]))->getData();
        $this->assertEquals(190,$mayor['totales']['saldo']);
        $this->assertSame(25000,$c['debit_cents']);$this->assertSame($c['debit_cents'],$c['credit_cents']);
    }

    public function test_null_origin_states_period_date_and_closing_are_distinct_universes(): void
    {
        $i=$this->account('4.','ingreso','acreedora');$a=$this->account('1.','activo');
        $other=PeriodoContable::create(['nombre'=>'AC05B1 mismatched period fixture','fecha_inicio'=>'2037-03-01','fecha_fin'=>'2037-03-31','estado'=>'cerrado']);
        foreach([[null,'contabilizado',10],['manual','borrador',20],['ventas','anulado',30],['cierre_fiscal','contabilizado',40]] as [$o,$s,$v])
            $this->entry([[$a,$v.'.00','0.00'],[$i,'0.00',$v.'.00']],$o,$s,$other->id);
        $base=$this->results();$account=$base['cuentasResultados']->firstWhere('id',$i->id);
        $this->assertEquals(50,$account->saldo_final);
        $this->assertCount(2,$account->detalles); // draft + annulled; NULL and closing excluded.
        $map=[$a->id=>'asset',$i->id=>'income'];$this->reading=true;
        $all=$this->classified($map);$sqlNull=$this->classified($map,'result',['null_origin'=>'exclude']);
        $this->assertSame(6000,$all['subtotals_cents']['result']);$this->assertSame(5000,$sqlNull['subtotals_cents']['result']);
        $expected=$account->detalles->pluck('id')->sort()->values()->all();
        $actual=array_values(array_map(fn($r)=>$r['detail_id'],array_filter($sqlNull['details'],fn($r)=>$r['account_id']===$i->id)));
        $this->assertSame($expected,$actual);
        $this->assertSame(0,$this->classified($map,'result',['period_id'=>$this->period->id])['subtotals_cents']['result']);
        $b=$this->balance();$this->assertEquals(100,$b['cuentasActivoConSaldo']->firstWhere('id',$a->id)->saldo_calculado);
        $this->assertSame(10000,$this->classified($map,'balance')['pending_result_cents']);
        $this->assertCount(8,$this->originals());
    }

    public function test_dashboard_result_series_panel_and_correctors_match_original_details(): void
    {
        $s=app(DashboardService::class);$start=Carbon::parse('2037-04-01');$end=Carbon::parse('2037-04-30');$before=$s->kpisRango($start,$end);
        $a=$this->account('1.','activo');$i=$this->account('4.','ingreso','acreedora');$contra=$this->account('4.','ingreso');
        $cost=$this->account('5.','costo');$g=$this->account('6.','gasto');$other=$this->account('7.','gasto');
        $this->entry([[$a,'100.00','0.00'],[$i,'0.00','100.00']],'ventas');
        $this->entry([[$contra,'10.00','0.00'],[$a,'0.00','10.00']]);
        $this->entry([[$cost,'20.00','0.00'],[$g,'10.00','0.00'],[$other,'5.00','0.00'],[$a,'0.00','35.00']]);
        $this->reading=true;$after=$s->kpisRango($start,$end);$r=$this->results();
        foreach(['ingresos'=>90,'costos'=>20,'gastos_operativos'=>10,'otros_egresos'=>5] as $key=>$v)$this->assertEquals($v,$after[$key]-$before[$key]);
        $map=[$a->id=>'asset',$i->id=>'income',$contra->id=>'income',$cost->id=>'cost',$g->id=>'operating_expense',$other->id=>'other_expense'];
        $c=$this->classified($map);$this->assertSame(5500,$c['subtotals_cents']['result']);
        $this->assertEquals(90,$r['cuentasResultados']->whereIn('id',[$i->id,$contra->id])->sum('saldo_final'));
        $this->assertEquals($after,$s->panel($start,$end,'mes')['kpis']);
        $series=$s->serieTemporal($start,$end);$this->assertEquals($after['ingresos'],array_sum($series['ingresos']));
        $this->assertEquals($after['costos'],array_sum($series['costos']));
    }

    public function test_inactive_and_parent_own_postings_are_not_lost_or_duplicated(): void
    {
        $parent=$this->account('6.','gasto');$child=$this->account('6.','gasto','deudora',['cuenta_padre_id'=>$parent->id,'nivel'=>2,'activa'=>false]);
        $a=$this->account('1.','activo');$this->entry([[$parent,'10.00','0.00'],[$child,'20.00','0.00'],[$a,'0.00','30.00']]);
        $this->reading=true;$r=$this->results();$this->assertEquals(30,$r['cuentasResultados']->whereIn('id',[$parent->id,$child->id])->sum('saldo_final'));
        $c=$this->classified([$parent->id=>'operating_expense',$child->id=>'operating_expense',$a->id=>'asset']);
        $this->assertSame(3000,$c['groups_cents']['operating_expense']);$this->assertCount(3,$c['details']);
        $this->assertSame($child->id,app(AccountIntegrityService::class)->account($child->id,true)->id);
        $this->expectException(\App\Exceptions\ContabilidadException::class);app(AccountIntegrityService::class)->account($child->id);
    }

    public function test_synthetic_charge_refund_and_manual_reversals_keep_original_accounts(): void
    {
        $a=$this->account('1.','activo');$g=$this->account('6.','gasto');
        $charge=$this->entry([[$g,'50.00','0.00'],[$a,'0.00','50.00']],'bancos');
        $this->entry([[$a,'50.00','0.00'],[$g,'0.00','50.00']],'bank_charge_refunds');
        $this->entry([[$g,'2.00','0.00'],[$a,'0.00','2.00']],'manual');
        $this->entry([[$a,'2.00','0.00'],[$g,'0.00','2.00']],'reversion');
        $before=$this->originals();$this->reading=true;$c=$this->classified([$a->id=>'asset',$g->id=>'operating_expense'],'balance');
        $this->assertSame(0,$c['pending_result_cents']);$this->assertSame(0,$c['balance_difference_cents']);
        $this->assertSame($before,array_values($c['details']));$this->assertSame($before,$this->originals());
        $this->assertSame(4,app(ContabilidadController::class)->diario()->getData()['asientos']->whereIn('id',$this->ids)->count());
        $this->reading=false;
        $response=$this->post('/asientos/'.$charge->id.'/reversar');$response->assertSessionHasErrors();
        $this->assertSame($before,$this->originals());
    }

    public function test_corrected_closing_writer_credits_contra_income_preserving_original_net_effect(): void
    {
        // Only this newly-created period is closed; outer DatabaseTransactions always rolls it back.
        Carbon::setTestNow('2037-05-15');$a=$this->account('1.','activo');$i=$this->account('4.','ingreso','acreedora');$c=$this->account('4.','ingreso');
        $this->entry([[$a,'90.00','0.00'],[$c,'10.00','0.00'],[$i,'0.00','100.00']]);
        $originals=$this->originals();
        $response=app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertFalse(session()->has('errors'));$this->assertSame('cerrado',$this->period->refresh()->estado);
        $closing=AsientoContable::where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->firstOrFail();
        $line=DB::table('detalle_asientos')->where('asiento_id',$closing->id)->where('cuenta_id',$c->id)->first();
        // AC05B1 reproduced -10.00 on debit before AC05C0; preserve its -10.00 net effect as a credit.
        $this->assertSame('0.00',$line->debe);$this->assertSame('10.00',$line->haber);
        $totals=DB::table('detalle_asientos')->where('asiento_id',$closing->id)->selectRaw('SUM(debe) d,SUM(haber) h')->first();
        $this->assertSame($totals->d,$totals->h);$this->assertSame('100.00',$totals->d);
        $this->assertSame($originals,$this->originals());
        $r=$this->results();$this->assertEquals(90,$r['cuentasResultados']->whereIn('id',[$i->id,$c->id])->sum('saldo_final'));
        $this->assertSame(1,DB::table('asientos_contables')->where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->count());
        $this->ids[]=$closing->id;$retained=(int)CuentaContable::where('codigo','3.2')->value('id');$this->reading=true;
        $map=[$a->id=>'asset',$i->id=>'income',$c->id=>'income',$retained=>'equity'];
        $balance=$this->classified($map,'balance');$result=$this->classified($map);
        $this->assertSame(0,$balance['pending_result_cents']);$this->assertSame(9000,$balance['groups_cents']['equity']);
        $this->assertSame(0,$balance['balance_difference_cents']);$this->assertSame(9000,$result['subtotals_cents']['result']);
    }

    public function test_catalogue_history_and_legitimate_manual_reversal_regression_without_seeders(): void
    {
        $a=$this->account('1.','activo');$g=$this->account('6.','gasto');
        $entry=$this->entry([[$g,'12.50','0.00'],[$a,'0.00','12.50']]);$before=$entry->detalles()->get()->toJson();
        try {
            app(AccountIntegrityService::class)->save(array_replace($g->getAttributes(),['tipo'=>'costo']),$g->id);
            $this->fail('Used classification must remain protected.');
        } catch(\Illuminate\Validation\ValidationException $e){$this->assertSame('gasto',$g->refresh()->tipo);}
        $this->post('/asientos/'.$entry->id.'/reversar')->assertSessionHasNoErrors();
        $reverse=AsientoContable::where('modulo_origen','reversion')->where('referencia_id',$entry->id)->firstOrFail();
        $this->ids[]=$reverse->id;$this->reading=true;
        $r=$this->classified([$a->id=>'asset',$g->id=>'operating_expense'],'balance');
        $this->assertSame(0,$r['pending_result_cents']);$this->assertSame(0,$r['balance_difference_cents']);
        $this->assertSame($before,$entry->detalles()->get()->toJson());$this->assertCount(4,$r['details']);
    }

    public function test_multiple_real_bindings_on_one_fixture_account_cannot_multiply_details(): void
    {
        $cost=$this->account('5.','costo');$a=$this->account('1.','activo');
        $v=$this->policyDraft();$bindings=$this->policyBindings();
        foreach($bindings as &$binding)if(in_array($binding['role'],['costo.producto','costo.servicio'],true))$binding['account_id']=$cost->id;
        unset($binding);
        app(\App\Services\AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'AC05B1 synthetic cardinality only');
        $this->assertSame(2,DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->where('account_id',$cost->id)->count());
        $this->entry([[$cost,'50.00','0.00'],[$a,'0.00','50.00']]);$this->reading=true;
        $r=$this->classified([$cost->id=>'cost',$a->id=>'asset']);
        $this->assertSame(5000,$r['groups_cents']['cost']);$this->assertCount(2,$r['details']);
    }
}
