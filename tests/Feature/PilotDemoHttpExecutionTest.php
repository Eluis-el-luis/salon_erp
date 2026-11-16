<?php
namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\{AccountingPolicyService,AccountingEventAuthorizationService,AccountIntegrityService};
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use Tests\Support\{AccountingPilotFixtures,PilotHttpClient};
use Tests\TestCase;

/** Ensayo HTTP persistente identificado; nunca reset ni reutilización de ventanas previas. */
final class PilotDemoHttpExecutionTest extends TestCase
{
    use AccountingPilotFixtures;
    private const PASSWORD='SYNTHETIC-AC04B2B4B-LOCAL-TEST-ONLY';
    public function test_live_http_contract_and_documented_results(): void {
        $file=storage_path('app/private/ac04b2b4b-http-fixture.json');
        if(is_file($file)) {
            // Reanudación explícita tras inspeccionar rollback: conserva el UUID original.
            $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$day=$fixture['day'];$p=$fixture['input'];
            $actor=Usuario::findOrFail($fixture['actor']);$this->actingAs($actor);$bank=\App\Models\CuentaBancaria::findOrFail($fixture['bank']);
            $expense=\App\Models\CuentaContable::findOrFail($fixture['expense']);$funds=\App\Models\CuentaContable::findOrFail($fixture['funds']);
            $v=DB::table('accounting_policy_versions')->find($fixture['policy']);$g=DB::table('accounting_policy_authorizations')->find($fixture['grant']);
            $this->assertContains($bank->saldo_actual,['1000.00','950.00'],'Revisar efectos posteriores antes de reanudar.');
        } else {
        Carbon::setTestNow();$day=now('UTC')->toDateString();$until=now('UTC')->addDay()->toDateString();$from=now('UTC')->addSeconds(15)->format('Y-m-d\TH:i:s\Z');
        $this->assertFalse(DB::table('accounting_policy_versions')->where('status','published')->where('valid_from','<',$until.' 00:00:00')->where('valid_until','>',$day.' 00:00:00')->exists(),'Ventana actual ya ocupada.');
        $this->policyActor();$actor=Usuario::create(['name'=>'AC04B2B4B SYNTHETIC HTTP','email'=>'ac04b2b4b-'.bin2hex(random_bytes(5)).'@example.test','password'=>Hash::make(self::PASSWORD),'role'=>'contador','role_id'=>auth()->user()->role_id,'is_active'=>true]);$this->actingAs($actor);
        $accounts=app(AccountIntegrityService::class);$suffix=bin2hex(random_bytes(4));$expense=$accounts->save(['codigo'=>'6.HTTP'.$suffix,'nombre'=>'SYNTHETIC HTTP expense','tipo'=>'gasto','naturaleza'=>'deudora','permite_movimiento'=>true,'cuenta_padre_id'=>DB::table('cuentas_contables')->where('codigo','6')->value('id')]);
        $funds=$accounts->save(['codigo'=>'1.1.2.HTTP'.$suffix,'nombre'=>'SYNTHETIC HTTP funds','tipo'=>'activo','naturaleza'=>'deudora','permite_movimiento'=>true,'cuenta_padre_id'=>DB::table('cuentas_contables')->where('codigo','1.1.2')->value('id')]);
        $bank=$this->bankFixture();$bank->update(['alias'=>'AC04B2B4B SYNTHETIC HTTP','cuenta_contable_id'=>$funds->id]);
        $slots=$this->policySlots();$bindings=$this->policyBindings();
        foreach(['fondos.banco'=>$funds->id,'gasto.cargo_bancario'=>$expense->id] as $role=>$account){$slot=['role'=>$role,'scope_type'=>'bank_account','resource_id'=>$bank->id];$slots[]=$slot;$bindings[]=$slot+['account_id'=>$account];}
        $policy=app(AccountingPolicyService::class);$v=$this->policyDraft(['label'=>'AC04B2B4B SYNTHETIC HTTP ONLY','valid_from'=>$from,'valid_until'=>$until.'T00:00:00Z','coverage'=>$slots]);$v=$policy->replaceBindings($v->id,$v->content_hash,$bindings,'Synthetic HTTP fixture');$v=$policy->publish($v->id,$v->content_hash,'SYNTHETIC-HTTP-ONLY','No economic approval');$g=$this->simulationGrant($v,$bank);
        $this->bankDate($day);Carbon::setTestNow();
        $p=['uuid'=>(string)Str::uuid(),'classification'=>'maintenance','amount'=>'50.00','recognition_date'=>$day,'reference'=>'AC04B2B4B-SYNTHETIC','description'=>'Synthetic HTTP charge, no business authority'];
        $fixture=['actor'=>$actor->id,'bank'=>$bank->id,'grant'=>$g->id,'policy'=>$v->id,'expense'=>$expense->id,'funds'=>$funds->id,'day'=>$day,'input'=>$p];$h=fopen($file,'xb');fwrite($h,json_encode($fixture,JSON_THROW_ON_ERROR));fclose($h);
        }
        while(now('UTC')->format('Y-m-d H:i:s')<$v->valid_from)usleep(100000);
        $clients=[new PilotHttpClient(8775),new PilotHttpClient(8776)];$servers=[];
        try {
            foreach($clients as $c){$servers[]=$c->startServer();$this->assertSame(302,$c->login($actor->email,self::PASSWORD));}
            $c=$clients[0];$payload=$p+['authorization_id'=>$g->id];
            $this->assertSame(419,$c->request('/__pilot-demo/confirm','POST',$payload)['status']);$this->assertSame(419,$c->request('/__pilot-demo/confirm','POST',$payload+['_token'=>'invalid'])['status']);
            $this->assertSame(200,$c->request('/__pilot-demo/review','POST',$payload+['_token'=>$c->token])['status']);
            $result=$c->confirm($payload);$this->assertSame(302,$result['status']);$op=DB::table('accounting_event_operations')->where('operation_uuid',$p['uuid'])->first();$this->assertNotNull($op);$this->assertSame('confirmed',$op->status);$this->assertSame('950.00',$bank->refresh()->saldo_actual);
            $page=$c->request('/__pilot-demo/operations/'.$p['uuid']);$this->assertSame(200,$page['status']);$this->assertStringContainsString('950.00',$page['body']);$this->assertStringContainsString('Correspondencias originales',$page['body']);
            $lines=DB::table('detalle_asientos')->where('asiento_id',$op->result_asiento_id)->get();$this->assertCount(2,$lines);$this->assertSame('50.00',$lines->firstWhere('cuenta_id',$expense->id)->debe);$this->assertSame('50.00',$lines->firstWhere('cuenta_id',$funds->id)->haber);$this->assertSame(2,DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->count());
            // Simula respuesta perdida: consulta primero y reenvío explícito del mismo UUID.
            $this->assertSame(302,$c->confirm($payload)['status']);$this->assertSame('950.00',$bank->refresh()->saldo_actual);$this->assertSame(1,DB::table('accounting_event_operations')->where('operation_uuid',$p['uuid'])->count());
            $this->assertSame(409,$c->confirm(array_replace($payload,['amount'=>'51.00']))['status']);
            foreach(['account_id','policy_version_id','actor_id'] as $key)$this->assertSame(422,$c->confirm($payload+[$key=>1])['status']);
            foreach([['amount'=>'0'],['amount'=>'50.001'],['classification'=>'transfer']] as $change)$this->assertSame(422,$c->confirm(array_replace($payload,$change))['status']);
            $new=array_replace($payload,['uuid'=>(string)Str::uuid()]);
            DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['activa'=>false]);$this->assertSame(503,$c->confirm($new)['status']);$this->assertSame(200,$c->request('/__pilot-demo/operations/'.$p['uuid'])['status']);DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['activa'=>true]);
            DB::table('cuentas_contables')->where('id',$expense->id)->update(['activa'=>false]);$this->assertSame(422,$c->confirm($new)['status']);$this->assertSame(200,$c->request('/__pilot-demo/operations/'.$p['uuid'])['status']);DB::table('cuentas_contables')->where('id',$expense->id)->update(['activa'=>true]);
            $period=DB::table('periodos_contables')->where('fecha_inicio','<=',$day)->where('fecha_fin','>=',$day)->first();$this->assertSame('abierto',$period->estado);
            // No cambia el período existente: prueba una fecha cerrada mediante fixture futuro aislado.
            $closed='2087-11';$this->assertFalse(DB::table('periodos_contables')->where('fecha_inicio',$closed.'-01')->exists());$closedId=DB::table('periodos_contables')->insertGetId(['nombre'=>'AC04B2B4B SYNTHETIC CLOSED','fecha_inicio'=>$closed.'-01','fecha_fin'=>$closed.'-30','estado'=>'cerrado','created_at'=>now(),'updated_at'=>now()]);
            $this->assertSame(422,$c->confirm(array_replace($new,['recognition_date'=>$closed.'-15']))['status']);$this->assertSame('950.00',$bank->refresh()->saldo_actual);
            foreach(['/bancos','/.env','/tests/bootstrap.php','/__pilot-demo/refund'] as $path)$this->assertSame(404,$c->request($path)['status']);
            $anonymous=new PilotHttpClient(8775);$this->assertSame(419,$anonymous->confirm($payload)['status']);
            DB::table('users')->where('id',$actor->id)->update(['is_active'=>false]);$this->assertSame(403,$c->confirm($new)['status']);DB::table('users')->where('id',$actor->id)->update(['is_active'=>true]);
            $this->assertBankReconciled($bank,1000);$this->assertSame(1,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->count());
            file_put_contents(storage_path('app/private/ac04b2b4b-functional-result.json'),json_encode(['fixture'=>$fixture,'operation'=>$op->id,'movement'=>$op->result_movement_id,'entry'=>$op->result_asiento_id,'balance'=>$bank->saldo_actual,'connections'=>[$result['headers']['x-synthetic-connection']],'status'=>'passed'],JSON_THROW_ON_ERROR));
        } finally {
            foreach($servers as $server){proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}Carbon::setTestNow();
        }
    }
}
