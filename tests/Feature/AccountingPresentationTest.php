<?php

namespace Tests\Feature;

use App\Services\SyntheticAccountingPresentation;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure fixtures: never bootstrap Laravel, select a policy or execute SQL. */
class AccountingPresentationTest extends TestCase
{
    private function config(array $assignments): array
    {
        return ['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B1-fixture-1',
            'assignments'=>array_map(fn($id,$group)=>['account_id'=>$id,'group'=>$group],array_keys($assignments),array_values($assignments))];
    }

    private function row(int $id,int $account,string $d,string $h,array $extra=[]): array
    {
        return array_replace(['detail_id'=>$id,'entry_id'=>100+$id,'account_id'=>$account,'period_id'=>1,
            'date'=>'2077-04-15','origin'=>'manual','state'=>'contabilizado','debit'=>$d,'credit'=>$h,
            'reference'=>'SYNTHETIC-'.$id,'code'=>'UNUSED','active'=>false],$extra);
    }

    private function parameters(string $report='result'): array
    {
        return ['report'=>$report,'from'=>$report==='result'?'2077-04-01':null,'until'=>'2077-04-30',
            'null_origin'=>'include','states'=>['contabilizado']];
    }

    public static function signs(): array
    {
        return [
            'alternative asset'=>['asset','100.00','0.00',10000],
            'alternative income'=>['income','0.00','120.00',12000],
            'alternative expense'=>['operating_expense','30.00','0.00',3000],
            'cost'=>['cost','20.00','0.00',2000],
            'contra income'=>['income','10.00','0.00',-1000],
            'contra asset'=>['asset','0.00','10.00',-1000],
            'contrary equity'=>['equity','10.00','0.00',-1000],
            'bank refund expense'=>['operating_expense','0.00','50.00',-5000],
            'last cent'=>['asset','0.01','0.00',1],
            'legacy negative closing'=>['income','-10.00','0.00',1000],
        ];
    }

    #[DataProvider('signs')]
    public function test_original_signs_without_code_or_active_inference(string $group,string $d,string $h,int $expected): void
    {
        $row=$this->row(1,1,$d,$h);
        $r=(new SyntheticAccountingPresentation)->read($this->config([1=>$group]),[$row],$this->parameters());
        $this->assertSame($expected,$r['groups_cents'][$group]);
        $this->assertSame($row,$r['details'][1]);
        $this->assertFalse($r['issued_statement']);
    }

    public function test_cost_subtotals_are_visual_and_do_not_duplicate_parent_or_bindings(): void
    {
        $rows=[$this->row(1,1,'10.00','0.00',['parent_id'=>9,'bindings'=>[22,23]]),
            $this->row(2,2,'20.00','0.00',['parent_id'=>1]),$this->row(3,3,'0.00','100.00'),
            $this->row(4,4,'20.00','0.00'),$this->row(5,5,'10.00','0.00')];
        $r=(new SyntheticAccountingPresentation)->read($this->config([1=>'asset',2=>'asset',3=>'income',4=>'cost',5=>'operating_expense']),$rows,$this->parameters());
        $this->assertSame(3000,$r['groups_cents']['asset']);
        $this->assertSame(['gross'=>8000,'operating'=>7000,'result'=>7000],$r['subtotals_cents']);
        $this->assertSame([1,2,3,4,5],array_keys($r['details']));
    }

    public function test_parent_without_postings_needs_no_economic_contribution(): void
    {
        $r=(new SyntheticAccountingPresentation)->read($this->config([2=>'asset']),[$this->row(1,2,'40.00','0.00',['parent_id'=>1])],$this->parameters('balance'));
        $this->assertSame(4000,$r['groups_cents']['asset']);
        $this->assertCount(1,$r['accounts']);
    }

    public function test_closing_is_excluded_from_result_and_included_in_balance(): void
    {
        $rows=[$this->row(1,1,'80.00','0.00'),$this->row(2,2,'0.00','100.00'),$this->row(3,3,'20.00','0.00'),
            $this->row(4,2,'100.00','0.00',['origin'=>'cierre_fiscal']),
            $this->row(5,3,'0.00','20.00',['origin'=>'cierre_fiscal']),
            $this->row(6,4,'0.00','80.00',['origin'=>'cierre_fiscal'])];
        $c=$this->config([1=>'asset',2=>'income',3=>'operating_expense',4=>'equity']);$s=new SyntheticAccountingPresentation;
        $result=$s->read($c,$rows,$this->parameters());$balance=$s->read($c,$rows,$this->parameters('balance'));
        $this->assertSame(8000,$result['subtotals_cents']['result']);
        $this->assertSame([1,2,3],array_keys($result['details']));
        $this->assertSame(8000,$balance['groups_cents']['equity']);
        $this->assertSame(0,$balance['pending_result_cents']);
        $this->assertSame(0,$balance['balance_difference_cents']);
        $this->assertSame($balance['debit_cents'],$balance['credit_cents']);
    }

    public function test_explicit_date_period_null_and_state_universe(): void
    {
        $rows=[$this->row(1,1,'0.00','1.00',['period_id'=>2,'origin'=>null]),
            $this->row(2,1,'0.00','2.00',['state'=>'borrador']),$this->row(3,1,'0.00','3.00',['date'=>'2077-03-31'])];
        $s=new SyntheticAccountingPresentation;$c=$this->config([1=>'income']);$p=$this->parameters();
        $this->assertSame([1],array_keys($s->read($c,$rows,$p)['details']));
        $this->assertSame([],$s->read($c,$rows,$p+['period_id'=>1])['details']);
        $p['null_origin']='exclude';$this->assertSame([],$s->read($c,$rows,$p)['details']);
        $p['states'][]='borrador';$this->assertSame([2],array_keys($s->read($c,$rows,$p)['details']));
    }

    public function test_manual_reverse_and_synthetic_charge_refund_preserve_exact_references(): void
    {
        $rows=[$this->row(1,1,'50.00','0.00',['origin'=>'bancos','operation_uuid'=>'synthetic-new-fixture']),
            $this->row(2,2,'0.00','50.00'),$this->row(3,1,'0.00','50.00',['origin'=>'bank_charge_refunds','original_detail_id'=>1]),
            $this->row(4,2,'50.00','0.00',['original_detail_id'=>2]),
            $this->row(5,1,'1.00','0.00',['origin'=>'manual']),$this->row(6,1,'0.00','1.00',['origin'=>'reversion'])];
        $r=(new SyntheticAccountingPresentation)->read($this->config([1=>'operating_expense',2=>'asset']),$rows,$this->parameters('balance'));
        $this->assertSame(0,$r['subtotals_cents']['result']);$this->assertSame(0,$r['balance_difference_cents']);
        $this->assertSame($rows,array_values($r['details']));$this->assertSame(1,$r['details'][3]['original_detail_id']);
    }

    public function test_classification_hash_is_order_stable_and_significant(): void
    {
        $s=new SyntheticAccountingPresentation;$p=$this->parameters();
        $a=$s->read($this->config([1=>'asset',2=>'income']),[],$p);
        $b=$s->read($this->config([2=>'income',1=>'asset']),[],$p);
        $this->assertSame($a['classification_hash'],$b['classification_hash']);
        $this->assertNotSame($a['classification_hash'],$s->read($this->config([1=>'asset',2=>'equity']),[],$p)['classification_hash']);
    }

    public static function invalid(): array
    {
        return ['unclassified'=>['missing'],'ambiguous'=>['duplicate'],'unknown group'=>['group'],
            'repeated detail'=>['detail'],'float'=>['float'],'precision'=>['precision'],'business'=>['business'],
            'missing universe'=>['universe'],'invalid date'=>['date'],'balance range'=>['range']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_incomplete_or_ambiguous_contract(string $case): void
    {
        $c=$this->config([1=>'asset']);$rows=[$this->row(1,1,'1.00','0.00')];$p=$this->parameters();
        match($case){
            'missing'=>$c['assignments']=[], 'duplicate'=>$c['assignments'][]=$c['assignments'][0],
            'group'=>$c['assignments'][0]['group']='guessed', 'detail'=>$rows[]=$rows[0],
            'float'=>$rows[0]['debit']=1.0, 'precision'=>$rows[0]['debit']='1.001',
            'business'=>$c['purpose']='business', 'universe'=>$p['states']=[],
            'date'=>$p['until']='2077-02-30', 'range'=>$p['report']='balance',
        };
        $this->expectException(DomainException::class);
        (new SyntheticAccountingPresentation)->read($c,$rows,$p);
    }
}
