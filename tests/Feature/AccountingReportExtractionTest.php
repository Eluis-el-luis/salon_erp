<?php

namespace Tests\Feature;

use App\Models\{AsientoContable,CuentaContable,DetalleAsiento,PeriodoContable};
use App\Services\AccountingReportExtractor;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

class AccountingReportExtractionTest extends TestCase
{
    use DatabaseTransactions, AccountingPolicyFixtures;
    private PeriodoContable $period;
    private array $map=[];
    private array $entries=[];
    private bool $reading=false;

    protected function setUp(): void
    {
        parent::setUp();$this->policyActor();Carbon::setTestNow('2035-08-15');
        $this->period=PeriodoContable::create(['nombre'=>'AC05B2 TRANSACTION ONLY','fecha_inicio'=>'2035-08-01','fecha_fin'=>'2035-08-31','estado'=>'abierto']);
        DB::connection()->beforeExecuting(function($sql){
            if($this->reading&&!preg_match('/^\s*(select|show|set transaction)\b/i',$sql))throw new \LogicException('Extractor mutated database.');
        });
    }
    protected function tearDown(): void {$this->reading=false;Carbon::setTestNow();parent::tearDown();}
    private function account(string $code,string $group,array $extra=[]): CuentaContable
    {
        $type=match($group){'asset'=>'activo','equity'=>'patrimonio','income'=>'ingreso','cost'=>'costo',default=>'gasto'};
        $a=CuentaContable::create(array_replace(['codigo'=>$code.bin2hex(random_bytes(4)),'nombre'=>'AC05B2 synthetic',
            'tipo'=>$type,'naturaleza'=>in_array($group,['equity','income'],true)?'acreedora':'deudora',
            'permite_movimiento'=>true,'activa'=>true,'nivel'=>1],$extra));$this->map[$a->id]=$group;return $a;
    }
    private function entry(array $lines,?string $origin='manual',array $extra=[]): AsientoContable
    {
        $e=AsientoContable::create(array_replace(['numero_asiento'=>'AC05B2-'.bin2hex(random_bytes(6)),
            'fecha'=>'2035-08-15','concepto'=>'AC05B2 synthetic','modulo_origen'=>$origin,'estado'=>'contabilizado',
            'periodo_id'=>$this->period->id,'usuario_id'=>auth()->id()],$extra));$this->entries[]=$e->id;
        foreach($lines as $line){[$a,$d,$h]=$line;DetalleAsiento::create(['asiento_id'=>$e->id,'cuenta_id'=>$a->id,
            'moneda_id'=>DB::table('monedas')->where('es_base',true)->value('id'),'debe'=>$d,'haber'=>$h,'centro_costo_id'=>$line[3]??null]);}
        return $e;
    }
    private function classification(): array
    {
        $a=[];foreach($this->map as $id=>$g)$a[]=['account_id'=>$id,'group'=>$g];
        return ['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B2-hypothesis-1','assignments'=>$a];
    }
    private function p(string $report='result',array $extra=[]): array
    {
        return array_replace(['report'=>$report,'from'=>$report==='result'?'2035-08-01':null,'until'=>'2035-08-31',
            'states'=>['borrador','contabilizado','anulado'],'null_origin'=>'include',
            'account_scope'=>array_keys($this->map),'center_scope'=>'all'],$extra);
    }
    private function compare(string $report='result',array $extra=[]): array
    {
        $this->reading=true;try{return app(AccountingReportExtractor::class)->compare($this->classification(),$this->p($report,$extra));}
        finally{$this->reading=false;}
    }

    public static function amounts(): array
    {
        return [
            'income alternative'=>['INC-','income','0.00','120.00',12000,false],
            'asset alternative'=>['ACT-','asset','100.00','0.00',10000,false],
            'expense alternative'=>['EXP-','operating_expense','30.00','0.00',3000,false],
            'cost alternative'=>['CST-','cost','20.00','0.00',2000,false],
            'income corrector'=>['4.','income','10.00','0.00',-1000,true],
            'contra asset'=>['1.','asset','0.00','10.00',-1000,true],
            'contrary expense'=>['6.','operating_expense','0.00','5.00',-500,true],
            'inactive history'=>['6.','operating_expense','0.01','0.00',1,true],
        ];
    }
    #[DataProvider('amounts')]
    public function test_explicit_groups_preserve_exact_originals_and_explain_legacy_difference(string $prefix,string $group,string $d,string $h,int $expected,bool $conventional): void
    {
        $a=$this->account($prefix,$group,['activa'=>false]);$capital=$this->account('3.','equity');
        $e=$this->entry([[$a,$d,$h],[$capital,$h,$d]]);$before=$e->detalles()->orderBy('id')->get()->toJson();
        $r=$this->compare('balance');$s=$r['synthetic'];
        $this->assertSame($expected,$s['accounts'][$a->id]['contribution_cents']);
        $original=$e->detalles()->where('cuenta_id',$a->id)->sole();$row=$s['details'][$original->id];
        $this->assertSame($original->debe,$row['debit']);$this->assertSame($original->haber,$row['credit']);
        $this->assertSame($e->id,$row['entry_id']);$this->assertSame((int)$original->moneda_id,$row['currency_id']);
        $this->assertFalse($row['current_account']['active']);$this->assertSame($before,$e->detalles()->orderBy('id')->get()->toJson());
        $diff=$r['differences']['details'][$original->id];
        $this->assertSame($conventional?'equal':'classification_changed',$diff['difference']);
        $this->assertSame(0,$r['differences']['accounts'][$a->id]['debit_delta_cents']);
        $this->assertSame($s['debit_cents'],$s['credit_cents']);$this->assertCount(2,$s['details']);
    }

    public function test_result_alternate_codes_and_cost_expense_separation(): void
    {
        $a=$this->account('1.','asset');$i=$this->account('INC-','income');$c=$this->account('CST-','cost');$g=$this->account('EXP-','operating_expense');
        $this->entry([[$a,'70.00','0.00'],[$i,'0.00','100.00'],[$c,'20.00','0.00'],[$g,'10.00','0.00']]);
        $r=$this->compare();$this->assertSame(['gross'=>8000,'operating'=>7000,'result'=>7000],$r['synthetic']['subtotals_cents']);
        $this->assertSame([],$r['legacy']['details']);$this->assertSame(10000,$r['differences']['groups_cents']['income']);
        $this->assertSame(4,count(array_filter($r['differences']['details'],fn($d)=>$d['difference']==='synthetic_only')));
    }

    public function test_nonpostable_parent_without_postings_does_not_add_a_second_contribution(): void
    {
        $parent=$this->account('6.','operating_expense',['permite_movimiento'=>false]);
        $child=$this->account('6.','operating_expense',['cuenta_padre_id'=>$parent->id,'nivel'=>2]);$a=$this->account('1.','asset');
        $this->entry([[$child,'40.00','0.00'],[$a,'0.00','40.00']]);$r=$this->compare();
        $this->assertSame(4000,$r['synthetic']['groups_cents']['operating_expense']);
        $this->assertSame(4000,$r['legacy']['groups_cents']['operating_expense']);
        $this->assertArrayNotHasKey($parent->id,$r['synthetic']['accounts']);$this->assertCount(2,$r['synthetic']['details']);
    }

    public function test_states_null_period_center_and_detail_sets_are_not_silently_equalized(): void
    {
        $a=$this->account('1.','asset');$i=$this->account('4.','income');
        $other=PeriodoContable::create(['nombre'=>'AC05B2 mismatch','fecha_inicio'=>'2035-07-01','fecha_fin'=>'2035-07-31','estado'=>'cerrado']);
        $center=DB::table('centros_costo')->insertGetId(['codigo'=>'AC05B2-'.bin2hex(random_bytes(3)),'nombre'=>'Historic fixture center','activo'=>false,'created_at'=>now(),'updated_at'=>now()]);
        $this->entry([[$a,'10.00','0.00'],[$i,'0.00','10.00']],null);
        $this->entry([[$a,'20.00','0.00'],[$i,'0.00','20.00']],'manual',['estado'=>'borrador']);
        $this->entry([[$a,'30.00','0.00'],[$i,'0.00','30.00']],'ventas',['estado'=>'anulado','periodo_id'=>$other->id]);
        $e=$this->entry([[$a,'40.00','0.00',$center],[$i,'0.00','40.00',$center]]);
        $r=$this->compare();$this->assertSame(10000,$r['synthetic']['subtotals_cents']['result']);$this->assertSame(9000,$r['legacy']['subtotals_cents']['result']);
        $strict=$this->compare('result',['states'=>['contabilizado'],'null_origin'=>'exclude','period_id'=>$this->period->id,'center_scope'=>[$center]]);
        $this->assertSame(4000,$strict['synthetic']['subtotals_cents']['result']);
        $this->assertSame(9000,$strict['legacy']['subtotals_cents']['result']);
        $this->assertSame($e->detalles()->pluck('id')->all(),array_keys($strict['synthetic']['details']));
        $this->assertContains('legacy_filters_date_not_period_id',array_column($strict['warnings'],'kind'));
        $this->assertContains('legacy_does_not_filter_centers',array_column($strict['warnings'],'kind'));
        $this->assertContains('legacy_includes_all_entry_states',array_column($strict['warnings'],'kind'));
        $this->assertCount(6,$this->compare('result',['center_scope'=>'unassigned'])['synthetic']['details']);
    }

    public function test_hierarchy_multiple_real_bindings_and_manual_mirror_do_not_duplicate(): void
    {
        $a=$this->account('1.','asset');$parent=$this->account('5.','cost');$child=$this->account('5.','cost',['cuenta_padre_id'=>$parent->id,'nivel'=>2]);
        $v=$this->policyDraft();$bindings=$this->policyBindings();foreach($bindings as &$b)if(in_array($b['role'],['costo.producto','costo.servicio'],true))$b['account_id']=$parent->id;unset($b);
        app(\App\Services\AccountingPolicyService::class)->replaceBindings($v->id,$v->content_hash,$bindings,'AC05B2 fixture only');
        $this->assertSame(2,DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->where('account_id',$parent->id)->count());
        $this->entry([[$parent,'10.00','0.00'],[$child,'20.00','0.00'],[$a,'0.00','30.00']]);
        $this->entry([[$parent,'0.00','10.00'],[$child,'0.00','20.00'],[$a,'30.00','0.00']],'reversion');
        $r=$this->compare();$this->assertSame(0,$r['synthetic']['groups_cents']['cost']);$this->assertCount(6,$r['synthetic']['details']);
        $this->assertSame(6000,$r['synthetic']['debit_cents']);$this->assertSame(6000,$r['synthetic']['credit_cents']);
    }

    public function test_new_charge_and_refund_fixture_keeps_bank_and_original_accounts(): void
    {
        // Accounting projection only; no pilot writer or bank balance is invoked.
        $bank=$this->account('1.','asset');$g=$this->account('6.','operating_expense');
        $original=$this->entry([[$g,'50.00','0.00'],[$bank,'0.00','50.00']],'bancos');
        $refund=$this->entry([[$bank,'50.00','0.00'],[$g,'0.00','50.00']],'bank_charge_refunds',['referencia_id'=>$original->id]);
        $r=$this->compare('balance');$this->assertSame(0,$r['synthetic']['pending_result_cents']);$this->assertSame(0,$r['synthetic']['balance_difference_cents']);
        $this->assertCount(4,$r['synthetic']['details']);$this->assertSame($original->id,(int)$r['synthetic']['details'][$refund->detalles()->first()->id]['reference_id']);
    }

    public function test_corrected_closing_projection_has_distinct_result_and_balance_universes(): void
    {
        $a=$this->account('1.','asset');$i=$this->account('4.','income');$contra=$this->account('4.','income');$equity=$this->account('3.','equity');
        $this->entry([[$a,'90.00','0.00'],[$contra,'10.00','0.00'],[$i,'0.00','100.00']]);
        // Exact AC05C0 representation as a NEW fixture; never call cierreFiscal here.
        $close=$this->entry([[$i,'100.00','0.00'],[$contra,'0.00','10.00'],[$equity,'0.00','90.00']],'cierre_fiscal');
        $r=$this->compare();$b=$this->compare('balance');
        $this->assertSame(9000,$r['synthetic']['subtotals_cents']['result']);$this->assertSame(0,$b['synthetic']['pending_result_cents']);
        $this->assertSame(9000,$b['synthetic']['groups_cents']['equity']);$this->assertSame(0,$b['synthetic']['balance_difference_cents']);
        foreach($close->detalles()->pluck('id') as $id){$this->assertArrayNotHasKey($id,$r['synthetic']['details']);$this->assertArrayHasKey($id,$b['synthetic']['details']);}
    }

    public function test_missing_classification_is_rejected_with_coverage_warning_and_no_partial_result(): void
    {
        $a=$this->account('1.','asset');$i=$this->account('4.','income');$this->entry([[$a,'1.00','0.00'],[$i,'0.00','1.00']]);
        $p=$this->p();unset($this->map[$i->id]);$this->reading=true;
        $r=app(AccountingReportExtractor::class)->compare($this->classification(),$p);
        $this->assertNull($r['synthetic']);$this->assertSame('rejected_missing_classification',$r['synthetic_status']);
        $this->assertSame([$i->id],$r['warnings'][0]['account_ids']);$this->assertSame([],$r['differences']['details']);
    }

    public function test_arbitrary_range_can_be_extracted_but_not_mislabelled_as_monthly_legacy_comparison(): void
    {
        $a=$this->account('1.','asset');$this->entry([[$a,'0.01','0.01']]);$p=$this->p('result',['from'=>'2035-08-10','until'=>'2035-08-20']);
        $this->reading=true;$r=app(AccountingReportExtractor::class)->extract($this->classification(),$p);
        $this->assertSame(1,$r['synthetic']['debit_cents']);$this->assertSame(1,$r['synthetic']['credit_cents']);
        $this->expectException(DomainException::class);app(AccountingReportExtractor::class)->compare($this->classification(),$p);
    }

    public static function invalid(): array
    {
        return [['duplicate'],['missing_scope'],['invalid_state'],['invalid_date'],['unknown'],['balance_period'],
            ['unknown_account'],['unknown_center'],['unknown_period']];
    }
    #[DataProvider('invalid')]
    public function test_ambiguous_contract_is_rejected(string $case): void
    {
        $a=$this->account('1.','asset');$c=$this->classification();$p=$this->p();
        match($case){'duplicate'=>$c['assignments'][]=$c['assignments'][0], 'missing_scope'=>$p['account_scope']=[],
            'invalid_state'=>$p['states']=['guessed'], 'invalid_date'=>$p['until']='2035-02-30','unknown'=>$p['account_id']=1,
            'balance_period'=>$p=array_replace($this->p('balance'),['period_id'=>$this->period->id]),
            'unknown_account'=>$p['account_scope']=[2147483647], 'unknown_center'=>$p['center_scope']=[2147483647],
            'unknown_period'=>$p['period_id']=2147483647};
        $this->expectException(DomainException::class);app(AccountingReportExtractor::class)->compare($c,$p);
    }

    public function test_ordinary_operator_has_no_internal_reader_authority(): void
    {
        $a=$this->account('1.','asset');$actor=auth()->user();$actor->role='cajera';
        $this->expectException(DomainException::class);app(AccountingReportExtractor::class)->extract($this->classification(),$this->p());
    }

    public function test_invalid_enclosing_isolation_is_rejected_without_changing_it(): void
    {
        $a=$this->account('1.','asset');DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            try{app(AccountingReportExtractor::class)->extract($this->classification(),$this->p());$this->fail('Must reject incompatible enclosing fixture configuration.');}
            catch(DomainException $e){$this->assertStringContainsString('REPEATABLE READ',$e->getMessage());}
            $this->assertSame('READ-COMMITTED',DB::selectOne('SELECT @@transaction_isolation level')->level);
        } finally {DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
    }
}
