<?php
namespace Tests\Support;

/** Cliente del arnés. Nunca usado por la aplicación ni para puentear HTTP a CLI. */
final class PilotHttpClient
{
    public string $cookie='';
    public string $token='';
    public function __construct(public int $port) {}
    public function request(string $path,string $method='GET',array $data=[]): array {
        $headers="Content-Type: application/x-www-form-urlencoded\r\n".($this->cookie!==''?"Cookie: {$this->cookie}\r\n":'');
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='POST'?http_build_query($data):'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>25]]);
        $body=file_get_contents('http://127.0.0.1:'.$this->port.$path,false,$context);$h=$http_response_header??[];preg_match('/\s(\d{3})\s/',$h[0]??'',$m);$result=['status'=>(int)($m[1]??0),'body'=>$body,'headers'=>[]];
        foreach($h as $header){if(preg_match('/^Set-Cookie: (pilot_demo_http_synthetic_session=[^;]+)/i',$header,$c))$this->cookie=$c[1];$parts=explode(': ',$header,2);if(count($parts)===2)$result['headers'][strtolower($parts[0])]=$parts[1];}
        if(preg_match('/name="_token" value="([^"]+)"/',$body,$m))$this->token=html_entity_decode($m[1]);
        return $result;
    }
    public function login(string $email,string $password): int {
        $this->request('/__pilot-demo/login');$r=$this->request('/__pilot-demo/login','POST',['_token'=>$this->token,'email'=>$email,'password'=>$password]);$this->request('/__pilot-demo');return $r['status'];
    }
    public function confirm(array $payload): array {return $this->request('/__pilot-demo/confirm','POST',$payload+['_token'=>$this->token]);}
    public function startServer(): mixed {
        $s=@stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$error,0.2);if($s){fclose($s);throw new \RuntimeException('Puerto de ensayo ocupado.');}
        $log=dirname(__DIR__,2).'/storage/app/private/ac04b2b4b-http-'.$this->port.'.log';
        $p=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:'.$this->port,'-t',dirname(__DIR__,2).'/public',__DIR__.'/pilot-demo-http-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2));
        if(!is_resource($p))throw new \RuntimeException('No se inició el servidor.');fclose($pipes[0]);
        for($i=0;$i<40;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$error,0.2);if($s){fclose($s);return $p;}usleep(100000);}
        proc_terminate($p);proc_close($p);throw new \RuntimeException('Servidor no disponible.');
    }
}
