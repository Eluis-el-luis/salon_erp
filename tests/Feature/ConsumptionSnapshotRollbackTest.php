<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/** Ensayo DDL acotado: exclusivamente evidencia vacía y sin escritores rivales. */
class ConsumptionSnapshotRollbackTest extends TestCase
{
    public function test_empty_snapshot_addition_can_be_reversed_and_reinstalled(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(77, DB::table('migrations')->count());
        foreach (['sale_details', 'stock_ledger_entries', 'stock_ledger_lot_allocations'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'El ensayo requiere tablas de evidencia vacías; no borrar fixtures desconocidos.');
        }
        $path = 'database/migrations/2026_11_14_000003_add_consumption_composition_evidence.php';
        $this->assertSame(pathinfo($path, PATHINFO_FILENAME), DB::table('migrations')->orderByDesc('id')->value('migration'));
        $before = [];
        foreach (['items', 'lotes', 'sales', 'sale_details', 'stock_ledger_entries', 'stock_ledger_lot_allocations', 'asientos_contables', 'detalle_asientos'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->artisan('migrate:rollback', ['--path' => $path, '--step' => 1, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('sale_details', 'consumption_snapshot'));
        $this->assertFalse(Schema::hasColumn('stock_ledger_entries', 'sale_detail_id'));
        $this->assertTrue(Schema::hasTable('stock_ledger_lot_allocations'));
        $this->assertSame(76, DB::table('migrations')->count());
        $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
        $this->assertSame(77, DB::table('migrations')->count());
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }
}
