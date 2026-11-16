<?php

namespace Tests\Support;

use App\Models\CuentaBancaria;
use Illuminate\Support\Facades\DB;

trait BankReconciliationFixtures
{
    use BankBalanceFixtures,AccountingEventFixtures;
    protected function reconciliationBank(string $code='BAC'): CuentaBancaria {
        $bank=$this->bankFixture(0,$code);$bank->update(['alias'=>'AC04B2B2R SYNTHETIC '.bin2hex(random_bytes(5))]);return $bank;
    }
    protected function documentedEntry(CuentaBancaria $bank,int $amount,string $type='abono',?string $day=null): object {
        $account=DB::table('cuentas_contables')->where('codigo',$type==='abono'?'4.7':'6.9')->value('id');
        $this->post('/bancos/'.$type,['cuenta_bancaria_id'=>$bank->id,'cuenta_contable_id'=>$account,'monto'=>$amount,'fecha'=>$day??now()->toDateString(),'concepto'=>'AC04B2B2R SYNTHETIC DOCUMENTED FIXTURE'])->assertSessionHas('success')->assertSessionHasNoErrors();
        return DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->orderByDesc('id')->first();
    }
    protected function reconciliationPayload(string $day,float $amount,array $movements=[],?int $base=null): array {
        return ['fecha'=>$day,'saldo_extracto'=>$amount,'movimientos'=>$movements,'base_conciliacion_id'=>$base,'observaciones'=>'AC04B2B2R SYNTHETIC CUT'];
    }
}
