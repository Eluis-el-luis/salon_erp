<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertEngine();
        foreach (['items', 'lotes', 'stock_ledger_entries'] as $table) {
            if (Schema::hasColumn($table, 'inventory_representation_version')) {
                throw new RuntimeException('H03-B1: columna ya existente; revisar instalación parcial.');
            }
        }
        foreach (['volumen_abierto', 'volumen_entrada', 'capacidad_envase', 'unidad_nativa', 'open_item_guard'] as $field) {
            if (Schema::hasColumn('lotes', $field)) {
                throw new RuntimeException('H03-B1: metadatos ya existentes; revisar antes del DDL.');
            }
        }
        Schema::table('items', function (Blueprint $t) {
            $t->unsignedTinyInteger('inventory_representation_version')->nullable();
        });
        Schema::table('stock_ledger_entries', function (Blueprint $t) {
            $t->unsignedTinyInteger('inventory_representation_version')->nullable();
        });
        Schema::table('lotes', function (Blueprint $t) {
            $t->unsignedTinyInteger('inventory_representation_version')->nullable();
            $t->decimal('volumen_abierto', 8, 2)->nullable();
            $t->decimal('volumen_entrada', 15, 2)->nullable();
            $t->decimal('capacidad_envase', 8, 2)->nullable();
            $t->string('unidad_nativa', 10)->nullable();
            $t->unsignedBigInteger('open_item_guard')->nullable()->storedAs('CASE WHEN inventory_representation_version = 2 AND volumen_abierto > 0 THEN item_id ELSE NULL END');
            $t->unique('open_item_guard', 'lotes_one_open_item_unique');
        });
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_representation_check CHECK (inventory_representation_version IS NULL OR (inventory_representation_version = 2 AND is_fractionable = 1 AND existencia_actual >= 0 AND total_volume > 0 AND current_volume BETWEEN 0 AND total_volume AND existencia_actual * total_volume + current_volume <= 99999999999.99))');
        DB::statement('ALTER TABLE stock_ledger_entries ADD CONSTRAINT ledger_representation_check CHECK (inventory_representation_version IS NULL OR (inventory_representation_version = 2 AND volumen IS NOT NULL AND saldo_volumen IS NOT NULL AND saldo_volumen >= 0 AND saldo_cantidad >= 0))');
        DB::statement("ALTER TABLE lotes ADD CONSTRAINT lotes_representation_check CHECK (
            (inventory_representation_version IS NULL AND volumen_abierto IS NULL AND volumen_entrada IS NULL AND capacidad_envase IS NULL AND unidad_nativa IS NULL)
            OR (inventory_representation_version IS NOT NULL AND inventory_representation_version = 2
                AND volumen_abierto IS NOT NULL AND volumen_entrada IS NOT NULL AND capacidad_envase IS NOT NULL AND unidad_nativa IS NOT NULL
                AND capacidad_envase > 0 AND unidad_nativa IN ('ml','gr','oz')
                AND cantidad_entrada >= 0 AND cantidad_entrada = FLOOR(cantidad_entrada)
                AND cantidad_disponible >= 0 AND cantidad_disponible = FLOOR(cantidad_disponible)
                AND volumen_abierto BETWEEN 0 AND capacidad_envase
                AND volumen_entrada BETWEEN 0 AND 99999999999.99
                AND cantidad_entrada * capacidad_envase <= volumen_entrada
                AND cantidad_disponible * capacidad_envase + volumen_abierto <= volumen_entrada))");
    }

    public function down(): void
    {
        $this->assertEngine();
        foreach (['items', 'stock_ledger_entries', 'lotes'] as $table) {
            if (DB::table($table)->whereNotNull('inventory_representation_version')->exists()) {
                throw new RuntimeException('H03-B1: hay datos versionados; reversión destructiva rechazada.');
            }
        }
        foreach (['volumen_abierto', 'volumen_entrada', 'capacidad_envase', 'unidad_nativa'] as $field) {
            if (DB::table('lotes')->whereNotNull($field)->exists()) {
                throw new RuntimeException('H03-B1: metadatos existentes; no se eliminan.');
            }
        }
        DB::statement('ALTER TABLE lotes DROP CHECK lotes_representation_check');
        DB::statement('ALTER TABLE items DROP CHECK items_representation_check');
        DB::statement('ALTER TABLE stock_ledger_entries DROP CHECK ledger_representation_check');
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropUnique('lotes_one_open_item_unique');
            $t->dropColumn(['open_item_guard', 'inventory_representation_version', 'volumen_abierto', 'volumen_entrada', 'capacidad_envase', 'unidad_nativa']);
        });
        foreach (['items', 'stock_ledger_entries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('inventory_representation_version');
            });
        }
    }

    private function assertEngine(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getTablePrefix() !== '' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('H03-B1 requiere MySQL sin prefijo fuera de una transacción.');
        }
        $version = DB::selectOne('SELECT VERSION() AS v')->v;
        if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.16', '<')) {
            throw new RuntimeException('H03-B1 requiere MySQL >= 8.0.16 con CHECK efectivo.');
        }
        $tables = DB::select("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('items','lotes','stock_ledger_entries','sale_details')");
        if (count($tables) !== 4 || collect($tables)->contains(fn ($t) => $t->ENGINE !== 'InnoDB')) {
            throw new RuntimeException('H03-B1 requiere las cuatro tablas InnoDB.');
        }
    }
};
