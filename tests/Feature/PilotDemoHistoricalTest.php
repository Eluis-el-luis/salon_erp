<?php
namespace Tests\Feature;

use App\Services\{AccountingPolicyService,PilotDemoReadOnly};
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Hash};
use Illuminate\Support\Str;
use Tests\Support\{AccountingPilotFixtures,PilotHttpClient};
use Tests\TestCase;

final class PilotDemoHistoricalTest extends TestCase
{
    use AccountingPilotFixtures;
    public function test_final_persisted_history_permissions_and_revoked_replay(): void {
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);$this->actingAs(Usuario::findOrFail($f['actor']));$bank=\App\Models\CuentaBancaria::findOrFail($f['bank']);
        $this->assertSame('450.00',$bank->saldo_actual);$this->assertBankReconciled($bank,1000);
        $this->assertSame(11,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->count());
        $ops=DB::table('accounting_event_operations')->where('bank_account_id',$bank->id)->get();$this->assertCount(10,$ops);
        foreach($ops as $op){$this->assertSame('confirmed',$op->status);$this->assertSame(2,DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->count());$this->assertSame($f['policy'],(int)$op->policy_version_id);}
        $race=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-races.json')),true,512,JSON_THROW_ON_ERROR);$this->assertCount(7,$race);
        foreach($race as $record){$this->assertTrue($record['verified']);$this->assertNotSame($record['workers'][0]['connection_id'],$record['workers'][1]['connection_id']);$this->assertNotSame($record['workers'][0]['pid'],$record['workers'][1]['pid']);}
        $recon=DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$bank->id)->first();$this->assertNotNull($recon);$snapshot=json_encode($recon);$this->assertSame('cerrada',$recon->estado);$this->assertSame('-500.00',$recon->saldo_libros);$this->assertSame('0.00',$recon->diferencia);
        $this->assertSame(10,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->where('estado_conciliacion','en_transito')->count());$this->assertSame(1,DB::table('movimientos_bancarios')->where('cuenta_bancaria_id',$bank->id)->where('estado_conciliacion','pendiente')->count());
        $c=new PilotHttpClient(8775);$server=$c->startServer();
        try {
            $this->assertSame(302,$c->login(auth()->user()->email,'SYNTHETIC-AC04B2B4B-LOCAL-TEST-ONLY'));$payload=$f['input']+['authorization_id'=>$f['grant']];
            $this->assertSame(302,$c->confirm($payload)['status']);$this->assertSame('450.00',$bank->refresh()->saldo_actual);
            $this->assertSame(422,$c->confirm(array_replace($payload,['uuid'=>(string)Str::uuid()]))['status']);
            $this->assertSame(403,$c->request('/__pilot-demo/operations/'.DB::table('accounting_event_operations')->where('id',131)->value('operation_uuid'))['status']);
            $this->assertSame(403,$c->confirm(array_replace($payload,['authorization_id'=>DB::table('accounting_event_operations')->where('id',131)->value('authorization_id')]))['status']);
            $this->assertSame(404,$c->request('/__pilot-demo/refund','POST',['_token'=>$c->token])['status']);
            DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['activa'=>false]);DB::table('cuentas_contables')->where('id',$f['expense'])->update(['activa'=>false]);
            $this->assertSame(200,$c->request('/__pilot-demo/operations/'.$f['input']['uuid'])['status']);
            DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['activa'=>true,'cuenta_contable_id'=>DB::table('cuentas_contables')->where('codigo','1.1.2.2')->value('id')]);
            $this->assertSame(200,$c->request('/__pilot-demo/operations/'.$f['input']['uuid'])['status']);
            DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['cuenta_contable_id'=>$f['funds']]);DB::table('cuentas_contables')->where('id',$f['expense'])->update(['activa'=>true]);
            $other=Usuario::create(['name'=>'AC04B2B4B SYNTHETIC NON OPERATOR','email'=>'ac04b2b4b-denied-'.bin2hex(random_bytes(5)).'@example.test','password'=>Hash::make('SYNTHETIC-NON-OPERATOR-ONLY'),'role'=>'recepcion','role_id'=>auth()->user()->role_id,'is_active'=>true]);
            $foreign=new PilotHttpClient(8775);$foreign->login($other->email,'SYNTHETIC-NON-OPERATOR-ONLY');$this->assertSame(302,$foreign->request('/__pilot-demo')['status']);
            $c->request('/__pilot-demo/logout','POST',['_token'=>$c->token]);$this->assertContains($c->confirm($payload)['status'],[302,419]);
        } finally {DB::table('cuentas_bancarias')->where('id',$bank->id)->update(['activa'=>true,'cuenta_contable_id'=>$f['funds']]);DB::table('cuentas_contables')->where('id',$f['expense'])->update(['activa'=>true]);proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}
        // Publicación técnica posterior, con asociación de gasto distinta; no nueva autoridad económica.
        $policy=app(AccountingPolicyService::class);$source=DB::table('accounting_policy_versions')->find($f['policy']);$content=$policy->contentForInspection($source);$start=Carbon::parse($source->valid_until,'UTC');
        $v=$this->policyDraft(['label'=>'AC04B2B4B SYNTHETIC FUTURE HISTORY TEST','valid_from'=>$start->format('Y-m-d\TH:i:s\Z'),'valid_until'=>$start->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),'coverage'=>$content['coverage']]);
        $bindings=array_map(function($r){$b=array_intersect_key($r,array_flip(['role','scope_type','resource_id','account_id']));if($r['role']==='gasto.cargo_bancario')$b['account_id']=(int)DB::table('cuentas_contables')->where('codigo','6.9')->value('id');return $b;},$content['bindings']);
        $v=$policy->replaceBindings($v->id,$v->content_hash,$bindings,'SYNTHETIC prospective association');$policy->publish($v->id,$v->content_hash,'SYNTHETIC-ONLY','No business approval or enablement');
        if(!defined('SALON_PILOT_DEMO_READONLY'))define('SALON_PILOT_DEMO_READONLY',PilotDemoReadOnly::CONTRACT);
        try{Carbon::setTestNow($start->copy()->addHours(1));$d=(new PilotDemoReadOnly)->document($f['input']['uuid']);$this->assertSame($f['policy'],(int)$d['policy']->id);$this->assertTrue($d['revoked']);$this->assertSame($f['expense'],(int)array_values(array_filter($d['resolutions'],fn($r)=>$r->semantic_role==='gasto.cargo_bancario'))[0]->account_id);}finally{Carbon::setTestNow();}
        $this->assertSame($snapshot,json_encode(DB::table('conciliaciones_bancarias')->find($recon->id)));$this->assertSame('450.00',$bank->refresh()->saldo_actual);
        $this->assertSame('prepared',DB::table('accounting_event_operations')->where('id',131)->value('status'));
    }
}
