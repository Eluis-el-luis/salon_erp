<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public $withinTransaction=false;
    private function guard(): void {
        $i=DB::selectOne('SELECT DATABASE() AS d,CURRENT_USER() AS u,VERSION() AS v');
        if(DB::connection()->getDriverName()!=='mysql'||DB::transactionLevel()!==0||$i->d!=='salon_erp_test'||$i->u!=='salon_erp_tester@localhost'||$i->v!=='8.4.3'||!app()->environment('testing'))throw new RuntimeException('DDL de ensayo fuera del destino autorizado.');
    }
    private function hash(Blueprint $t,string $name): void {$t->char($name,64)->charset('ascii')->collation('ascii_bin');}
    public function up(): void {
        $this->guard();
        if(Schema::hasTable('bank_charge_reversals')||Schema::hasTable('bank_charge_reversal_lines'))throw new RuntimeException('Destino ocupado/parcial.');
        Schema::create('bank_charge_reversals',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->char('operation_uuid',36)->charset('ascii')->collation('ascii_bin')->unique('refund_uuid_uq');$this->hash($t,'content_hash');$t->json('content');
            $t->unsignedBigInteger('original_operation_id')->unique('refund_original_uq');$t->unsignedBigInteger('original_movement_id')->unique('refund_original_move_uq');$t->unsignedBigInteger('original_asiento_id')->unique('refund_original_entry_uq');
            $t->unsignedBigInteger('policy_version_id');$this->hash($t,'policy_hash');$t->unsignedBigInteger('authorization_id');
            $t->foreignId('bank_account_id')->constrained('cuentas_bancarias')->restrictOnDelete()->restrictOnUpdate();
            $t->string('purpose',16);$t->char('currency',3);$t->string('reason_code',32);$t->decimal('amount',15,2);$t->date('effective_date');$t->date('recognition_date');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();$t->dateTime('recorded_at');$t->json('evidence');$this->hash($t,'evidence_hash');$t->string('status',16);
            $t->unsignedBigInteger('result_movement_id')->nullable()->unique('refund_result_move_uq');$t->unsignedBigInteger('result_asiento_id')->nullable()->unique('refund_result_entry_uq');
            $t->foreign(['original_operation_id','policy_version_id','policy_hash','authorization_id','bank_account_id','original_movement_id','original_asiento_id'],'refund_origin_fk')
                ->references(['id','policy_version_id','policy_hash','authorization_id','bank_account_id','result_movement_id','result_asiento_id'])->on('accounting_event_operations')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['result_movement_id','result_asiento_id','bank_account_id'],'refund_result_fk')->references(['id','asiento_contable_id','cuenta_bancaria_id'])->on('movimientos_bancarios')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign('result_asiento_id','refund_entry_fk')->references('id')->on('asientos_contables')->restrictOnDelete()->restrictOnUpdate();
            $t->unique(['id','original_asiento_id','result_asiento_id'],'refund_line_parent_uq');
        });
        DB::statement("ALTER TABLE bank_charge_reversals ADD CONSTRAINT refund_contract_ck CHECK (
            purpose='simulation' AND currency='NIO' AND reason_code='credited_full_refund' AND amount>0 AND effective_date=recognition_date
            AND REGEXP_LIKE(operation_uuid,'^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$','c')
            AND REGEXP_LIKE(content_hash,'^[a-f0-9]{64}$','c') AND REGEXP_LIKE(evidence_hash,'^[a-f0-9]{64}$','c')
            AND JSON_TYPE(content)='OBJECT' AND COALESCE(JSON_EXTRACT(content,'$.version')=1,0)
            AND JSON_TYPE(evidence)='OBJECT' AND COALESCE(JSON_EXTRACT(evidence,'$.version')=1,0) AND COALESCE(JSON_EXTRACT(evidence,'$.synthetic')=true,0)
            AND ((status='prepared' AND result_movement_id IS NULL AND result_asiento_id IS NULL) OR (status='confirmed' AND result_movement_id IS NOT NULL AND result_asiento_id IS NOT NULL)))");
        Schema::create('bank_charge_reversal_lines',function(Blueprint $t){
            $t->engine='InnoDB';$t->id();$t->unsignedBigInteger('reversal_id');$t->unsignedBigInteger('original_asiento_id');$t->unsignedBigInteger('reversal_asiento_id');
            $t->unsignedBigInteger('original_detail_id')->unique('refund_original_detail_uq');$t->unsignedBigInteger('reversal_detail_id')->unique('refund_reverse_detail_uq');$t->unsignedBigInteger('account_id');
            $t->foreignId('currency_id')->constrained('monedas')->restrictOnDelete()->restrictOnUpdate();
            foreach(['original_debe','original_haber','reversal_debe','reversal_haber'] as $c)$t->decimal($c,15,2);
            $t->foreign(['reversal_id','original_asiento_id','reversal_asiento_id'],'refund_line_parent_fk')->references(['id','original_asiento_id','result_asiento_id'])->on('bank_charge_reversals')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['original_detail_id','original_asiento_id','account_id'],'refund_line_original_fk')->references(['id','asiento_id','cuenta_id'])->on('detalle_asientos')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['reversal_detail_id','reversal_asiento_id','account_id'],'refund_line_inverse_fk')->references(['id','asiento_id','cuenta_id'])->on('detalle_asientos')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE bank_charge_reversal_lines ADD CONSTRAINT refund_line_ck CHECK (original_debe>=0 AND original_haber>=0 AND ((original_debe>0 AND original_haber=0) OR (original_haber>0 AND original_debe=0)) AND reversal_debe=original_haber AND reversal_haber=original_debe)');
    }
    public function down(): void {
        $this->guard();foreach(['bank_charge_reversal_lines','bank_charge_reversals'] as $t)if(!Schema::hasTable($t)||DB::table($t)->exists())throw new RuntimeException('Evidencia o estado parcial; rollback rechazado antes de DDL.');
        Schema::drop('bank_charge_reversal_lines');Schema::drop('bank_charge_reversals');
    }
};
