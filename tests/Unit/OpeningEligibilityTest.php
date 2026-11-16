<?php

namespace Tests\Unit;

use App\Services\OpeningInventoryEligibility;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OpeningContractHypotheses;

final class OpeningEligibilityTest extends TestCase
{
    use OpeningContractHypotheses;

    public function test_clean_snapshot_is_only_provisional_and_formula_alone_is_not_history(): void
    {
        $item=$this->items()[1]; $before=$item;
        $r=(new OpeningInventoryEligibility)->assess($item,[]);
        self::assertTrue($r['eligible_at_inspection']); self::assertTrue($r['requires_locked_recheck']);
        self::assertFalse($r['confirmation_allowed']); self::assertSame($before,$item);
    }

    public static function histories(): array
    {
        return array_map(fn ($h)=>[$h],['lotes','stock_ledger_entries','stock_ledger_lot_allocations','purchase_invoice_lines',
            'sale_details','sale_detail_consumptions','mermas','inventory_opening_lines','documented_service_formula',
            'documented_service_snapshot','legacy_service_composition_unresolved']);
    }

    #[DataProvider('histories')]
    public function test_zero_balance_does_not_erase_history(string $history): void
    {
        $r=(new OpeningInventoryEligibility)->assess($this->items()[1],[$history]);
        self::assertFalse($r['eligible_at_inspection']); self::assertContains($history,$r['reasons']);
    }

    public static function incompatibleItems(): array
    {
        return [['deleted_at','2026-01-01'],['type','servicio'],['existencia_actual','1'],['current_volume','0.01'],
            ['costo_promedio','1'],['current_volume',null],['inventory_representation_version',1],['is_fractionable',null]];
    }

    #[DataProvider('incompatibleItems')]
    public function test_incompatible_item_is_not_eligible(string $key,$value): void
    {
        $item=$this->items()[1]; $item[$key]=$value;
        self::assertFalse((new OpeningInventoryEligibility)->assess($item,[])['eligible_at_inspection']);
    }

    public function test_missing_item(): void
    {
        self::assertContains('missing_item',(new OpeningInventoryEligibility)->assess(null,[])['reasons']);
    }

    public function test_query_adapter_issues_only_bound_selects_without_opening_sql_connection(): void
    {
        $mock=$this->createMock(ConnectionInterface::class); $calls=[]; $item=$this->items()[1];
        $mock->expects(self::exactly(12))->method('select')->willReturnCallback(function ($sql,$bindings=[]) use (&$calls,$item) {
            self::assertStringStartsWith('SELECT ',$sql); self::assertStringNotContainsString('FOR UPDATE',$sql);
            self::assertStringNotContainsString('salon_erp',$sql); $calls[]=$sql;
            if (str_starts_with($sql,'SELECT * FROM items')) {self::assertSame([1],$bindings);return [(object)$item];}
            if (str_contains($sql,'FROM lotes')) return [(object)['id'=>8]];
            if (str_contains($sql,'JSON_CONTAINS')) { self::assertSame([1],$bindings); return [(object)['id'=>10]]; }
            return [];
        });
        foreach (['insert','update','delete','statement','affectingStatement','unprepared','transaction','beginTransaction','commit','rollBack'] as $method) {
            $mock->expects(self::never())->method($method);
        }
        $r=(new OpeningInventoryEligibility)->inspect($mock,1);
        self::assertContains('lotes',$r['reasons']); self::assertContains('documented_service_snapshot',$r['reasons']);
        self::assertCount(12,$calls);
    }
}
