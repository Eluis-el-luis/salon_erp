<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/** Ejecutar después de los tests RefreshDatabase. DDL solo en la base aislada. */
class AppointmentServicesMigrationTest extends TestCase
{
    private function target(): string
    {
        return DB::selectOne("SELECT REFERENCED_TABLE_NAME AS target FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND COLUMN_NAME='service_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->target;
    }

    private function refuses(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Se esperaba un rechazo preventivo de la migración.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
    }

    private function legacyFixture(bool $withService): void
    {
        $client = DB::table('clients')->insertGetId(['name' => 'Fixture migración H13']);
        DB::table('items')->insert(['id' => 930001, 'producto' => 'Producto heredado H13', 'type' => 'producto_venta']);
        if ($withService) {
            DB::table('services')->insert(['id' => 930001, 'name' => 'Servicio diferente H13', 'price' => 100]);
        }
        DB::table('appointments')->insert(['client_id' => $client, 'service_id' => 930001, 'appointment_date' => '2026-11-20 10:00:00']);
    }

    public function test_controlled_up_down_rejects_ambiguous_rows_and_active_transactions(): void
    {
        // Este test no usa RefreshDatabase ni transacción exterior: MySQL DDL hace commit.
        TestDatabaseSafety::assertResetAuthorized(SALON_TEST_DATABASE_RESET_ALLOWED);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, DB::table('appointments')->count());
        $this->assertSame('services', $this->target());
        $migration = require database_path('migrations/2026_11_13_000001_point_appointments_to_services.php');
        DB::beginTransaction();
        try {
            $this->refuses(fn () => $migration->down(), 'transacción activa');
            $this->assertSame('services', $this->target());
        } finally {
            DB::rollBack();
        }

        try {
            $migration->down();
            $this->assertSame('items', $this->target());
            foreach ([false, true] as $withService) {
                DB::beginTransaction();
                try {
                    $this->legacyFixture($withService);
                    $before = DB::table('appointments')->first();
                    $this->refuses(fn () => $migration->up(), 'conciliación histórica');
                    $this->assertSame('items', $this->target());
                    $this->assertEquals($before, DB::table('appointments')->first());
                } finally {
                    DB::rollBack();
                }
            }
            $migration->up();
            $this->assertSame('services', $this->target());
            DB::beginTransaction();
            try {
                $this->legacyFixture(true);
                $this->refuses(fn () => $migration->down(), 'conciliación histórica');
                $this->assertSame('services', $this->target());
                $this->assertSame(1, DB::table('appointments')->count());
            } finally {
                DB::rollBack();
            }
        } finally {
            // Solo restaurar la dirección conocida en una tabla comprobada vacía.
            if ($this->target() === 'items' && ! DB::table('appointments')->exists()) {
                $migration->up();
            }
        }
        $this->assertSame('services', $this->target());
        $this->assertSame(0, DB::table('appointments')->count());
    }
}
