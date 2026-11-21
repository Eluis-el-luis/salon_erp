<?php

namespace Tests\Feature;

use App\Services\AccountingCatalogueInventoryReader;
use PHPUnit\Framework\TestCase;

/** Existing rows only; no Laravel traits and no SQL fixtures. */
final class AccountingCatalogueInventoryReadOnlyTest extends TestCase
{
    public function test_complete_repeatable_catalogue_and_amount_controls(): void
    {
        $a=(new AccountingCatalogueInventoryReader)->read();$b=(new AccountingCatalogueInventoryReader)->read();
        self::assertSame($a['accounts'],$b['accounts']);self::assertSame($a['controls'],$b['controls']);
        self::assertSame($a['controls']['account_count_sql'],count($a['accounts']));
        self::assertSame($a['controls']['detail_count_sql'],array_sum(array_column(array_column($a['accounts'],'activity'),'count')));
        self::assertSame($a['coverage']['details'],$a['coverage']['unique_details']);
        $cents=fn($v)=>(int)str_replace('.','',(string)$v);
        self::assertSame($cents($a['controls']['debit_sql']),array_sum(array_column(array_column($a['accounts'],'activity'),'debit_cents')));
        self::assertSame($cents($a['controls']['credit_sql']),array_sum(array_column(array_column($a['accounts'],'activity'),'credit_cents')));
        self::assertTrue($a['snapshot']['read_only']);self::assertTrue($a['snapshot']['owned']);
        self::assertNotSame($a['snapshot']['connection_id'],$b['snapshot']['connection_id']);
        foreach($a['accounts']as $r){
            self::assertSame('PENDIENTE',$r['economic_review']['group']);
            self::assertSame($r['activity']['count'],$r['dependencies']['detalle_asientos.cuenta_id']??0);
        }
        self::assertFalse($a['business_authority']);self::assertFalse(defined('SALON_SYNTHETIC_DRAFT_HARNESS'));
        self::assertSame('0',getenv('SALON_TEST_DB_RESET_ALLOWED'));
    }
}
