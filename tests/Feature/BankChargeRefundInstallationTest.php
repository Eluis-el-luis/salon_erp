<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class BankChargeRefundInstallationTest extends TestCase
{
    public function test_install_empty_rollback_reinstall_only_new_tables(): void {
        $this->assertSame(81,DB::table('migrations')->count());$m=require base_path('database/migrations/2026_11_16_000003_create_bank_charge_refunds.php');
        $m->up();foreach(['bank_charge_reversals','bank_charge_reversal_lines'] as $t)$this->assertTrue(Schema::hasTable($t));
        $fks=DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA','salon_erp_test')->whereIn('TABLE_NAME',['bank_charge_reversals','bank_charge_reversal_lines'])->get();
        $this->assertCount(9,$fks);foreach($fks as $fk){$this->assertSame('RESTRICT',$fk->DELETE_RULE);$this->assertSame('RESTRICT',$fk->UPDATE_RULE);}
        $m->down();$this->assertFalse(Schema::hasTable('bank_charge_reversals'));$m->up();
        DB::table('migrations')->insert(['migration'=>'2026_11_16_000003_create_bank_charge_refunds','batch'=>DB::table('migrations')->max('batch')+1]);$this->assertSame(82,DB::table('migrations')->count());
    }
}
