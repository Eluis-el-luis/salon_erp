<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public $withinTransaction=false;
    private array $tables=['accounting_policy_authorizations','accounting_event_operations','accounting_entry_line_resolutions'];
    private function preflight(): void {
        if(DB::connection()->getDriverName()!=='mysql'||DB::connection()->getTablePrefix()!==''||DB::transactionLevel()!==0)throw new RuntimeException('DDL requiere MySQL sin transacción/prefijo.');
        $v=DB::selectOne('SELECT VERSION() AS v,@@SESSION.sql_mode AS modes');
        if(str_contains($v->v,'MariaDB')||version_compare($v->v,'8.0.16','<')||!str_contains($v->modes,'STRICT_'))throw new RuntimeException('CHECK y modo estricto requeridos.');
    }
    private function hash(Blueprint $t,string $name): void {$t->char($name,64)->charset('ascii')->collation('ascii_bin');}
    private function scope(Blueprint $t): void {
        $t->unsignedBigInteger('policy_version_id');$this->hash($t,'policy_hash');
        $t->string('purpose',16)->charset('ascii')->collation('ascii_bin');
        $t->string('event_key',64)->charset('ascii')->collation('ascii_bin');
        $t->foreignId('bank_account_id')->constrained('cuentas_bancarias')->restrictOnDelete()->restrictOnUpdate();
        $t->char('currency',3)->charset('ascii')->collation('ascii_bin');
        $t->string('environment',16)->charset('ascii')->collation('ascii_bin');
    }
    public function up(): void {
        $this->preflight();foreach($this->tables as $t)if(Schema::hasTable($t))throw new RuntimeException('Destino ocupado/parcial.');
        if(Schema::hasColumn('asientos_contables','accounting_policy_version_id'))throw new RuntimeException('Cabecera ya ampliada.');
        foreach(['accounting_policy_versions','accounting_account_bindings','asientos_contables','detalle_asientos','movimientos_bancarios'] as $table) {
            $row=DB::table('information_schema.TABLES')->where('TABLE_SCHEMA',DB::connection()->getDatabaseName())->where('TABLE_NAME',$table)->first();
            if(!$row||$row->ENGINE!=='InnoDB')throw new RuntimeException('Padres InnoDB requeridos.');
        }
        Schema::table('accounting_policy_versions',fn(Blueprint $t)=>$t->unique(['id','content_hash'],'policy_id_hash_uq'));
        Schema::table('accounting_account_bindings',fn(Blueprint $t)=>$t->unique(['id','policy_version_id','account_id','semantic_role','scope_key','bank_account_id'],'binding_identity_uq'));
        Schema::table('detalle_asientos',fn(Blueprint $t)=>$t->unique(['id','asiento_id','cuenta_id'],'detail_identity_uq'));
        Schema::table('movimientos_bancarios',fn(Blueprint $t)=>$t->unique(['id','asiento_contable_id','cuenta_bancaria_id'],'bank_move_identity_uq'));
        Schema::create('accounting_policy_authorizations',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$this->scope($t);$t->string('action',16)->charset('ascii')->collation('ascii_bin');
            $t->unsignedBigInteger('parent_id')->nullable();$t->string('parent_action',16)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->string('document_reference',160);$t->text('reason');$t->dateTime('valid_from');$t->dateTime('valid_until');
            $t->dateTime('recorded_at');$t->json('evidence');$this->hash($t,'evidence_hash');
            $t->foreign(['policy_version_id','policy_hash'],'auth_policy_fk')->references(['id','content_hash'])->on('accounting_policy_versions')->restrictOnDelete()->restrictOnUpdate();
            $t->unique(['id','action','policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment'],'auth_context_uq');
            $t->unique(['id','action','policy_version_id','policy_hash'],'auth_entry_uq');
            $t->unique(['parent_id','action'],'auth_parent_action_uq');
        });
        Schema::table('accounting_policy_authorizations',function(Blueprint $t){
            $t->foreign(['parent_id','parent_action','policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment'],'auth_parent_fk')
                ->references(['id','action','policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment'])->on('accounting_policy_authorizations')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE accounting_policy_authorizations ADD CONSTRAINT auth_contract_ck CHECK (
            purpose IN ('simulation','business') AND event_key='bancos.cargo_gasto.nio' AND currency='NIO'
            AND environment IN ('testing','local','production') AND (purpose<>'simulation' OR environment='testing')
            AND valid_from<valid_until AND CHAR_LENGTH(TRIM(document_reference))>0 AND CHAR_LENGTH(TRIM(reason))>0
            AND REGEXP_LIKE(policy_hash,'^[a-f0-9]{64}$','c') AND REGEXP_LIKE(evidence_hash,'^[a-f0-9]{64}$','c')
            AND JSON_TYPE(evidence)='OBJECT' AND COALESCE(JSON_EXTRACT(evidence,'$.version')=1,0)
            AND ((action='approval' AND parent_id IS NULL AND parent_action IS NULL)
              OR (action='enablement' AND parent_id IS NOT NULL AND parent_action='approval')
              OR (action='revocation' AND parent_id IS NOT NULL AND parent_action IN ('approval','enablement'))))");
        Schema::table('asientos_contables',function(Blueprint $t){
            $t->unsignedBigInteger('accounting_policy_version_id')->nullable();
            $t->char('accounting_policy_hash',64)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedBigInteger('accounting_authorization_id')->nullable();
            $t->string('accounting_authorization_action',16)->charset('ascii')->collation('ascii_bin')->storedAs("CASE WHEN accounting_authorization_id IS NULL THEN NULL ELSE 'enablement' END");
            $t->foreign(['accounting_policy_version_id','accounting_policy_hash'],'entry_policy_fk')->references(['id','content_hash'])->on('accounting_policy_versions')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['accounting_authorization_id','accounting_authorization_action','accounting_policy_version_id','accounting_policy_hash'],'entry_auth_fk')
                ->references(['id','action','policy_version_id','policy_hash'])->on('accounting_policy_authorizations')->restrictOnDelete()->restrictOnUpdate();
            $t->unique(['id','accounting_policy_version_id','accounting_policy_hash','accounting_authorization_id'],'entry_policy_identity_uq');
        });
        DB::statement("ALTER TABLE asientos_contables ADD CONSTRAINT entry_policy_ck CHECK (
            (accounting_policy_version_id IS NULL AND accounting_policy_hash IS NULL AND accounting_authorization_id IS NULL)
            OR (accounting_policy_version_id IS NOT NULL AND accounting_policy_hash IS NOT NULL AND accounting_authorization_id IS NOT NULL
                AND REGEXP_LIKE(accounting_policy_hash,'^[a-f0-9]{64}$','c')))");
        Schema::create('accounting_event_operations',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->char('operation_uuid',36)->charset('ascii')->collation('ascii_bin')->unique('event_uuid_uq');
            $this->hash($t,'content_hash');$t->json('content');$this->scope($t);
            $t->unsignedBigInteger('authorization_id');$t->string('authorization_action',16)->charset('ascii')->collation('ascii_bin')->storedAs("'enablement'");
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->string('classification',16)->charset('ascii')->collation('ascii_bin');$t->decimal('amount',15,2);$t->date('recognition_date');
            $t->string('status',16)->charset('ascii')->collation('ascii_bin');$t->dateTime('recorded_at');
            $t->unsignedBigInteger('result_movement_id')->nullable();$t->unsignedBigInteger('result_asiento_id')->nullable();
            $t->foreign(['authorization_id','authorization_action','policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment'],'operation_auth_fk')
                ->references(['id','action','policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment'])->on('accounting_policy_authorizations')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['result_movement_id','result_asiento_id','bank_account_id'],'operation_move_fk')->references(['id','asiento_contable_id','cuenta_bancaria_id'])->on('movimientos_bancarios')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['result_asiento_id','policy_version_id','policy_hash','authorization_id'],'operation_entry_fk')->references(['id','accounting_policy_version_id','accounting_policy_hash','accounting_authorization_id'])->on('asientos_contables')->restrictOnDelete()->restrictOnUpdate();
            $t->unique(['id','policy_version_id','policy_hash','authorization_id','bank_account_id','result_movement_id','result_asiento_id'],'operation_result_uq');
        });
        DB::statement("ALTER TABLE accounting_event_operations ADD CONSTRAINT operation_contract_ck CHECK (
            REGEXP_LIKE(operation_uuid,'^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$','c')
            AND REGEXP_LIKE(content_hash,'^[a-f0-9]{64}$','c') AND JSON_TYPE(content)='OBJECT' AND COALESCE(JSON_EXTRACT(content,'$.version')=1,0)
            AND purpose IN ('simulation','business') AND currency='NIO' AND event_key='bancos.cargo_gasto.nio'
            AND classification IN ('maintenance','commission') AND amount>0
            AND ((status='prepared' AND result_movement_id IS NULL AND result_asiento_id IS NULL)
              OR (status='confirmed' AND result_movement_id IS NOT NULL AND result_asiento_id IS NOT NULL)))");
        Schema::create('accounting_entry_line_resolutions',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->unsignedBigInteger('operation_id');$t->unsignedBigInteger('policy_version_id');$this->hash($t,'policy_hash');
            $t->unsignedBigInteger('authorization_id');$t->unsignedBigInteger('bank_account_id');$t->unsignedBigInteger('movement_id');$t->unsignedBigInteger('asiento_id');
            $t->unsignedBigInteger('detail_id')->unique('resolution_detail_uq');$t->unsignedBigInteger('binding_id');$t->unsignedBigInteger('account_id');
            $t->string('semantic_role',80)->charset('ascii')->collation('ascii_bin');
            $t->string('scope_key',80)->charset('ascii')->collation('ascii_bin')->storedAs("CONCAT('bank_account:',bank_account_id)");
            $t->unsignedSmallInteger('contract_version');$t->dateTime('recorded_at');
            $t->foreign(['operation_id','policy_version_id','policy_hash','authorization_id','bank_account_id','movement_id','asiento_id'],'resolution_operation_fk')
                ->references(['id','policy_version_id','policy_hash','authorization_id','bank_account_id','result_movement_id','result_asiento_id'])->on('accounting_event_operations')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['detail_id','asiento_id','account_id'],'resolution_detail_fk')->references(['id','asiento_id','cuenta_id'])->on('detalle_asientos')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['binding_id','policy_version_id','account_id','semantic_role','scope_key','bank_account_id'],'resolution_binding_fk')
                ->references(['id','policy_version_id','account_id','semantic_role','scope_key','bank_account_id'])->on('accounting_account_bindings')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE accounting_entry_line_resolutions ADD CONSTRAINT resolution_contract_ck CHECK (contract_version=1 AND semantic_role IN ('gasto.cargo_bancario','fondos.banco'))");
    }
    public function down(): void {
        $this->preflight();foreach($this->tables as $t)if(!Schema::hasTable($t)||DB::table($t)->exists())throw new RuntimeException('Evidencia o estado parcial: no revertir.');
        if(DB::table('asientos_contables')->whereNotNull('accounting_policy_version_id')->orWhereNotNull('accounting_policy_hash')->orWhereNotNull('accounting_authorization_id')->exists())throw new RuntimeException('Cabecera en uso: no revertir.');
        Schema::drop('accounting_entry_line_resolutions');Schema::drop('accounting_event_operations');
        DB::statement('ALTER TABLE asientos_contables DROP CHECK entry_policy_ck');
        Schema::table('asientos_contables',function(Blueprint $t){$t->dropForeign('entry_auth_fk');$t->dropForeign('entry_policy_fk');$t->dropUnique('entry_policy_identity_uq');$t->dropColumn(['accounting_authorization_action','accounting_authorization_id','accounting_policy_hash','accounting_policy_version_id']);});
        Schema::drop('accounting_policy_authorizations');
        Schema::table('movimientos_bancarios',fn(Blueprint $t)=>$t->dropUnique('bank_move_identity_uq'));
        Schema::table('detalle_asientos',fn(Blueprint $t)=>$t->dropUnique('detail_identity_uq'));
        Schema::table('accounting_account_bindings',fn(Blueprint $t)=>$t->dropUnique('binding_identity_uq'));
        Schema::table('accounting_policy_versions',fn(Blueprint $t)=>$t->dropUnique('policy_id_hash_uq'));
    }
};
