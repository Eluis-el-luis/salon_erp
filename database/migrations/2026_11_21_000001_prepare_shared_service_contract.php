<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** H06-P1: preparado para revisión. No instalado por esta fase; sin DML/backfill. */
return new class extends Migration
{
    public $withinTransaction = false;
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0) throw new RuntimeException('H06 requiere DDL MySQL fuera de transacción.');
        foreach (['sales', 'sale_details', 'appointments', 'users', 'comisiones_generadas', 'stock_ledger_entries'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('Perfil H06 incompleto: '.$table);
        }
        if (!Schema::hasColumn('sale_details', 'consumption_snapshot') || !Schema::hasColumn('stock_ledger_entries', 'sale_detail_id')
            || Schema::hasTable('service_participations')) throw new RuntimeException('Se requiere H03-B2 y ausencia de instalación H06 previa/parcial.');
        foreach (['sales' => ['service_contract_version','creation_key','creation_request_hash','document_revision'],
            'sale_details' => ['performance_key','appointment_id','commercial_identity_snapshot'],
            'comisiones_generadas' => ['service_participation_id']] as $table => $columns) {
            foreach ($columns as $column) if (Schema::hasColumn($table, $column)) throw new RuntimeException('H06 parcial: revisar antes de DDL.');
        }
        Schema::table('sales', function (Blueprint $t) {
            $t->unsignedTinyInteger('service_contract_version')->nullable();
            $t->char('creation_key', 36)->charset('ascii')->collation('ascii_bin')->nullable()->unique('h06_sale_creation');
            $t->char('creation_request_hash', 64)->charset('ascii')->nullable();
            $t->unsignedBigInteger('document_revision')->nullable();
        });
        Schema::table('sale_details', function (Blueprint $t) {
            $t->char('performance_key', 36)->charset('ascii')->collation('ascii_bin')->nullable()->unique('h06_performance');
            $t->foreignId('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete()->restrictOnUpdate();
            $t->json('commercial_identity_snapshot')->nullable();
            $t->unique(['id','sale_id'], 'h06_detail_sale');
        });
        Schema::create('service_participations', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id(); $t->unsignedBigInteger('sale_detail_id'); $t->unsignedBigInteger('sale_id');
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->json('commission_resolution_snapshot')->nullable(); $t->timestamps();
            $t->unique(['sale_detail_id','user_id'], 'h06_participant_once');
            $t->unique(['id','sale_id','user_id'], 'h06_participant_origin');
            $t->index('sale_id', 'h06_participant_sale');
            $t->foreign(['sale_detail_id','sale_id'], 'h06_participant_detail')->references(['id','sale_id'])->on('sale_details')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::table('comisiones_generadas', function (Blueprint $t) {
            $t->unsignedBigInteger('service_participation_id')->nullable()->unique('h06_commission_once');
            $t->foreign(['service_participation_id','venta_id','empleado_id'], 'h06_commission_origin')
                ->references(['id','sale_id','user_id'])->on('service_participations')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE sales ADD CONSTRAINT h06_sale_contract CHECK (
            (service_contract_version IS NULL AND creation_key IS NULL AND creation_request_hash IS NULL AND document_revision IS NULL)
            OR (service_contract_version IS NOT NULL AND service_contract_version = 1 AND creation_key IS NOT NULL AND creation_request_hash IS NOT NULL AND document_revision IS NOT NULL AND document_revision > 0
                AND creation_key REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' AND creation_request_hash REGEXP '^[0-9a-f]{64}$'))");
        DB::statement("ALTER TABLE sale_details ADD CONSTRAINT h06_detail_contract CHECK (
            (performance_key IS NULL AND appointment_id IS NULL AND commercial_identity_snapshot IS NULL)
            OR (performance_key IS NOT NULL AND service_id IS NOT NULL AND item_id IS NULL AND stylist_id IS NULL
                AND performance_key REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
                AND quantity > 0 AND unit_price >= 0 AND commercial_identity_snapshot IS NOT NULL
                AND JSON_CONTAINS_PATH(commercial_identity_snapshot, 'all', '$.version', '$.service_id') = 1
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(commercial_identity_snapshot, '$.service_id')), '') = CAST(service_id AS CHAR)
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(commercial_identity_snapshot, '$.version')), '') = '1'))");
    }
    public function down(): void
    {
        if (DB::transactionLevel() !== 0 || !Schema::hasTable('service_participations')
            || DB::table('service_participations')->exists()
            || DB::table('sales')->whereNotNull('service_contract_version')->orWhereNotNull('creation_key')->orWhereNotNull('creation_request_hash')->orWhereNotNull('document_revision')->exists()
            || DB::table('sale_details')->whereNotNull('performance_key')->orWhereNotNull('appointment_id')->orWhereNotNull('commercial_identity_snapshot')->exists()
            || DB::table('comisiones_generadas')->whereNotNull('service_participation_id')->exists()) throw new RuntimeException('H06 en uso o parcial: no se elimina evidencia.');
        Schema::table('comisiones_generadas', function (Blueprint $t) { $t->dropForeign('h06_commission_origin'); $t->dropUnique('h06_commission_once'); $t->dropColumn('service_participation_id'); });
        Schema::drop('service_participations');
        DB::statement('ALTER TABLE sale_details DROP CHECK h06_detail_contract');
        Schema::table('sale_details', function (Blueprint $t) { $t->dropForeign(['appointment_id']); $t->dropUnique('h06_performance'); $t->dropUnique('h06_detail_sale'); $t->dropColumn(['performance_key','appointment_id','commercial_identity_snapshot']); });
        DB::statement('ALTER TABLE sales DROP CHECK h06_sale_contract');
        Schema::table('sales', function (Blueprint $t) { $t->dropUnique('h06_sale_creation'); $t->dropColumn(['service_contract_version','creation_key','creation_request_hash','document_revision']); });
    }
};
