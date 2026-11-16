<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public $withinTransaction = false;
    private array $tables = ['accounting_policy_versions','accounting_account_bindings','accounting_policy_audits'];

    private function prerequisites(): void
    {
        $c=DB::connection();
        if ($c->getDriverName()!=='mysql' || $c->getTablePrefix()!=='' || DB::transactionLevel()!==0) throw new RuntimeException('AC-03A requiere MySQL sin prefijo y DDL fuera de transacción.');
        $s=DB::selectOne('SELECT VERSION() AS version, @@SESSION.sql_mode AS modes');
        if (str_contains($s->version,'MariaDB') || version_compare($s->version,'8.0.16','<')
            || !preg_match('/(?:^|,)(STRICT_TRANS_TABLES|STRICT_ALL_TABLES)(?:,|$)/',$s->modes)) throw new RuntimeException('AC-03A requiere CHECK efectivos y modo estricto.');
    }

    public function up(): void
    {
        $this->prerequisites();
        foreach ($this->tables as $table) if (Schema::hasTable($table)) throw new RuntimeException('AC-03A: destino ocupado o instalación parcial.');
        foreach (['users','cuentas_contables','items','cuentas_bancarias'] as $table) {
            $row=DB::table('information_schema.TABLES')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())->where('TABLE_NAME',$table)->first();
            if (!$row || $row->ENGINE!=='InnoDB') throw new RuntimeException('AC-03A: padre ausente o motor incompatible.');
        }
        if (!Schema::hasTable('inventory_openings')) throw new RuntimeException('AC-03A requiere el esquema anterior completo.');
        Schema::create('accounting_policy_versions',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->id(); $t->unsignedInteger('version_number')->unique();
            $t->unsignedSmallInteger('contract_version');
            $t->string('kind',16)->collation('utf8mb4_bin');
            $t->string('status',16)->collation('utf8mb4_bin');
            $t->string('label',160); $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->string('approval_reference',160)->nullable();
            $t->dateTime('valid_from')->nullable(); $t->dateTime('valid_until')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->json('coverage_manifest');
            $t->char('content_hash',64)->charset('ascii')->collation('ascii_bin');
            $t->timestamps();
        });
        DB::statement("ALTER TABLE accounting_policy_versions ADD CONSTRAINT accounting_policy_state_check CHECK (
            version_number > 0 AND contract_version=1 AND kind IN ('demo','enterprise') AND status IN ('draft','published')
            AND CHAR_LENGTH(TRIM(label))>0 AND CHAR_LENGTH(TRIM(reason))>0
            AND REGEXP_LIKE(content_hash,'^[a-f0-9]{64}$','c')
            AND JSON_TYPE(coverage_manifest)='ARRAY'
            AND (valid_from IS NULL OR valid_until IS NULL OR valid_from<valid_until)
            AND ((status='draft' AND approved_by IS NULL AND approval_reference IS NULL AND published_at IS NULL)
            OR (status='published' AND kind='enterprise' AND approved_by IS NOT NULL AND published_at IS NOT NULL
                AND approval_reference IS NOT NULL AND CHAR_LENGTH(TRIM(approval_reference))>0
                AND valid_from IS NOT NULL AND valid_until IS NOT NULL AND valid_from<valid_until)))");
        Schema::create('accounting_account_bindings',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->id();
            $t->foreignId('policy_version_id')->constrained('accounting_policy_versions')->restrictOnDelete()->restrictOnUpdate();
            $t->string('semantic_role',80)->charset('ascii')->collation('ascii_bin');
            $t->string('scope_type',16)->collation('utf8mb4_bin');
            $t->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('bank_account_id')->nullable()->constrained('cuentas_bancarias')->restrictOnDelete()->restrictOnUpdate();
            $t->string('scope_key',80)->charset('ascii')->collation('ascii_bin')->storedAs("CASE scope_type WHEN 'global' THEN 'global' WHEN 'item' THEN CONCAT('item:',item_id) WHEN 'bank_account' THEN CONCAT('bank_account:',bank_account_id) ELSE NULL END");
            $t->foreignId('account_id')->constrained('cuentas_contables')->restrictOnDelete()->restrictOnUpdate();
            $t->json('rules_snapshot'); $t->json('account_snapshot'); $t->timestamps();
            $t->unique(['policy_version_id','semantic_role','scope_key'],'accounting_binding_slot_unique');
        });
        DB::statement("ALTER TABLE accounting_account_bindings ADD CONSTRAINT accounting_binding_scope_check CHECK (
            REGEXP_LIKE(semantic_role,'^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$','c') AND (
                (scope_type='global' AND item_id IS NULL AND bank_account_id IS NULL)
                OR (scope_type='item' AND item_id IS NOT NULL AND bank_account_id IS NULL)
                OR (scope_type='bank_account' AND item_id IS NULL AND bank_account_id IS NOT NULL)))");
        foreach (['rules_snapshot','account_snapshot'] as $field) {
            DB::statement("ALTER TABLE accounting_account_bindings ADD CONSTRAINT accounting_binding_{$field}_check CHECK (
                JSON_TYPE($field)='OBJECT' AND COALESCE(JSON_TYPE(JSON_EXTRACT($field,'$.version'))='INTEGER',0)
                AND COALESCE(JSON_EXTRACT($field,'$.version')=1,0))");
        }
        Schema::create('accounting_policy_audits',function(Blueprint $t) {
            $t->engine='InnoDB'; $t->id();
            $t->foreignId('policy_version_id')->constrained('accounting_policy_versions')->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedInteger('event_sequence'); $t->string('event',32)->collation('utf8mb4_bin');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->text('reason'); $t->char('before_hash',64)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->char('after_hash',64)->charset('ascii')->collation('ascii_bin');
            $t->json('evidence'); $t->dateTime('recorded_at');
            $t->unique(['policy_version_id','event_sequence'],'accounting_policy_audit_sequence_unique');
        });
        DB::statement("ALTER TABLE accounting_policy_audits ADD CONSTRAINT accounting_policy_audit_check CHECK (
            event_sequence>0 AND event IN ('created','draft_changed','bindings_changed','published') AND CHAR_LENGTH(TRIM(reason))>0
            AND (before_hash IS NULL OR REGEXP_LIKE(before_hash,'^[a-f0-9]{64}$','c'))
            AND REGEXP_LIKE(after_hash,'^[a-f0-9]{64}$','c') AND JSON_TYPE(evidence)='OBJECT'
            AND COALESCE(JSON_TYPE(JSON_EXTRACT(evidence,'$.version'))='INTEGER',0)
            AND COALESCE(JSON_EXTRACT(evidence,'$.version')=1,0))");
    }

    public function down(): void
    {
        $this->prerequisites();
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || DB::table($table)->exists()) throw new RuntimeException('AC-03A: estado parcial o evidencia en uso; rollback rechazado antes de DDL.');
        }
        foreach (array_reverse($this->tables) as $table) Schema::drop($table);
    }
};
