<?php

namespace Tests\Unit;

use App\Services\AccountingCatalogueInventory;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingCatalogueInventoryTest extends TestCase
{
    private function account(int $id=1,array $extra=[]): array
    {
        return array_replace(['id'=>$id,'codigo'=>'4.1','nombre'=>'Fixture, no economic approval','tipo'=>'ingreso','naturaleza'=>'acreedora',
            'cuenta_padre_id'=>null,'nivel'=>1,'permite_movimiento'=>1,'activa'=>1,'is_system_account'=>0,'created_at'=>null,'updated_at'=>null],$extra);
    }
    private function detail(int $id=1,array $extra=[]): array
    {
        return array_replace(['detail_id'=>$id,'account_id'=>1,'entry_id'=>$id,'debit'=>'0.00','credit'=>'10.00','currency_id'=>1,
            'date'=>'2030-10-15','period_id'=>1,'state'=>'contabilizado','origin'=>'manual','entry_type'=>'diario','center_id'=>null,
            'period_start'=>'2030-10-01','period_end'=>'2030-10-31','period_state'=>'abierto','has_reference'=>false,'reverse_marker'=>false],$extra);
    }
    public function test_complete_inventory_keeps_empty_inactive_zero_net_and_nonpostable_parent(): void
    {
        $accounts=[$this->account(1,['permite_movimiento'=>0]),$this->account(2,['cuenta_padre_id'=>1,'nivel'=>2,'activa'=>0]),$this->account(3)];
        $details=[$this->detail(),$this->detail(2,['account_id'=>2]),$this->detail(3,['account_id'=>2,'debit'=>'10.00','credit'=>'0.00','reverse_marker'=>true])];
        $r=(new AccountingCatalogueInventory)->describe($accounts,$details,[1=>['bindings'=>3],2=>['bindings'=>9]]);
        self::assertCount(3,$r['accounts']);self::assertSame(3,$r['coverage']['unique_details']);
        self::assertSame(1,$r['accounts'][1]['activity']['count']);self::assertSame(2,$r['accounts'][2]['activity']['count']);
        self::assertSame([2],$r['accounts'][1]['children']);self::assertNull($r['accounts'][1]['legacy']['result']);
        self::assertSame(0,$r['accounts'][2]['activity']['nature_signed_cents']);self::assertSame(0,$r['accounts'][3]['activity']['count']);
        self::assertContains('Inactiva con historia',$r['accounts'][2]['observations']);
        self::assertSame(1000,$r['accounts'][1]['activity']['credit_cents']);self::assertSame(1000,$r['accounts'][2]['activity']['credit_cents']);
    }
    public function test_explicit_legacy_universes_preserve_states_null_closures_and_period_mismatch(): void
    {
        $ds=[$this->detail(1,['origin'=>null,'state'=>'borrador']),$this->detail(2,['origin'=>'cierre_fiscal','state'=>'anulado']),
            $this->detail(3,['state'=>'anulado','period_start'=>'2030-11-01'])];
        $r=(new AccountingCatalogueInventory)->describe([$this->account()],$ds)['accounts'][1];
        self::assertSame(3,$r['activity']['count']);self::assertSame(1,$r['legacy_universes']['result']['count']);
        self::assertSame(3,$r['legacy_universes']['balance']['count']);self::assertSame(1,$r['activity']['null_origin']);
        self::assertSame(1,$r['activity']['closing']);self::assertSame(1,$r['activity']['date_outside_period']);self::assertSame(2,$r['activity']['states']['anulado']);
    }
    public function test_alternative_code_and_nature_do_not_infer_economic_category(): void
    {
        $a=$this->account(1,['codigo'=>'9-ALT','tipo'=>'activo','naturaleza'=>'acreedora']);
        $r=(new AccountingCatalogueInventory)->describe([$a],[$this->detail()]);$x=$r['accounts'][1];
        self::assertNull($x['legacy']['result']);self::assertSame('resultado:otros',$x['legacy']['balance']);
        self::assertSame('PENDIENTE',$x['economic_review']['group']);self::assertSame('PENDIENTE DE CONFIRMACIÓN',$x['economic_review']['corrector']);
        self::assertSame(1000,$x['activity']['nature_signed_cents']);self::assertFalse($r['business_authority']);
    }
    public function test_monetary_precision_currency_separation_and_original_references_preserved(): void
    {
        $ds=[$this->detail(1,['credit'=>'0.01']),$this->detail(2,['currency_id'=>2,'credit'=>'1.00','debit'=>'-0.02'])];$copy=$ds;
        $r=(new AccountingCatalogueInventory)->describe([$this->account()],$ds)['accounts'][1]['activity'];
        self::assertSame(1,$r['currencies'][1]['credit_cents']);self::assertSame(100,$r['currencies'][2]['credit_cents']);
        self::assertSame(102,$r['currencies'][2]['nature_signed_cents']);self::assertSame(1,$r['negative_lines']);self::assertSame($copy,$ds);
    }
    public static function invalid(): array {return [['account'],['detail'],['orphan'],['parent'],['cycle'],['float'],['precision'],['nature']];}
    #[DataProvider('invalid')]
    public function test_inconsistent_memory_input_rejected(string $case): void
    {
        $a=[$this->account()];$d=[$this->detail()];
        match($case){'account'=>$a[]=$a[0],'detail'=>$d[]=$d[0],'orphan'=>$d[0]['account_id']=9,
            'parent'=>$a[0]['cuenta_padre_id']=9,'cycle'=>$a[0]['cuenta_padre_id']=1,
            'float'=>$d[0]['credit']=1.23,'precision'=>$d[0]['debit']='1.001','nature'=>$a[0]['naturaleza']='unknown'};
        $this->expectException(DomainException::class);(new AccountingCatalogueInventory)->describe($a,$d);
    }
}
