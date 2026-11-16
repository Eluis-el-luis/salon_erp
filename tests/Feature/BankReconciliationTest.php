<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BankReconciliationFixtures;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use DatabaseTransactions,BankReconciliationFixtures;
    protected function setUp(): void {parent::setUp();$this->bankActor();$this->bankDate('2032-08-25');}
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    public function test_successive_cuts_keep_100_then_120_without_double_counting_or_balance_writes(): void {
        $b=$this->reconciliationBank();$a=$this->documentedEntry($b,100,'abono','2032-08-10');$url='/bancos/'.$b->id.'/conciliacion';
        $this->get($url)->assertOk()->assertSee('name="base_conciliacion_id"',false)->assertViewHas('extractoBase',null);
        $entries=DB::table('asientos_contables')->get()->toJson();$this->post($url,$this->reconciliationPayload('2032-08-10',100,[$a->id]))->assertSessionHas('success')->assertSessionHasNoErrors();
        $first=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->first();$before=json_encode($first);
        $c=$this->documentedEntry($b,20,'abono','2032-08-20');$balance=$b->refresh()->getRawOriginal('saldo_actual');$entries=DB::table('asientos_contables')->get()->toJson();
        $this->post($url,$this->reconciliationPayload('2032-08-20',120,[$c->id],$first->id))->assertSessionHasNoErrors()->assertSessionHas('success');
        $second=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->orderByDesc('id')->first();
        $this->assertSame('120.00',$second->saldo_libros);$this->assertSame('100.00',$second->saldo_inicial_conciliado);$this->assertSame($before,json_encode(DB::table('conciliaciones_bancarias')->find($first->id)));
        $this->assertSame($balance,$b->refresh()->getRawOriginal('saldo_actual'));$this->assertSame($entries,DB::table('asientos_contables')->get()->toJson());
        $this->assertSame(120.0,(float)$this->get('/bancos/'.$b->id.'/libro')->viewData('movimientos')->first()->saldo_corriendo);
    }
    public function test_old_transit_and_unselected_pending_remain_in_books_and_future_is_excluded(): void {
        $b=$this->reconciliationBank();$a=$this->documentedEntry($b,100,'abono','2032-08-10');$old=$this->documentedEntry($b,10,'cargo','2032-08-09');$future=$this->documentedEntry($b,20,'abono','2032-08-25');$url='/bancos/'.$b->id.'/conciliacion';
        $this->post($url,$this->reconciliationPayload('2032-08-10',100,[$a->id]))->assertSessionHasNoErrors();
        $first=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->first();$this->assertSame('90.00',$first->saldo_libros);
        $this->assertSame('en_transito',DB::table('movimientos_bancarios')->find($old->id)->estado_conciliacion);$this->assertSame('pendiente',DB::table('movimientos_bancarios')->find($future->id)->estado_conciliacion);
        $this->post($url,$this->reconciliationPayload('2032-08-20',90,[$old->id],$first->id))->assertSessionHasNoErrors();
        $last=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->orderByDesc('id')->first();$this->assertSame('90.00',$last->saldo_libros);
        $this->assertSame('conciliado',DB::table('movimientos_bancarios')->find($old->id)->estado_conciliacion);$this->assertSame('pendiente',DB::table('movimientos_bancarios')->find($future->id)->estado_conciliacion);
    }
    public static function invalidCases(): array {return array_map(fn($v)=>[$v],['equal','earlier','stale','missing_base','difference','other_bank','already_cleared','future','wrong_currency','inconsistent_reference']);}
    #[DataProvider('invalidCases')]
    public function test_invalid_cuts_leave_originals_and_balances_unchanged(string $case): void {
        $b=$this->reconciliationBank();$a=$this->documentedEntry($b,100,'abono','2032-08-10');$url='/bancos/'.$b->id.'/conciliacion';$this->post($url,$this->reconciliationPayload('2032-08-10',100,[$a->id]))->assertSessionHasNoErrors();
        $first=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->first();$p=$this->reconciliationPayload('2032-08-20',100,[],$first->id);
        if($case==='equal')$p['fecha']='2032-08-10';if($case==='earlier')$p['fecha']='2032-08-09';if($case==='stale')$p['base_conciliacion_id']=null;if($case==='missing_base')unset($p['base_conciliacion_id']);if($case==='difference')$p['saldo_extracto']=101;
        if($case==='other_bank'){$other=$this->reconciliationBank('LAFISE');$m=$this->documentedEntry($other,20);$p['movimientos']=[$m->id];}
        if($case==='already_cleared')$p['movimientos']=[$a->id];
        if($case==='future'){$m=$this->documentedEntry($b,20,'abono','2032-08-25');$p['movimientos']=[$m->id];}
        if(in_array($case,['wrong_currency','inconsistent_reference'])){$m=$this->documentedEntry($b,20,'abono','2032-08-15');DB::table('movimientos_bancarios')->where('id',$m->id)->update($case==='wrong_currency'?['moneda_id'=>DB::table('monedas')->where('codigo','USD')->value('id')]:['conciliado'=>true]);}
        $before=[];foreach(['cuentas_bancarias','movimientos_bancarios','conciliaciones_bancarias','asientos_contables','detalle_asientos'] as $t)$before[$t]=DB::table($t)->get()->toJson();
        $this->post($url,$p)->assertSessionHasErrors();foreach($before as $t=>$rows)$this->assertSame($rows,DB::table($t)->get()->toJson(),$t);
    }
    public function test_empty_cut_order_and_retrodated_new_movement_do_not_change_closed_snapshot(): void {
        $b=$this->reconciliationBank();$url='/bancos/'.$b->id.'/conciliacion';$this->post($url,$this->reconciliationPayload('2032-08-10',0))->assertSessionHasNoErrors();
        $first=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$b->id)->first();$original=json_encode($first);
        $this->post($url,$this->reconciliationPayload('2032-08-10',0,[],$first->id))->assertSessionHasErrors();
        $m=$this->documentedEntry($b,20,'abono','2032-08-09');
        $this->post($url,$this->reconciliationPayload('2032-08-10',20,[$m->id],$first->id))->assertSessionHasErrors();
        $this->post($url,$this->reconciliationPayload('2032-08-20',20,[$m->id],$first->id))->assertSessionHasNoErrors();$this->assertSame($original,json_encode(DB::table('conciliaciones_bancarias')->find($first->id)));
    }
    public static function failures(): array {return [['header'],['first_mark'],['before_commit']];}
    #[DataProvider('failures')]
    public function test_failure_restores_every_state_and_reference(string $stage): void {
        $b=$this->reconciliationBank();$a=$this->documentedEntry($b,100);$c=$this->documentedEntry($b,20);$before=[];foreach(['movimientos_bancarios','conciliaciones_bancarias','cuentas_bancarias','asientos_contables'] as $t)$before[$t]=DB::table($t)->get()->toJson();$updates=0;
        DB::connection()->beforeExecuting(function($sql)use($stage,&$updates){
            if(($stage==='header'&&str_starts_with($sql,'update `movimientos_bancarios`'))||($stage==='first_mark'&&str_starts_with($sql,'update `movimientos_bancarios`')&&++$updates===2)
                ||($stage==='before_commit'&&str_starts_with($sql,'select * from `movimientos_bancarios`')&&str_contains($sql,'`conciliacion_id` =')))throw new \Error('Injected reconciliation failure');
        });
        $level=DB::transactionLevel();$this->post('/bancos/'.$b->id.'/conciliacion',$this->reconciliationPayload('2032-08-25',120,[$a->id,$c->id]))->assertSessionHasErrors();
        foreach($before as $t=>$rows)$this->assertSame($rows,DB::table($t)->get()->toJson(),$t);$this->assertSame($level,DB::transactionLevel());
    }
}
