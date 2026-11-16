<?php

namespace Tests\Support;

use App\Models\{Banco,CuentaBancaria,CuentaContable,SesionCaja,Usuario};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait BankBalanceFixtures
{
    protected function bankActor(): Usuario
    {
        $u=Usuario::where('is_active',true)->whereIn('role',['alvaro','admin','contador'])->orderBy('id')->firstOrFail();
        $this->actingAs($u);return $u;
    }
    protected function bankDate(string $day='2030-04-15'): void
    {
        Carbon::setTestNow($day.' 10:00:00');
        if(!DB::table('periodos_contables')->where('fecha_inicio','<=',$day)->where('fecha_fin','>=',$day)->exists()) {
            DB::table('periodos_contables')->insert(['nombre'=>'AC04B0 HYPOTHETICAL TEST ONLY','fecha_inicio'=>Carbon::parse($day)->startOfMonth()->toDateString(),
                'fecha_fin'=>Carbon::parse($day)->endOfMonth()->toDateString(),'estado'=>'abierto','created_at'=>now(),'updated_at'=>now()]);
        }
    }
    protected function bankFixture(float $balance=1000,string $code='BAC',bool $usd=false): CuentaBancaria
    {
        $bank=Banco::firstOrCreate(['codigo'=>$code],['nombre'=>'AC04B0 '.$code,'activo'=>true]);
        $account=$usd?($code==='BAC'?'1.1.2.3':'1.1.2.4'):($code==='BAC'?'1.1.2.2':'1.1.2.1');
        return CuentaBancaria::create(['banco_id'=>$bank->id,'numero_cuenta'=>'AC04B0-'.bin2hex(random_bytes(8)),
            'tipo_cuenta'=>'Corriente','alias'=>'AC04B0 fixture sin autorización empresarial','moneda_id'=>DB::table('monedas')->where('codigo',$usd?'USD':'NIO')->value('id'),
            'cuenta_contable_id'=>CuentaContable::where('codigo',$account)->firstOrFail()->id,'saldo_actual'=>$balance,'activa'=>true]);
    }
    protected function bankCash(float $balance=5000): SesionCaja
    {
        return SesionCaja::create(['user_id'=>auth()->id(),'fecha_apertura'=>now(),'monto_apertura'=>$balance,'estado'=>'abierta']);
    }
    protected function charge(CuentaBancaria $b,float $amount=50): array
    {
        return ['cuenta_bancaria_id'=>$b->id,'monto'=>$amount,'concepto'=>'AC04B0 cargo de ensayo',
            'cuenta_contable_id'=>CuentaContable::where('codigo','6.9')->firstOrFail()->id,'fecha'=>now()->toDateString()];
    }
    protected function transfer(CuentaBancaria $from,CuentaBancaria $to,float $amount=80,float $fee=0): array
    {
        return ['origen_tipo'=>'banco','origen_id'=>$from->id,'destino_tipo'=>'banco','destino_id'=>$to->id,
            'monto'=>$amount,'comision'=>$fee,'concepto'=>'AC04B0 transferencia de ensayo','fecha'=>now()->toDateString()];
    }
    protected function assertBankReconciled(CuentaBancaria $bank,float $opening): void
    {
        $rows=DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->get();$net=0;
        foreach($rows as $r){$net+=(in_array($r->tipo,['deposito','abono'])?1:-1)*(int)round((float)$r->monto*100);$this->assertNotNull($r->asiento_contable_id);}
        $this->assertSame((int)round($opening*100)+$net,(int)round((float)$bank->refresh()->saldo_actual*100));
        foreach($rows->pluck('asiento_contable_id')->unique() as $id) {
            $lines=DB::table('detalle_asientos')->where('asiento_id',$id)->get();$this->assertGreaterThanOrEqual(2,$lines->count());
            $this->assertSame((int)round($lines->sum('debe')*100),(int)round($lines->sum('haber')*100));
            $this->assertTrue($lines->contains(fn($l)=>(int)$l->cuenta_id===(int)$bank->cuenta_contable_id));
        }
    }
}
