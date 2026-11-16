<?php
namespace Tests\Feature;

use App\Services\AccountingPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\{AccountingPilotFixtures,PilotHttpClient};
use Tests\TestCase;

/** Dos servidores/procesos HTTP y conexiones SQL reales; evidencia append-only de ensayo. */
final class PilotDemoHttpConcurrencyTest extends TestCase
{
    use AccountingPilotFixtures;
    private array $workers=[];
    private function http(PilotHttpClient $c,array $payload): int {
        $p=proc_open([PHP_BINARY,__DIR__.'/../Support/pilot-demo-http-client.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());$this->assertIsResource($p);stream_set_timeout($pipes[1],30);
        fwrite($pipes[0],json_encode(['port'=>$c->port,'cookie'=>$c->cookie,'token'=>$c->token,'payload'=>$payload],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);$index=count($this->workers);$this->workers[]=[$p,$pipes,'http',null];$this->assertSame("READY\n",fgets($pipes[1]));return $index;
    }
    private function domain(array $data): int {
        $path=storage_path('app/private/ac04b2b4b-worker-'.bin2hex(random_bytes(8)).'.result');$data['result']=$path;
        $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',__DIR__.'/../Support/ac04b2b4b-domain-worker.php',json_encode($data,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());$this->assertIsResource($p);stream_set_timeout($pipes[1],30);$index=count($this->workers);$this->workers[]=[$p,$pipes,'domain',$path];$this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));return $index;
    }
    private function signal(int $i,string $s): void {fwrite($this->workers[$i][1][0],$s."\n");fflush($this->workers[$i][1][0]);}
    private function line(int $i,string $expected): void {$this->assertSame($expected."\n",str_replace("\r",'',fgets($this->workers[$i][1][1])));}
    private function finish(int $i,int $code=0): array {
        [$p,$pipes,$kind,$path]=$this->workers[$i];fclose($pipes[0]);$out=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);$this->workers[$i]=null;$this->assertSame('',$err);$this->assertSame($code,$exit,$out);
        if($kind==='http'){$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);$this->assertSame(302,$r['status']);return ['connection_id'=>(int)$r['headers']['x-synthetic-connection'],'pid'=>(int)$r['headers']['x-synthetic-process'],'status'=>$r['status']];}
        $r=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$this->assertSame($code===0?'RESULT:accepted':'RESULT:rejected',$out);return $r;
    }
    private function waitBankLock(): void {
        for($i=0;$i<80;$i++){foreach(DB::select('SHOW PROCESSLIST') as $p)if(str_contains($p->Info??'','`cuentas_bancarias`')&&str_contains(strtolower($p->Info??''),'for update')){$this->assertTrue(true);return;}usleep(50000);}
        $this->fail('No se observó la lectura SQL bancaria bloqueada del cliente HTTP.');
    }
    public function test_seven_real_races_preserve_persisted_documents(): void {
        $journal=storage_path('app/private/ac04b2b4b-http-races.json');$resume=getenv('AC04B2B4B_RESUME')==='after_fixture_rejection';
        if(!$resume)$this->assertFileDoesNotExist($journal,'No repetir carreras sobre evidencia previa.');
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);$this->assertFileExists(storage_path('app/private/ac04b2b4b-functional-result.json'));
        $this->actingAs(\App\Models\Usuario::findOrFail($f['actor']));$bank=\App\Models\CuentaBancaria::findOrFail($f['bank']);$this->assertSame($resume?'550.00':'950.00',$bank->saldo_actual);
        $clients=[new PilotHttpClient(8775),new PilotHttpClient(8776)];$servers=[];$records=$resume?json_decode(file_get_contents($journal),true,512,JSON_THROW_ON_ERROR):[];
        if($resume){$this->assertSame(['same_uuid','distinct_uuid','publication','legacy','preparation'],array_column($records,'case'));$this->assertSame(0,DB::table('conciliaciones_bancarias')->where('cuenta_bancaria_id',$bank->id)->count());
            $prior=DB::table('accounting_event_operations')->where('bank_account_id',$bank->id)->where('content','like','%SYNTHETIC-HTTP-RACE-reconciliation%')->first();$this->assertSame('confirmed',$prior->status);file_put_contents(storage_path('app/private/ac04b2b4b-rejected-reconciliation-attempt.json'),json_encode(['operation_id'=>$prior->id,'movement_id'=>$prior->result_movement_id,'entry_id'=>$prior->result_asiento_id,'reconciliation'=>'rejected before lock: invalid negative statement fixture','balance'=>$bank->saldo_actual]));
        } else {$h=fopen($journal,'xb');fwrite($h,'[]');fclose($h);}
        try {
            foreach($clients as $c){$servers[]=$c->startServer();$this->assertSame(302,$c->login(auth()->user()->email,'SYNTHETIC-AC04B2B4B-LOCAL-TEST-ONLY'));}
            foreach(['same_uuid','distinct_uuid','publication','legacy','preparation','reconciliation','withdrawal'] as $case){
                if($resume&&in_array($case,array_column($records,'case'),true))continue;
                $p=array_replace($f['input'],['uuid'=>(string)Str::uuid(),'reference'=>'SYNTHETIC-HTTP-RACE-'.$case]);$payload=$p+['authorization_id'=>$f['grant']];$before=(float)$bank->refresh()->saldo_actual;$entries=1;
                if($case==='publication'){
                    $service=app(AccountingPolicyService::class);$source=DB::table('accounting_policy_versions')->find($f['policy']);$content=$service->contentForInspection($source);
                    $draft=$this->policyDraft(['valid_from'=>now('UTC')->addMinutes(2)->format('Y-m-d\TH:i:s\Z'),'valid_until'=>now('UTC')->addMinutes(3)->format('Y-m-d\TH:i:s\Z'),'coverage'=>$content['coverage']]);
                    $bindings=array_map(fn($r)=>array_intersect_key($r,array_flip(['role','scope_type','resource_id','account_id'])),$content['bindings']);$draft=$service->replaceBindings($draft->id,$draft->content_hash,$bindings,'SYNTHETIC overlapping publication race');
                }
                $a=$this->http($clients[0],$payload);$b=null;$meta=['actor'=>$f['actor'],'day'=>$f['day'],'hold'=>false];
                if($case==='preparation'){
                    $b=$this->domain(array_replace($meta,['hold'=>true,'action'=>'prepare','grant'=>$f['grant'],'input'=>$p]));
                    $this->signal($b,'go');$this->line($b,'LOCKED');$this->signal($a,'go');$this->line($a,'ATTEMPT');$this->waitBankLock();
                    $this->signal($b,'release');$rb=$this->finish($b);$ra=$this->finish($a);$this->assertSame('prepared',$rb['result']['status']);
                } else {
                DB::beginTransaction();DB::table('cuentas_bancarias')->where('id',$bank->id)->lockForUpdate()->first();
                $this->signal($a,'go');$this->line($a,'ATTEMPT');$this->waitBankLock();
                if(in_array($case,['same_uuid','distinct_uuid'],true)){
                    $q=$payload;if($case==='distinct_uuid'){$q['uuid']=(string)Str::uuid();$entries=2;}$b=$this->http($clients[1],$q);$this->signal($b,'go');$this->line($b,'ATTEMPT');
                } elseif($case==='publication'){
                    $b=$this->domain($meta+['action'=>'publish','version'=>$draft->id,'hash'=>$draft->content_hash]);$this->signal($b,'go');$this->line($b,'ATTEMPT');
                } elseif($case==='legacy'){
                    $b=$this->domain($meta+['action'=>'legacy','legacy_payload'=>$this->charge($bank)]);$entries=2;$this->signal($b,'go');$this->line($b,'ATTEMPT');
                } elseif($case==='withdrawal'){
                    $b=$this->domain($meta+['action'=>'revoke','grant'=>$f['grant']]);$this->signal($b,'go');$this->line($b,'ATTEMPT');
                } elseif($case==='reconciliation'){
                    // Base cero provisional: ningún movimiento presentado; los cargos quedan en tránsito.
                    $b=$this->domain($meta+['action'=>'reconcile','bank'=>$bank->id,'legacy_payload'=>['fecha'=>$f['day'],'saldo_extracto'=>0,'movimientos'=>[],'base_conciliacion_id'=>null,'observaciones'=>'SYNTHETIC HTTP RACE CUT']]);$this->signal($b,'go');$this->line($b,'ATTEMPT');
                }
                usleep(200000);DB::commit();$ra=$this->finish($a);$rb=$b!==null?$this->finish($b,$case==='publication'?2:0):null;
                }
                if($rb){$this->assertNotSame($ra['connection_id'],$rb['connection_id']);$this->assertNotSame($ra['pid'],$rb['pid']);}
                $this->assertSame($before-$entries*50,(float)$bank->refresh()->saldo_actual,$case);$this->assertBankReconciled($bank,1000);
                $op=DB::table('accounting_event_operations')->where('operation_uuid',$p['uuid'])->first();$this->assertSame('confirmed',$op->status);$this->assertSame(2,DB::table('accounting_entry_line_resolutions')->where('operation_id',$op->id)->count());
                $records[]=['case'=>$case,'operation_id'=>$op->id,'bank'=>$bank->id,'balance'=>$bank->saldo_actual,'workers'=>[$ra,$rb],'verified'=>true];file_put_contents($journal,json_encode($records,JSON_THROW_ON_ERROR));
            }
            $this->assertCount(7,$records);
        } finally {
            while(DB::transactionLevel()>0)DB::rollBack();foreach($this->workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}
            foreach($servers as $server){proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}
        }
    }
}
