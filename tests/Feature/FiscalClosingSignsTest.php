<?php

namespace Tests\Feature;

use App\Http\Controllers\{ContabilidadController,PeriodoContableController};
use App\Models\{AsientoContable,CuentaContable,DetalleAsiento,PeriodoContable};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** New periods only; no seeds, persistent races or edits to historical documents. */
class FiscalClosingSignsTest extends TestCase
{
    use DatabaseTransactions, AccountingPolicyFixtures;

    private PeriodoContable $period;
    private CuentaContable $asset;
    private array $nominal=[];

    protected function setUp(): void
    {
        parent::setUp();$this->policyActor();Carbon::setTestNow('2036-11-15');
        $this->period=PeriodoContable::create(['nombre'=>'AC05C0 TRANSACTIONAL SYNTHETIC ONLY',
            'fecha_inicio'=>'2036-10-01','fecha_fin'=>'2036-10-31','estado'=>'abierto']);
        $this->asset=$this->account('1','activo');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();parent::tearDown();
    }

    private function account(string $prefix,string $type,string $nature='deudora'): CuentaContable
    {
        return CuentaContable::create(['codigo'=>$prefix.'.'.bin2hex(random_bytes(5)),'nombre'=>'AC05C0 synthetic',
            'tipo'=>$type,'naturaleza'=>$nature,'permite_movimiento'=>true,'activa'=>true,'nivel'=>1]);
    }

    private function amount(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    private function cents(string $amount): int
    {
        return (str_starts_with($amount,'-')?-1:1)*(int)str_replace(['-','.'],'',$amount);
    }

    private function fixture(array $balances): AsientoContable
    {
        $entry=AsientoContable::create(['numero_asiento'=>'AC05C0-'.bin2hex(random_bytes(6)),
            'fecha'=>'2036-10-15','concepto'=>'AC05C0 fixture, not business','modulo_origen'=>'manual',
            'periodo_id'=>$this->period->id,'usuario_id'=>auth()->id()]);
        $currency=DB::table('monedas')->where('es_base',true)->value('id');$net=0;
        foreach($balances as [$prefix,$signed]) {
            $type=match($prefix){'4'=>'ingreso','5'=>'costo',default=>'gasto'};
            $a=$this->account($prefix,$type,$prefix==='4'&&$signed<0?'acreedora':'deudora');
            $this->nominal[$a->id]=$signed;$net+=$signed;
            DetalleAsiento::create(['asiento_id'=>$entry->id,'cuenta_id'=>$a->id,'moneda_id'=>$currency,
                'debe'=>$this->amount(max($signed,0)),'haber'=>$this->amount(max(-$signed,0))]);
        }
        DetalleAsiento::create(['asiento_id'=>$entry->id,'cuenta_id'=>$this->asset->id,'moneda_id'=>$currency,
            'debe'=>$this->amount(max(-$net,0)),'haber'=>$this->amount(max($net,0))]);
        return $entry;
    }

    public static function balances(): array
    {
        // Signed original D-H in cents. Expected closing is its exact inverse.
        return [
            'normal income / profit'=>[[['4',-10000]]],
            'contra income / loss'=>[[['4',1000]]],
            'normal expense / loss'=>[[['6',2000]]],
            'contrary expense'=>[[['6',-500]]],
            'contrary cost'=>[[['5',-700]]],
            'contrary other expense'=>[[['7',-300]]],
            'normal cost'=>[[['5',2000]]],
            'zero result'=>[[['4',-2000],['6',2000]]],
            'zero nominal balance'=>[[['4',-10000],['6',0]]],
            'mixed profit'=>[[['4',-10000],['4',1000],['6',2000],['6',-500],['5',-700],['7',-300]]],
            'mixed loss'=>[[['4',-1000],['4',500],['5',2000],['6',3000],['7',100]]],
            'exact cents'=>[[['4',-10001],['4',1],['5',2003],['6',-7],['7',2]]],
            'all zero'=>[[['4',0],['5',0],['6',0],['7',0]]],
        ];
    }

    #[DataProvider('balances')]
    public function test_closing_neutralizes_each_account_with_nonnegative_sides(array $balances): void
    {
        $original=$this->fixture($balances);$originalRows=$original->detalles()->orderBy('id')->get()->toJson();
        $baselineResult=app(ContabilidadController::class)->resultados(Request::create('/','GET',['mes'=>'2036-10']))->getData()['utilidadNeta'];
        $baselineBalance=app(PeriodoContableController::class)->balanceGeneral(Request::create('/','GET',['fecha_corte'=>'2036-10-31']))->getData();
        $level=DB::transactionLevel();app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertFalse(session()->has('errors'));$this->assertSame($level,DB::transactionLevel());
        $this->assertSame('cerrado',$this->period->refresh()->estado);
        $closing=AsientoContable::where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->sole();
        $this->assertSame('2036-10-31',substr((string)$closing->fecha,0,10));$this->assertEquals($this->period->id,$closing->referencia_id);
        $rows=DB::table('detalle_asientos')->where('asiento_id',$closing->id)->get();$d=$h=0;
        foreach($rows as $row){
            $debit=$this->cents($row->debe);$credit=$this->cents($row->haber);
            $this->assertGreaterThanOrEqual(0,$debit);$this->assertGreaterThanOrEqual(0,$credit);
            $this->assertTrue(($debit===0) xor ($credit===0));$d+=$debit;$h+=$credit;
        }
        $this->assertSame($d,$h);
        foreach($this->nominal as $id=>$balance){
            $row=$rows->firstWhere('cuenta_id',$id);
            if($balance===0){$this->assertNull($row);continue;}
            $this->assertNotNull($row);$this->assertSame(-$balance,$this->cents($row->debe)-$this->cents($row->haber));
            $all=DB::table('detalle_asientos as d')->join('asientos_contables as a','a.id','=','d.asiento_id')
                ->where('a.periodo_id',$this->period->id)->where('d.cuenta_id',$id)->get(['d.debe','d.haber']);
            $sum=0;foreach($all as $l)$sum+=$this->cents($l->debe)-$this->cents($l->haber);$this->assertSame(0,$sum);
        }
        $retainedId=CuentaContable::where('codigo','3.2')->sole()->id;$retained=$rows->firstWhere('cuenta_id',$retainedId);
        $net=array_sum($this->nominal);
        if($net===0)$this->assertNull($retained);
        else {$this->assertNotNull($retained);$this->assertSame($net,$this->cents($retained->debe)-$this->cents($retained->haber));}
        $this->assertSame($originalRows,$original->detalles()->orderBy('id')->get()->toJson());
        $after=app(ContabilidadController::class)->resultados(Request::create('/','GET',['mes'=>'2036-10']))->getData();
        $this->assertEquals($baselineResult,$after['utilidadNeta']);
        $afterBalance=app(PeriodoContableController::class)->balanceGeneral(Request::create('/','GET',['fecha_corte'=>'2036-10-31']))->getData();
        $this->assertEquals($baselineBalance['totalActivo'],$afterBalance['totalActivo']);
        $this->assertEquals($baselineBalance['totalPatrimonio'],$afterBalance['totalPatrimonio']);
        $diary=app(ContabilidadController::class)->diario()->getData()['asientos']->whereIn('id',[$original->id,$closing->id]);$this->assertCount(2,$diary);
        foreach($this->nominal as $id=>$balance){
            $mayor=app(ContabilidadController::class)->mayor(Request::create('/','GET',['cuenta_id'=>$id]))->getData();
            $this->assertEquals(0,$mayor['totales']['saldo']);
        }
        // Repeated request (including a stale open model) must not emit a second closing.
        $this->period->estado='abierto';app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));
        $this->assertSame(1,AsientoContable::where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->count());
    }

    public static function faults(): array
    {
        return [['header','exception'],['first_line','exception'],['period','exception'],
            ['header','error'],['first_line','error'],['period','error']];
    }

    #[DataProvider('faults')]
    public function test_exception_or_throwable_rolls_back_complete_closing(string $stage,string $kind): void
    {
        $entry=$this->fixture([['4',-10000],['4',1000],['6',2000]]);
        $before=$entry->detalles()->get()->toJson();$headers=DB::table('asientos_contables')->count();$lines=DB::table('detalle_asientos')->count();$level=DB::transactionLevel();$hits=0;
        DB::connection()->beforeExecuting(function($sql)use($stage,$kind,&$hits){
            $fail=($stage==='header'&&str_starts_with($sql,'insert into `detalle_asientos`'))
                ||($stage==='first_line'&&str_starts_with($sql,'insert into `detalle_asientos`')&&++$hits===2)
                ||($stage==='period'&&str_starts_with($sql,'update `periodos_contables`'));
            if($fail)throw ($kind==='error'?new \Error('AC05C0 injected Throwable'):new \RuntimeException('AC05C0 injected Exception'));
        });
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));$this->assertSame($level,DB::transactionLevel());
        $this->assertSame('abierto',$this->period->refresh()->estado);
        $this->assertSame($headers,DB::table('asientos_contables')->count());$this->assertSame($lines,DB::table('detalle_asientos')->count());
        $this->assertSame($before,$entry->detalles()->get()->toJson());
    }

    public function test_already_closed_period_is_rejected_without_effects(): void
    {
        $this->period->update(['estado'=>'cerrado']);$count=DB::table('asientos_contables')->count();
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));$this->assertSame($count,DB::table('asientos_contables')->count());
    }

    public function test_line_exceeding_decimal_limit_rejects_entire_closing(): void
    {
        $entry=$this->fixture([['4',-600000000000000]]);
        foreach($entry->detalles()->get() as $line)DetalleAsiento::create($line->only(['asiento_id','cuenta_id','moneda_id','debe','haber']));
        $n=DB::table('detalle_asientos')->count();$h=DB::table('asientos_contables')->count();
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));$this->assertSame('abierto',$this->period->refresh()->estado);
        $this->assertSame($n,DB::table('detalle_asientos')->count());$this->assertSame($h,DB::table('asientos_contables')->count());
    }

    public function test_retained_earnings_overflow_rolls_back_preceding_nominal_lines(): void
    {
        $this->fixture([['4',-600000000000000]]);
        $this->fixture([['4',-600000000000000]]);
        $n=DB::table('detalle_asientos')->count();$h=DB::table('asientos_contables')->count();
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));$this->assertSame('abierto',$this->period->refresh()->estado);
        $this->assertSame($n,DB::table('detalle_asientos')->count());$this->assertSame($h,DB::table('asientos_contables')->count());
    }

    public function test_nominal_with_equal_nonzero_debit_and_credit_is_omitted(): void
    {
        $entry=$this->fixture([['4',0]]);$id=array_key_first($this->nominal);
        DetalleAsiento::create(['asiento_id'=>$entry->id,'cuenta_id'=>$id,
            'moneda_id'=>DB::table('monedas')->where('es_base',true)->value('id'),'debe'=>'10.00','haber'=>'10.00']);
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertFalse(session()->has('errors'));$this->assertSame('cerrado',$this->period->refresh()->estado);
        $closing=AsientoContable::where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->sole();
        $this->assertSame(0,$closing->detalles()->count());
    }

    public function test_maximum_decimal_line_keeps_its_last_cent(): void
    {
        $this->fixture([['4',-999999999999999]]);
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertFalse(session()->has('errors'));$this->assertSame('cerrado',$this->period->refresh()->estado);
        $closing=AsientoContable::where('periodo_id',$this->period->id)->where('modulo_origen','cierre_fiscal')->sole();
        $rows=$closing->detalles()->get();$this->assertCount(2,$rows);
        $nominal=$rows->firstWhere('cuenta_id',array_key_first($this->nominal));
        $this->assertSame('9999999999999.99',$nominal->debe);$this->assertSame('0.00',$nominal->haber);
        $retained=$rows->firstWhere('cuenta_id',CuentaContable::where('codigo','3.2')->sole()->id);
        $this->assertSame('0.00',$retained->debe);$this->assertSame('9999999999999.99',$retained->haber);
    }

    public function test_inactive_nominal_account_keeps_existing_posting_guard_and_rolls_back(): void
    {
        $this->fixture([['4',-10000]]);$id=array_key_first($this->nominal);
        CuentaContable::whereKey($id)->update(['activa'=>false]);$n=DB::table('detalle_asientos')->count();$h=DB::table('asientos_contables')->count();
        app(PeriodoContableController::class)->cierreFiscal($this->period);
        $this->assertTrue(session()->has('errors'));$this->assertSame('abierto',$this->period->refresh()->estado);
        $this->assertSame($n,DB::table('detalle_asientos')->count());$this->assertSame($h,DB::table('asientos_contables')->count());
    }

    public static function invalidAmounts(): array
    {
        return [['0.001'],['1e2'],[1.0],['10000000000000.00']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_internal_amount_reader_rejects_unrepresentable_originals(mixed $value): void
    {
        $method=new \ReflectionMethod(PeriodoContableController::class,'centavosCierre');
        $this->expectException(\App\Exceptions\ContabilidadException::class);
        $method->invoke(app(PeriodoContableController::class),$value);
    }
}
