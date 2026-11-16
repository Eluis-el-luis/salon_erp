<?php

namespace Tests\Feature;

use App\Models\{CuentaContable,SaldoMoneda};
use App\Services\{BankBalanceService,FondosService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BankBalanceFixtures;
use Tests\TestCase;

class BankBalanceIntegrityTest extends TestCase
{
    use DatabaseTransactions,BankBalanceFixtures;
    protected function setUp(): void {parent::setUp();$this->bankActor();$this->bankDate();}
    protected function tearDown(): void {Carbon::setTestNow();parent::tearDown();}
    public function test_charge_and_credit_keep_original_accounts_and_book(): void
    {
        $b=$this->bankFixture();$this->post('/bancos/cargo',$this->charge($b))->assertSessionHas('success');
        $this->post('/bancos/abono',array_replace($this->charge($b,20),['cuenta_contable_id'=>CuentaContable::where('codigo','4.7')->firstOrFail()->id]))->assertSessionHas('success');
        $this->assertSame(970.0,(float)$b->refresh()->saldo_actual);$this->assertBankReconciled($b,1000);
        $movement=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->where('tipo','cargo')->first();
        $this->assertSame('50.00',DB::table('detalle_asientos')->where('asiento_id',$movement->asiento_contable_id)->where('cuenta_id',$this->charge($b)['cuenta_contable_id'])->value('debe'));
    }
    public function test_deposit_and_transfer_keep_fee_and_balances(): void
    {
        $a=$this->bankFixture();$b=$this->bankFixture(1000,'LAFISE');$cash=$this->bankCash();
        $this->post('/bancos/depositar',['cuenta_destino_id'=>$a->id,'caja_origen_id'=>$cash->id,'moneda'=>'nio','monto'=>50,'fecha'=>now()->toDateString()])->assertSessionHas('success');
        $this->post('/bancos/transferir',$this->transfer($a,$b,100,4))->assertSessionHas('success');
        $this->assertSame(946.0,(float)$a->refresh()->saldo_actual);$this->assertSame(1100.0,(float)$b->refresh()->saldo_actual);
        $this->assertBankReconciled($a,1000);$this->assertBankReconciled($b,1000);
    }
    public function test_insufficient_transfer_does_not_leave_any_effect(): void
    {
        $a=$this->bankFixture(100);$b=$this->bankFixture(0,'LAFISE');$n=DB::table('asientos_contables')->count();
        $this->post('/bancos/transferir',$this->transfer($a,$b,100,1))->assertSessionHasErrors('error');
        $this->assertSame(100.0,(float)$a->refresh()->saldo_actual);$this->assertSame($n,DB::table('asientos_contables')->count());
        $this->assertSame(0,DB::table('movimientos_bancarios')->whereIn('cuenta_bancaria_id',[$a->id,$b->id])->count());
    }
    public function test_currency_mismatch_and_inactive_account_rejected(): void
    {
        $a=$this->bankFixture();$usd=$this->bankFixture(100,'BAC',true);$cash=$this->bankCash();
        $this->post('/bancos/transferir',$this->transfer($a,$usd))->assertSessionHasErrors('error');
        $this->post('/bancos/depositar',['cuenta_destino_id'=>$usd->id,'caja_origen_id'=>$cash->id,'moneda'=>'nio','monto'=>50])->assertSessionHasErrors('error');
        $a->update(['activa'=>false]);$this->post('/bancos/cargo',$this->charge($a))->assertSessionHasErrors('error');
        $this->assertSame(1000.0,(float)$a->refresh()->saldo_actual);$this->assertSame(100.0,(float)$usd->refresh()->saldo_actual);
    }
    public function test_lock_order_is_sorted_unique_and_reloads_stale_model(): void
    {
        $a=$this->bankFixture();$b=$this->bankFixture();DB::table('cuentas_bancarias')->where('id',$a->id)->update(['saldo_actual'=>75]);
        $locked=app(BankBalanceService::class)->lockAccounts([$b->id,$a->id,$a->id]);
        $this->assertSame([$a->id,$b->id],$locked->keys()->all());$this->assertSame(75.0,(float)$locked[$a->id]->saldo_actual);
        $this->assertSame(1000.0,(float)$a->saldo_actual);
    }
    public static function failures(): array {return [['before_entry'],['before_balance'],['second_line']];}
    #[DataProvider('failures')]
    public function test_injected_failure_rolls_back_book_entry_and_balance(string $stage): void
    {
        $b=$this->bankFixture();$entries=DB::table('asientos_contables')->count();$details=DB::table('detalle_asientos')->count();$line=0;
        DB::connection()->beforeExecuting(function($sql)use($stage,&$line){
            if(($stage==='before_entry' && str_starts_with($sql,'insert into `asientos_contables`'))
                || ($stage==='before_balance' && str_starts_with($sql,'update `cuentas_bancarias`') && str_contains($sql,'`saldo_actual`'))
                || ($stage==='second_line' && str_starts_with($sql,'insert into `detalle_asientos`') && ++$line===2))throw new \RuntimeException('AC04B0 injected failure');
        });
        $this->post('/bancos/cargo',$this->charge($b))->assertSessionHasErrors('error');
        $this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);$this->assertSame($entries,DB::table('asientos_contables')->count());
        $this->assertSame($details,DB::table('detalle_asientos')->count());$this->assertSame(0,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->count());
    }
    public function test_real_charge_keeps_existing_overdraft_semantics(): void
    {
        $b=$this->bankFixture(10);$this->post('/bancos/cargo',$this->charge($b,20))->assertSessionHas('success');
        $this->assertSame(-10.0,(float)$b->refresh()->saldo_actual);$this->assertBankReconciled($b,10);
    }
    public function test_exchange_purchase_keeps_average_and_nio_bank_leg(): void
    {
        $b=$this->bankFixture();$cash=$this->bankCash();
        $this->post('/mesa-cambio/operar',['tipo'=>'compra','monto_usd'=>10,'tasa_aplicada'=>10,'tasa_oficial'=>10,
            'origen_tipo'=>'banco','origen_id'=>$b->id,'destino_tipo'=>'sesion','destino_id'=>$cash->id])->assertSessionHas('success');
        $this->assertSame(900.0,(float)$b->refresh()->saldo_actual);$this->assertBankReconciled($b,1000);
        $s=SaldoMoneda::where('ubicacion_tipo',FondosService::SESION)->where('ubicacion_id',$cash->id)->firstOrFail();
        $this->assertSame(10.0,(float)$s->saldo_actual);$this->assertSame(10.0,(float)$s->costo_promedio_ponderado);
    }
    public function test_usd_deposit_preserves_native_balance_and_book_valuation(): void
    {
        $b=$this->bankFixture(100,'BAC',true);$cash=$this->bankCash();
        SaldoMoneda::create(['moneda_id'=>$b->moneda_id,'ubicacion_tipo'=>FondosService::SESION,'ubicacion_id'=>$cash->id,'saldo_actual'=>10,'costo_promedio_ponderado'=>36]);
        $this->post('/bancos/depositar',['cuenta_destino_id'=>$b->id,'caja_origen_id'=>$cash->id,'moneda'=>'usd','monto'=>5])->assertSessionHas('success');
        $this->assertSame(105.0,(float)$b->refresh()->saldo_actual);$this->assertBankReconciled($b,100);
        $id=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->value('asiento_contable_id');
        $this->assertSame(180.0,(float)DB::table('detalle_asientos')->where('asiento_id',$id)->sum('debe'));
    }
    public function test_session_and_existing_bank_roles_still_apply(): void
    {
        $b=$this->bankFixture();$actor=auth()->user();auth()->logout();
        $this->post('/bancos/cargo',$this->charge($b))->assertRedirect('/login');
        DB::table('users')->where('id',$actor->id)->update(['role'=>'recepcion']);$this->actingAs($actor->fresh());
        $this->post('/bancos/cargo',$this->charge($b))->assertForbidden();
        $this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);
        $this->assertSame(0,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$b->id)->count());
    }
    public static function otherFlows(): array {return [['deposit'],['transfer'],['exchange']];}
    #[DataProvider('otherFlows')]
    public function test_bank_update_failure_rolls_back_all_related_documents(string $flow): void
    {
        $a=$this->bankFixture();$b=$this->bankFixture(1000,'LAFISE');$cash=$this->bankCash();
        $tables=['asientos_contables','detalle_asientos','transferencias','movimientos_bancarios','operaciones_cambio','saldos_moneda'];
        $before=[];foreach($tables as $table)$before[$table]=DB::table($table)->count();
        DB::connection()->beforeExecuting(function($sql){if(str_starts_with($sql,'update `cuentas_bancarias`')&&str_contains($sql,'`saldo_actual`'))throw new \RuntimeException('AC04B0 injected bank update failure');});
        [$url,$payload]=match($flow) {
            'deposit'=>['/bancos/depositar',['cuenta_destino_id'=>$a->id,'caja_origen_id'=>$cash->id,'moneda'=>'nio','monto'=>50]],
            'transfer'=>['/bancos/transferir',$this->transfer($a,$b)],
            'exchange'=>['/mesa-cambio/operar',['tipo'=>'compra','monto_usd'=>10,'tasa_aplicada'=>10,'tasa_oficial'=>10,'origen_tipo'=>'banco','origen_id'=>$a->id,'destino_tipo'=>'sesion','destino_id'=>$cash->id]],
        };
        $this->post($url,$payload)->assertSessionHasErrors('error');
        foreach($before as $table=>$count)$this->assertSame($count,DB::table($table)->count(),$table);
        $this->assertSame(1000.0,(float)$a->refresh()->saldo_actual);$this->assertSame(1000.0,(float)$b->refresh()->saldo_actual);
    }
}
