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
        if (DB::transactionLevel() !== 0 || DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('H03 requiere DDL MySQL fuera de transacción.');
        }
        if (! Schema::hasColumn('items', 'inventory_representation_version') || ! Schema::hasTable('stock_ledger_lot_allocations')
            || Schema::hasColumn('sale_details', 'consumption_snapshot') || Schema::hasColumn('stock_ledger_entries', 'sale_detail_id')) {
            throw new RuntimeException('H03-B2: esquema previo inesperado o instalación parcial; revisar antes de DDL.');
        }
        Schema::table('sale_details', fn (Blueprint $t) => $t->json('consumption_snapshot')->nullable());
        Schema::table('stock_ledger_entries', fn (Blueprint $t) => $t->foreignId('sale_detail_id')->nullable()->constrained('sale_details')->restrictOnDelete()->restrictOnUpdate());
    }

    public function down(): void
    {
        if (DB::transactionLevel() !== 0 || DB::table('sale_details')->whereNotNull('consumption_snapshot')->exists()
            || DB::table('stock_ledger_entries')->whereNotNull('sale_detail_id')->exists()) {
            throw new RuntimeException('Evidencia de consumo en uso; no se elimina.');
        }
        Schema::table('stock_ledger_entries', function (Blueprint $t) {
            $t->dropForeign(['sale_detail_id']);
            $t->dropColumn('sale_detail_id');
        });
        Schema::table('sale_details', fn (Blueprint $t) => $t->dropColumn('consumption_snapshot'));
    }
};
