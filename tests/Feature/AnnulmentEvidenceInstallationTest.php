<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class AnnulmentEvidenceInstallationTest extends TestCase
{
    public function test_additive_installation_and_unused_column_reversal(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(77, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasColumn('sales','annulment_evidence'));
        $cols = Schema::getColumnListing('sales');
        $before = DB::table('sales')->select($cols)->orderBy('id')->get()->toJson();
        $path = 'database/migrations/2026_11_14_000004_add_sale_annulment_evidence.php';
        $this->artisan('migrate',['--path'=>$path,'--force'=>true])->assertExitCode(0);
        $this->assertSame(78,DB::table('migrations')->count());
        $this->assertSame(0,DB::table('sales')->whereNotNull('annulment_evidence')->count());
        $this->assertSame($before,DB::table('sales')->select($cols)->orderBy('id')->get()->toJson());
        $migration = require base_path($path);
        $migration->down(); // Solo una columna nueva todavía sin evidencia.
        $this->assertFalse(Schema::hasColumn('sales','annulment_evidence'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('sales','annulment_evidence'));
        $this->assertSame($before,DB::table('sales')->select($cols)->orderBy('id')->get()->toJson());
    }
}
