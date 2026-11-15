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
        if (DB::transactionLevel() !== 0 || DB::connection()->getDriverName() !== 'mysql'
            || ! Schema::hasColumn('sale_details', 'consumption_snapshot')
            || Schema::hasColumn('sales', 'annulment_evidence')) {
            throw new RuntimeException('H04-B: esquema previo inesperado; no se ejecutó DDL.');
        }
        Schema::table('sales', fn (Blueprint $t) => $t->json('annulment_evidence')->nullable());
    }

    public function down(): void
    {
        if (DB::transactionLevel() !== 0 || DB::table('sales')->whereNotNull('annulment_evidence')->exists()) {
            throw new RuntimeException('H04-B: evidencia en uso; no se elimina.');
        }
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('annulment_evidence'));
    }
};
