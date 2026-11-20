<?php
namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportDemoHttpClient;
use Tests\TestCase;

/** HTTP read-only tests. No financial fixtures or mutable reuse of AC-04 evidence. */
class ReportPresentationHttpTest extends TestCase
{
    private const PASSWORD='SYNTHETIC-AC04B2B4B-LOCAL-TEST-ONLY';
    private function input(array $extra=[]): array
    {
        return array_replace(['source'=>'offline','case'=>'overview','report'=>'result','month'=>'2030-10','until'=>'2030-10-31',
            'states'=>'all','null_origin'=>'include','period'=>'all'],$extra);
    }
    public function test_real_session_csrf_loopback_routes_and_readonly_reports_preserve_sql_history(): void
    {
        $f=json_decode(file_get_contents(storage_path('app/private/ac04b2b4b-http-fixture.json')),true,512,JSON_THROW_ON_ERROR);
        $actor=Usuario::findOrFail($f['actor']);$this->assertTrue((bool)$actor->is_active);$this->assertSame('contador',$actor->role);
        $tables=['users','asientos_contables','detalle_asientos','cuentas_contables','accounting_policy_authorizations','accounting_event_operations','cuentas_bancarias','conciliaciones_bancarias'];
        $before=[];foreach($tables as $t)$before[$t]=DB::table($t)->orderBy('id')->get()->toJson();
        $client=new ReportDemoHttpClient;$wrong=new ReportDemoHttpClient(8779);$server=$client->startServer();$other=null;
        try{
            $guest=$client->request('/__report-demo/login');$this->assertSame(200,$guest['status']);
            $this->assertTrue(str_contains(strtolower($guest['headers']['set-cookie']),'httponly'));
            $this->assertTrue(str_contains(strtolower($guest['headers']['set-cookie']),'samesite=strict'));
            $this->assertTrue(str_contains($guest['headers']['set-cookie'],'path=/__report-demo'));
            $this->assertSame(419,$client->request('/__report-demo/login','POST',['email'=>$actor->email,'password'=>self::PASSWORD])['status']);
            $this->assertSame(419,$client->request('/__report-demo/compare','POST',$this->input()+['_token'=>'invalid'])['status']);
            $this->assertSame(403,$client->request('/__report-demo/compare','POST',$this->input()+['_token'=>$client->token])['status']);
            $denied=new ReportDemoHttpClient;$this->assertSame(403,$denied->login('missing@example.test','invalid')['status']);
            $this->assertSame(302,$client->login($actor->email,self::PASSWORD)['status']);$client->request('/__report-demo');
            $this->assertStringNotContainsString('pilot_demo',$client->cookie);
            foreach(['result','balance'] as $report){
                $page=$client->request('/__report-demo/compare','POST',$this->input(['report'=>$report])+['_token'=>$client->token]);
                $this->assertSame(200,$page['status']);$this->assertStringContainsString('NO extraídos de MySQL',$page['body']);
                $this->assertStringContainsString('No disponibles para offline',$page['body']);$this->assertStringContainsString('-10.00',$page['body']);
                $this->assertStringContainsString('&lt;b&gt;etiqueta literal&lt;/b&gt;',$page['body']);
                $this->assertStringNotContainsString('<b>etiqueta literal</b>',$page['body']);
            }
            $this->assertSame(200,$client->request('/__report-demo/compare','POST',$this->input(['case'=>'closing','report'=>'balance'])+['_token'=>$client->token])['status']);
            $incomplete=$client->request('/__report-demo/compare','POST',$this->input(['case'=>'incomplete'])+['_token'=>$client->token]);
            $this->assertSame(200,$incomplete['status']);$this->assertStringContainsString('Cobertura incompleta',$incomplete['body']);
            foreach([['account_id'=>1],['month'=>'2030-11'],['report'=>'balance','period'=>'fixture1'],['report'=>'result','until'=>'2030-10-20'],['source'=>'guessed'],['month'=>['2030-10']],['states'=>['all']]] as $bad)
                $this->assertSame(422,$client->request('/__report-demo/compare','POST',$this->input($bad)+['_token'=>$client->token])['status']);
            $live=$client->request('/__report-demo/compare','POST',$this->input(['source'=>'mysql','report'=>'balance','until'=>'2036-12-31'])+['_token'=>$client->token]);
            $this->assertSame(200,$live['status']);$this->assertStringContainsString('MYSQL TEST',$live['body']);
            $this->assertStringContainsString('Cobertura incompleta',$live['body']);$this->assertStringContainsString('READ ONLY',$live['body']);
            foreach(['/bancos/cargo','/__pilot-demo','/.env','/AGENTS.md','/app/Services/ContabilidadService.php','/tests/bootstrap.php'] as $path)$this->assertSame(404,$client->request($path)['status']);
            $this->assertSame(404,$client->request('/__report-demo/login','GET',[],'localhost:8778')['status']);
            $other=$wrong->startServer();$this->assertSame(404,$wrong->request('/__report-demo/login','GET',[],'127.0.0.1:8778')['status']);
            $this->assertSame(419,(new ReportDemoHttpClient)->request('/__report-demo/compare','POST',$this->input()+['_token'=>$client->token])['status']);
            $this->assertSame(302,$client->request('/__report-demo/logout','POST',['_token'=>$client->token])['status']);
            $this->assertSame(419,$client->request('/__report-demo/compare','POST',$this->input()+['_token'=>'expired'])['status']);
            foreach($before as $t=>$rows)$this->assertSame($rows,DB::table($t)->orderBy('id')->get()->toJson());
        }finally{
            foreach([$server,$other] as $p)if(is_resource($p)){proc_terminate($p);proc_close($p);}
        }
    }
}
