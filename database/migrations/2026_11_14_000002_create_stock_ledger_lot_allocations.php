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
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) {
            throw new RuntimeException('H03-B1 requiere DDL MySQL fuera de transacción.');
        }
        if (! Schema::hasColumn('lotes', 'inventory_representation_version') || Schema::hasTable('stock_ledger_lot_allocations')) {
            throw new RuntimeException('H03-B1: esquema previo inesperado.');
        }
        Schema::table('lotes', function (Blueprint $t) {
            $t->unique(['id', 'item_id'], 'lotes_id_item_allocation_unique');
        });
        Schema::table('stock_ledger_entries', function (Blueprint $t) {
            $t->unique(['id', 'item_id'], 'ledger_id_item_allocation_unique');
        });
        Schema::create('stock_ledger_lot_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('stock_ledger_entry_id');
            $t->unsignedBigInteger('lote_id');
            $t->foreignId('sale_detail_id')->nullable()->constrained('sale_details')->restrictOnDelete()->restrictOnUpdate();
            $t->decimal('physical_delta', 15, 2);
            $t->string('native_unit', 10);
            $t->decimal('container_capacity', 8, 2);
            $t->unsignedInteger('closed_before');
            $t->unsignedInteger('closed_after');
            $t->decimal('open_before', 8, 2);
            $t->decimal('open_after', 8, 2);
            $t->decimal('value_delta', 15, 2);
            $t->unsignedBigInteger('reverses_allocation_id')->nullable()->unique();
            $t->timestamps();
            $t->unique(['id', 'item_id', 'lote_id'], 'allocations_original_lot_unique');
            $t->foreign(['stock_ledger_entry_id', 'item_id'], 'allocation_ledger_item_fk')->references(['id', 'item_id'])->on('stock_ledger_entries')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['lote_id', 'item_id'], 'allocation_lot_item_fk')->references(['id', 'item_id'])->on('lotes')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['reverses_allocation_id', 'item_id', 'lote_id'], 'allocation_original_lot_fk')->references(['id', 'item_id', 'lote_id'])->on('stock_ledger_lot_allocations')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE stock_ledger_lot_allocations ADD CONSTRAINT allocation_physical_check CHECK (
            container_capacity > 0 AND native_unit IN ('ml','gr','oz')
            AND open_before BETWEEN 0 AND container_capacity AND open_after BETWEEN 0 AND container_capacity
            AND closed_before * container_capacity + open_before <= 99999999999.99
            AND closed_after * container_capacity + open_after <= 99999999999.99
            AND physical_delta <> 0 AND ABS(physical_delta) <= 99999999999.99
            AND physical_delta = (CAST(closed_after AS DECIMAL(15,0))-CAST(closed_before AS DECIMAL(15,0))) * container_capacity + open_after - open_before)");
    }

    public function down(): void
    {
        if (DB::transactionLevel() !== 0 || DB::table('stock_ledger_lot_allocations')->exists()) {
            throw new RuntimeException('H03-B1: hay asignaciones o transacción; no se elimina su historia.');
        }
        // Evitar una reversión parcial: comprobar también los datos de la migración previa.
        foreach (['items', 'lotes', 'stock_ledger_entries'] as $table) {
            if (DB::table($table)->whereNotNull('inventory_representation_version')->exists()) {
                throw new RuntimeException('H03-B1: representación en uso; no se elimina el esquema de asignaciones.');
            }
        }
        foreach (['volumen_abierto', 'volumen_entrada', 'capacidad_envase', 'unidad_nativa'] as $field) {
            if (DB::table('lotes')->whereNotNull($field)->exists()) {
                throw new RuntimeException('H03-B1: metadatos en uso; reversión rechazada.');
            }
        }
        Schema::drop('stock_ledger_lot_allocations');
        Schema::table('lotes', function (Blueprint $t) {
            $t->dropUnique('lotes_id_item_allocation_unique');
        });
        Schema::table('stock_ledger_entries', function (Blueprint $t) {
            $t->dropUnique('ledger_id_item_allocation_unique');
        });
    }
};
