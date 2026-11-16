<?php
namespace Tests\Feature;

use Tests\TestCase;

/** HTTP real por loopback, sin credenciales ni escrituras SQL. */
final class PilotDemoHttpBoundaryTest extends TestCase
{
    public function test_testing_environment_alone_does_not_open_demo_routes(): void
    {
        $this->assertFalse(defined('SALON_PILOT_DEMO_READONLY'));
        $this->get('/__pilot-demo/login')->assertNotFound();
        $this->get('/__pilot-demo')->assertNotFound();
    }

    public function test_real_server_session_csrf_and_closed_financial_endpoints(): void
    {
        $socket=@stream_socket_client('tcp://127.0.0.1:8774',$errno,$error,0.2);
        if($socket){fclose($socket);$this->fail('Puerto 8774 ocupado; no se toca el proceso existente.');}
        $root=base_path();$log=storage_path('app/private/pilot-demo-http-test.log');
        $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:8774','-t',$root.'/public',$root.'/tests/Support/pilot-demo-readonly-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
        $this->assertIsResource($process);fclose($pipes[0]);
        try {
            $ready=false;
            for($i=0;$i<40;$i++){$s=@stream_socket_client('tcp://127.0.0.1:8774',$errno,$error,0.2);if($s){fclose($s);$ready=true;break;}usleep(100000);}
            $this->assertTrue($ready,'Servidor no disponible');
            $request=function(string $path,string $method='GET',string $body='',string $cookie=''): array {
                $headers="Content-Type: application/x-www-form-urlencoded\r\n".($cookie!==''?"Cookie: $cookie\r\n":'');
                $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
                $result=file_get_contents('http://127.0.0.1:8774'.$path,false,$context);$h=$http_response_header??[];
                preg_match('/\s(\d{3})\s/',$h[0]??'',$m);return [(int)($m[1]??0),$result,$h];
            };
            [$code,$html,$headers]=$request('/__pilot-demo/login');$this->assertSame(200,$code);$this->assertStringContainsString('INFORMACIÓN SINTÉTICA',$html);
            $cookie='';foreach($headers as $h)if(preg_match('/^Set-Cookie: (pilot_demo_readonly_session=[^;]+)/i',$h,$m))$cookie=$m[1];
            $this->assertNotSame('',$cookie);preg_match('/name="_token" value="([^"]+)"/',$html,$m);$this->assertNotEmpty($m[1]??'');
            $this->assertSame(419,$request('/__pilot-demo/login','POST','email=nobody%40example.test&password=synthetic-invalid',$cookie)[0]);
            $this->assertSame(302,$request('/__pilot-demo/login','POST',http_build_query(['_token'=>$m[1],'email'=>'nobody@example.test','password'=>'synthetic-invalid']),$cookie)[0]);
            $this->assertSame(302,$request('/__pilot-demo')[0]);
            foreach(['/bancos','/.env','/__pilot-demo/confirm','/__pilot-demo/refund'] as $path)$this->assertSame(404,$request($path,str_contains($path,'confirm')||str_contains($path,'refund')?'POST':'GET')[0]);
            $this->assertSame(200,$request('/__pilot-demo/assets/app.css')[0]);
        } finally {
            proc_terminate($process);$status=proc_get_status($process);proc_close($process);
            $this->assertFalse($status['running'],'El servidor hijo debe finalizar limpiamente.');
        }
    }
}
