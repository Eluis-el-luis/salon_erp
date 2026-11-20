<?php
namespace Tests\Support;

final class ReportDemoHttpClient
{
    public string $cookie='';public string $token='';
    public function __construct(public int $port=8778){}
    public function request(string $path,string $method='GET',array $data=[],?string $host=null): array
    {
        $headers="Content-Type: application/x-www-form-urlencoded\r\n".($this->cookie!==''?"Cookie: {$this->cookie}\r\n":'').($host?"Host: $host\r\n":'');
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='POST'?http_build_query($data):'',
            'ignore_errors'=>true,'follow_location'=>0,'timeout'=>15]]);
        $body=file_get_contents('http://127.0.0.1:'.$this->port.$path,false,$context);$raw=$http_response_header??[];preg_match('/\s(\d{3})\s/',$raw[0]??'',$m);$out=['status'=>(int)($m[1]??0),'body'=>$body,'headers'=>[]];
        foreach($raw as $header){if(preg_match('/^Set-Cookie: (report_demo_readonly_session=[^;]+)/i',$header,$cookie))$this->cookie=$cookie[1];
            $parts=explode(': ',$header,2);if(count($parts)===2)$out['headers'][strtolower($parts[0])]=$parts[1];}
        if(preg_match('/name="_token" value="([^"]+)"/',$body,$m))$this->token=html_entity_decode($m[1]);return $out;
    }
    public function login(string $email,string $password): array
    {
        $this->request('/__report-demo/login');return $this->request('/__report-demo/login','POST',['_token'=>$this->token,'email'=>$email,'password'=>$password]);
    }
    public function startServer(): mixed
    {
        $s=@stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$error,.2);if($s){fclose($s);throw new \RuntimeException('Report demo port occupied.');}
        $log=dirname(__DIR__,2).'/storage/app/private/ac05b3a-http-'.$this->port.'.log';
        $p=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1','-S','127.0.0.1:'.$this->port,
            '-t',dirname(__DIR__,2).'/public',__DIR__.'/report-demo-router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2));
        if(!is_resource($p))throw new \RuntimeException('Report server unavailable.');fclose($pipes[0]);
        for($i=0;$i<40;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.$this->port,$errno,$error,.2);if($s){fclose($s);return $p;}usleep(100000);}
        proc_terminate($p);proc_close($p);throw new \RuntimeException('Report server not ready.');
    }
}
