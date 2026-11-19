<?php
namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\{AccountingAccountResolver,AccountingBankChargeSimulation,AccountingEventAuthorizationService,PilotDemoReadOnly};
use App\Support\{AccountingHttpSimulationBoundary,AccountingSimulationBoundary,AccountingSimulationPosting};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\{PreserveGlobalState,RunInSeparateProcess};
use Tests\Support\PilotHttpClient;
use Tests\TestCase;

/** No INSERT/UPDATE/DELETE/DDL, fixtures nuevos, reenvíos financieros ni cambios de metadata. */
final class PilotDemoFinalAuditTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();DB::connection()->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
        DB::connection()->beforeExecuting(function($sql){if(!preg_match('/^\s*(SELECT|SHOW)\b/i',$sql))throw new \LogicException('AC04B2B4C solo permite consultas.');});
    }

    public function test_strict_cli_http_and_business_entries_remain_closed(): void {
        $this->actingAs(Usuario::where('is_active',true)->whereIn('role',['alvaro','contador'])->firstOrFail());
        AccountingSimulationBoundary::assert(AccountingSimulationBoundary::CONTRACT);
        foreach([
            fn()=>AccountingSimulationBoundary::assert(AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>app(AccountingBankChargeSimulation::class)->executeForInternalHarness(0,[],AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>app(AccountingAccountResolver::class)->inspectPinnedForInternalHarness([],new \DateTimeImmutable,0,AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>AccountingSimulationPosting::forPrepared(0,0,new \DateTimeImmutable,AccountingHttpSimulationBoundary::CONTRACT),
            fn()=>app(AccountingAccountResolver::class)->resolveForPosting(),
            fn()=>app(AccountingEventAuthorizationService::class)->assertBusinessAuthority(0),
        ] as $call){try{$call();$this->fail('Autoridad no autorizada.');}catch(\LogicException $e){$this->assertNotSame('',$e->getMessage());}}
        try{AccountingHttpSimulationBoundary::assert(AccountingHttpSimulationBoundary::CONTRACT);$this->fail('CLI no es el servidor HTTP.');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(404,$e->getStatusCode());}
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('pilot.demo.confirm'));
        $this->get('/__pilot-demo/login')->assertNotFound();$this->post('/__pilot-demo/confirm',[])->assertNotFound();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_existing_prepared_refund_and_expired_revoked_history_are_read_only(): void {
        define('SALON_PILOT_DEMO_READONLY',PilotDemoReadOnly::CONTRACT);
        $prepared=DB::table('accounting_event_operations')->where('id',131)->first();$before=json_encode($prepared);
        $this->actingAs(Usuario::findOrFail($prepared->created_by));
        $this->get('/__pilot-demo/operations/'.$prepared->operation_uuid)->assertOk()->assertSee('Prepared no equivale a éxito financiero')->assertDontSee('Asiento original #');
        $refund=DB::table('bank_charge_reversals')->where('status','confirmed')->first();$original=DB::table('accounting_event_operations')->find($refund->original_operation_id);
        $this->actingAs(Usuario::findOrFail($original->created_by));
        $this->get('/__pilot-demo/operations/'.$original->operation_uuid)->assertOk()->assertSee('Devolución acreditada sintética')->assertSee('Correspondencias originales');
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);
        $this->actingAs(Usuario::findOrFail($f['actor']));$g=DB::table('accounting_policy_authorizations')->find($f['grant']);
        $this->assertTrue($g->valid_until<now('UTC')->format('Y-m-d H:i:s'));
        $this->assertTrue(DB::table('accounting_policy_authorizations')->where('parent_id',$g->id)->where('action','revocation')->exists());
        $d=(new PilotDemoReadOnly)->document($f['input']['uuid']);$this->assertSame($f['policy'],(int)$d['policy']->id);$this->assertTrue($d['revoked']);$this->assertCount(2,$d['details']);$this->assertCount(2,$d['resolutions']);
        try{Carbon::setTestNow('2100-01-01 10:00:00');$future=(new PilotDemoReadOnly)->document($f['input']['uuid']);$this->assertSame($d['o']->content_hash,$future['o']->content_hash);$this->assertSame($d['o']->result_asiento_id,$future['o']->result_asiento_id);}finally{Carbon::setTestNow();}
        $this->get('/__pilot-demo/operations/'.$original->operation_uuid)->assertForbidden();
        $unprivileged=Usuario::where('is_active',true)->whereNotIn('role',['alvaro','contador'])->firstOrFail();$this->actingAs($unprivileged);$this->get('/__pilot-demo')->assertForbidden();
        $this->assertSame($before,json_encode(DB::table('accounting_event_operations')->find(131)));
    }

    public function test_actual_forbidden_listener_cannot_spoof_an_allowed_host(): void {
        $client=new PilotHttpClient(8777);$server=$client->startServer();
        try{
            $this->assertSame(404,$client->request('/__pilot-demo/login')['status']);
            foreach(['127.0.0.1:8775','127.0.0.1:8776','127.0.0.1:8774'] as $host){
                $ctx=stream_context_create(['http'=>['header'=>'Host: '.$host."\r\nServer-Port: 8775\r\n",'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
                file_get_contents('http://127.0.0.1:8777/__pilot-demo/login',false,$ctx);$this->assertStringContainsString(' 404 ',$http_response_header[0]);
            }
        }finally{proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}
    }

    public function test_live_http_session_csrf_ownership_and_read_only_queries(): void {
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);$actor=Usuario::findOrFail($f['actor']);
        $this->assertStringContainsString('SYNTHETIC',$actor->name);$this->assertStringEndsWith('@example.test',$actor->email);
        // Credencial del fixture existente; solo en memoria, sin crear o cambiar accesos.
        $password=(new \ReflectionClass(PilotDemoHttpExecutionTest::class))->getConstant('PASSWORD');
        $a=new PilotHttpClient(8775);$b=new PilotHttpClient(8776);$servers=[];
        try{
            $servers[]=$a->startServer();$servers[]=$b->startServer();
            $login=$a->request('/__pilot-demo/login');$this->assertSame(200,$login['status']);$cookie=$login['headers']['set-cookie']??'';$this->assertStringContainsString('httponly',strtolower($cookie));$this->assertStringContainsString('samesite=strict',strtolower($cookie));
            $this->assertSame(419,$a->request('/__pilot-demo/confirm','POST',[])['status']);$this->assertSame(419,$a->request('/__pilot-demo/confirm','POST',['_token'=>'invalid'])['status']);
            $this->assertSame(302,$a->login($actor->email,$password));unset($password);
            $this->assertSame(422,$a->request('/__pilot-demo/confirm','POST',['_token'=>$a->token,'account_id'=>1])['status']);
            $this->assertSame(200,$a->request('/__pilot-demo/operations/'.$f['input']['uuid'])['status']);
            $foreignUuid=DB::table('accounting_event_operations')->where('id',131)->value('operation_uuid');$this->assertSame(403,$a->request('/__pilot-demo/operations/'.$foreignUuid)['status']);
            // Dos puertos del mismo ensayo comparten intencionadamente la sesión; no afirmar aislamiento por puerto.
            $b->cookie=$a->cookie;$this->assertSame(200,$b->request('/__pilot-demo')['status']);
            $anonymous=new PilotHttpClient(8776);$this->assertSame(302,$anonymous->request('/__pilot-demo')['status']);
            foreach(['/bancos','/.env','/tests/bootstrap.php','/vendor/autoload.php','/public/index.php','/__pilot-demo/refund'] as $path)$this->assertSame(404,$a->request($path)['status']);
            $this->assertSame(419,$a->request('/__pilot-demo/review','POST',[])['status']);
            $this->assertSame(302,$a->request('/__pilot-demo/review','POST',['_token'=>$a->token,'amount'=>'invalid'])['status']);
            $error=$a->request('/__pilot-demo');$this->assertStringContainsString('Revise los datos',$error['body']);$this->assertStringNotContainsString('SQLSTATE',$error['body']);
            $this->assertSame(302,$a->request('/__pilot-demo/logout','POST',['_token'=>$a->token])['status']);$this->assertSame(302,$a->request('/__pilot-demo')['status']);
        }finally{foreach($servers as $server){proc_terminate($server);$status=proc_get_status($server);proc_close($server);$this->assertFalse($status['running']);}}
    }
}
