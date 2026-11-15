<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private function prerequisites(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || DB::transactionLevel() !== 0
            || DB::connection()->getTablePrefix() !== '') {
            throw new RuntimeException('H01-B2A requiere MySQL sin prefijo y fuera de transacción.');
        }
        $server = DB::selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS modes');
        if (str_contains($server->version, 'MariaDB') || version_compare($server->version, '8.0.16', '<')
            || !preg_match('/(?:^|,)(?:STRICT_TRANS_TABLES|STRICT_ALL_TABLES)(?:,|$)/', $server->modes)) {
            throw new RuntimeException('H01-B2A requiere CHECK efectivos y modo SQL estricto.');
        }
    }

    public function up(): void
    {
        $this->prerequisites();
        if (Schema::hasTable('inventory_openings') || Schema::hasTable('inventory_opening_lines')) {
            throw new RuntimeException('H01-B2A: destino ocupado o instalación parcial; revisar antes de DDL.');
        }
        $parents = ['users','items','lotes','stock_ledger_entries','stock_ledger_lot_allocations','asientos_contables','cuentas_contables'];
        $engines = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', $parents)->get(['TABLE_NAME','ENGINE']);
        if ($engines->count() !== count($parents) || $engines->contains(fn ($r) => $r->ENGINE !== 'InnoDB')
            || !Schema::hasColumn('sales','annulment_evidence')) {
            throw new RuntimeException('H01-B2A: esquema H03/H04 previo incompleto o motor incompatible.');
        }
        foreach (['lotes'=>'lotes_id_item_allocation_unique','stock_ledger_entries'=>'ledger_id_item_allocation_unique'] as $table=>$index) {
            $keys = DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME',$table)->where('INDEX_NAME',$index)->where('NON_UNIQUE',0)->orderBy('SEQ_IN_INDEX')->pluck('COLUMN_NAME')->all();
            if ($keys !== ['id','item_id']) throw new RuntimeException('H01-B2A: falta la clave compuesta de resultados.');
        }

        Schema::create('inventory_openings', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->char('operation_uuid',36)->charset('ascii')->collation('ascii_bin')->unique('opening_uuid_unique');
            $t->char('content_hash',64)->charset('ascii')->collation('ascii_bin');
            $t->unsignedSmallInteger('contract_version');
            $t->string('status',16)->collation('utf8mb4_bin');
            $t->string('document_reference',160);
            $t->dateTime('effective_at')->nullable();
            $t->dateTime('counted_at')->nullable();
            $t->dateTime('recorded_at');
            $t->foreignId('executed_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('supervised_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->json('document_evidence')->nullable();
            $t->json('policy_snapshot')->nullable();
            $t->foreignId('counterpart_account_id')->nullable()->constrained('cuentas_contables')->restrictOnDelete()->restrictOnUpdate();
            $t->decimal('total_value_nio',15,2)->nullable();
            $t->foreignId('result_asiento_id')->nullable()->unique('opening_asiento_unique')->constrained('asientos_contables')->restrictOnDelete()->restrictOnUpdate();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE inventory_openings ADD CONSTRAINT opening_identity_check CHECK (
            contract_version = 1 AND status IN ('prepared','confirmed') AND CHAR_LENGTH(TRIM(document_reference)) > 0
            AND REGEXP_LIKE(operation_uuid, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')
            AND REGEXP_LIKE(content_hash, '^[0-9a-f]{64}$', 'c')
            AND (total_value_nio IS NULL OR total_value_nio >= 0))");
        foreach (['document_evidence','policy_snapshot'] as $field) {
            DB::statement("ALTER TABLE inventory_openings ADD CONSTRAINT opening_{$field}_check CHECK (
                $field IS NULL OR (JSON_TYPE($field) = 'OBJECT'
                AND COALESCE(JSON_TYPE(JSON_EXTRACT($field, '$.version')) = 'INTEGER',0)
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($field, '$.version')) = '1',0)))");
        }
        // Solo una barrera estructural. Aprobar políticas y confirmar hijos es responsabilidad
        // del futuro escritor transaccional; este esquema no lo implementa ni lo autoriza.
        DB::statement("ALTER TABLE inventory_openings ADD CONSTRAINT opening_confirmation_check CHECK (
            status <> 'confirmed' OR (document_evidence IS NOT NULL AND policy_snapshot IS NOT NULL
            AND effective_at IS NOT NULL AND counted_at IS NOT NULL AND total_value_nio IS NOT NULL))");

        Schema::create('inventory_opening_lines', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->id();
            $t->foreignId('opening_id')->constrained('inventory_openings')->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedInteger('position');
            $t->foreignId('item_id')->constrained('items')->restrictOnDelete()->restrictOnUpdate();
            $t->string('physical_kind',10)->collation('utf8mb4_bin');
            $t->string('item_type',24)->collation('utf8mb4_bin');
            $t->unsignedTinyInteger('inventory_representation_version')->nullable();
            $t->string('native_unit',10)->collation('utf8mb4_bin');
            $t->decimal('counted_units',12,4)->nullable();
            $t->decimal('counted_remainder',8,2)->nullable();
            $t->decimal('container_capacity',8,2)->nullable();
            $t->decimal('native_quantity',15,2);
            $t->string('manufacturer_lot',100)->nullable();
            $t->date('expires_on')->nullable();
            $t->json('physical_evidence')->nullable();
            $t->decimal('document_value_nio',15,2)->nullable();
            $t->decimal('informational_unit_cost',15,6)->nullable();
            $t->json('valuation_evidence')->nullable();
            $t->unsignedBigInteger('result_lot_id')->nullable()->unique('opening_line_lot_unique');
            $t->unsignedBigInteger('result_ledger_id')->nullable()->unique('opening_line_ledger_unique');
            $t->unique(['opening_id','position'],'opening_position_unique');
            $t->foreign(['result_lot_id','item_id'],'opening_line_lot_item_fk')->references(['id','item_id'])->on('lotes')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['result_ledger_id','item_id'],'opening_line_ledger_item_fk')->references(['id','item_id'])->on('stock_ledger_entries')->restrictOnDelete()->restrictOnUpdate();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE inventory_opening_lines ADD CONSTRAINT opening_line_physical_check CHECK (
            position > 0 AND item_type IN ('producto_venta','producto_interno') AND CHAR_LENGTH(TRIM(native_unit)) > 0
            AND native_quantity > 0 AND native_quantity <= 99999999999.99
            AND COALESCE((
                (physical_kind = 'units' AND inventory_representation_version IS NULL
                 AND counted_units > 0 AND counted_units = FLOOR(counted_units) AND counted_units <= 99999999
                 AND counted_remainder IS NULL AND container_capacity IS NULL AND native_quantity = counted_units)
                OR (physical_kind = 'closed' AND inventory_representation_version = 2 AND native_unit IN ('ml','gr','oz')
                 AND counted_units > 0 AND counted_units = FLOOR(counted_units) AND counted_units <= 99999999
                 AND counted_remainder IS NULL AND container_capacity > 0 AND native_quantity = counted_units * container_capacity)
                OR (physical_kind = 'open' AND inventory_representation_version = 2 AND native_unit IN ('ml','gr','oz')
                 AND counted_units IS NULL AND container_capacity > 0 AND counted_remainder > 0
                 AND counted_remainder <= container_capacity AND native_quantity = counted_remainder)
            ),0))");
        DB::statement('ALTER TABLE inventory_opening_lines ADD CONSTRAINT opening_line_value_check CHECK (
            (document_value_nio IS NULL OR document_value_nio >= 0)
            AND (informational_unit_cost IS NULL OR informational_unit_cost >= 0))');
        foreach (['physical_evidence','valuation_evidence'] as $field) {
            DB::statement("ALTER TABLE inventory_opening_lines ADD CONSTRAINT opening_line_{$field}_check CHECK (
                $field IS NULL OR (JSON_TYPE($field) = 'OBJECT'
                AND COALESCE(JSON_TYPE(JSON_EXTRACT($field, '$.version')) = 'INTEGER',0)
                AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT($field, '$.version')) = '1',0)))");
        }
    }

    public function down(): void
    {
        $this->prerequisites();
        if (!Schema::hasTable('inventory_openings') || !Schema::hasTable('inventory_opening_lines')) {
            throw new RuntimeException('H01-B2A: estado parcial; no se ejecuta una reversión automática.');
        }
        if (DB::table('inventory_openings')->exists() || DB::table('inventory_opening_lines')->exists()) {
            throw new RuntimeException('H01-B2A: documentos o evidencia en uso; reversión rechazada antes de DDL.');
        }
        Schema::drop('inventory_opening_lines');
        Schema::drop('inventory_openings');
    }
};
