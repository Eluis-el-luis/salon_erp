<?php

namespace Tests\Feature;

use App\Models\Articulo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class FractionalSchemaInstallationTest extends TestCase
{
    public const PATHS = [
        'database/migrations/2026_11_14_000001_add_fractional_inventory_representation.php',
        'database/migrations/2026_11_14_000002_create_stock_ledger_lot_allocations.php',
    ];

    public function test_install_empty_additions_rollback_and_reinstall_preserve_legacy(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(Schema::hasColumn('items', 'inventory_representation_version'), 'No reutilizar una instalación parcial/desconocida.');
        $before = [];
        $columns = [];
        foreach (['items', 'lotes', 'stock_ledger_entries'] as $table) {
            $columns[$table] = Schema::getColumnListing($table);
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $count = DB::table('migrations')->count();
        $this->artisan('migrate', ['--path' => self::PATHS, '--force' => true])->assertExitCode(0);
        $this->assertSame($count + 2, DB::table('migrations')->count());
        foreach ($columns as $table => $fields) {
            $this->assertSame($before[$table], DB::table($table)->select($fields)->orderBy('id')->get()->toJson());
            $this->assertSame(0, DB::table($table)->whereNotNull('inventory_representation_version')->count());
        }
        $latest = DB::table('migrations')->orderByDesc('id')->limit(2)->get();
        $expected = array_map(fn ($p) => pathinfo($p, PATHINFO_FILENAME), array_reverse(self::PATHS));
        $this->assertSame($expected, $latest->pluck('migration')->all());
        $this->assertSame(1, $latest->pluck('batch')->unique()->count());
        $this->artisan('migrate:rollback', ['--path' => self::PATHS, '--step' => 2, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('stock_ledger_lot_allocations'));
        $this->assertSame($count, DB::table('migrations')->count());
        foreach ($columns as $table => $fields) {
            $this->assertSame($fields, Schema::getColumnListing($table));
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->artisan('migrate', ['--path' => self::PATHS, '--force' => true])->assertExitCode(0);
        $this->assertSame($count + 2, DB::table('migrations')->count());
    }

    public function test_versioned_metadata_blocks_both_down_methods_before_any_ddl(): void
    {
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0, DB::transactionLevel());
        $item = Articulo::create(['producto' => 'H03 rollback guard fixture', 'type' => 'producto_interno', 'precio_c' => 0,
            'is_fractionable' => true, 'total_volume' => 1000, 'current_volume' => 0, 'existencia_actual' => 0]);
        try {
            DB::table('items')->where('id', $item->id)->update(['inventory_representation_version' => 2]);
            foreach (array_reverse(self::PATHS) as $path) {
                $migration = require base_path($path);
                try {
                    $migration->down();
                    $this->fail('Versioned data must prevent destructive rollback');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('H03-B1', $e->getMessage());
                }
            }
            $this->assertTrue(Schema::hasTable('stock_ledger_lot_allocations'));
            $this->assertTrue(Schema::hasColumn('items', 'inventory_representation_version'));
        } finally {
            $item->forceDelete();
        } // Solo este catálogo vacío, sin documentos ni historia.
    }
}
