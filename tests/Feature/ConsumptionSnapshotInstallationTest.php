<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class ConsumptionSnapshotInstallationTest extends TestCase
{
    public function test_additive_installation_preserves_all_legacy_columns(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(76, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasColumn('sale_details', 'consumption_snapshot'));
        $before = [];
        $fields = [];
        foreach (['sale_details', 'stock_ledger_entries'] as $t) {
            $fields[$t] = Schema::getColumnListing($t);
            $before[$t] = DB::table($t)->orderBy('id')->get()->toJson();
        }
        $path = 'database/migrations/2026_11_14_000003_add_consumption_composition_evidence.php';
        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        foreach ($fields as $t => $cols) {
            $this->assertSame($before[$t], DB::table($t)->select($cols)->orderBy('id')->get()->toJson());
        }
        $this->assertSame(0, DB::table('sale_details')->whereNotNull('consumption_snapshot')->count());
        $this->assertSame(0, DB::table('stock_ledger_entries')->whereNotNull('sale_detail_id')->count());
        $this->assertSame(77, DB::table('migrations')->count());
    }
}
