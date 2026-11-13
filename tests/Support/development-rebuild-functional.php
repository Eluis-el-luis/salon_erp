<?php

class DevelopmentRebuildHttp
{
    private \CurlHandle $curl;
    public function __construct()
    {
        $this->curl=curl_init();
        curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_PROXY=>'',CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>5]);
    }
    public function request(string $method,string $path,array $data=[],?string $token=null,bool $json=true): array
    {
        if(!str_starts_with($path,'/') || str_contains($path,'://')) throw new RuntimeException('Unexpected URL');
        $headers=['Accept: '.($json?'application/json':'text/html'),'X-H13D-Check: '.dr_read('closed')['http_nonce']];
        if($token!==null) $headers[]='X-CSRF-TOKEN: '.$token;
        curl_setopt($this->curl,CURLOPT_URL,'http://127.0.0.1:8767'.$path);
        if($method==='GET') curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        else { $headers[]='Content-Type: application/x-www-form-urlencoded'; curl_setopt($this->curl,CURLOPT_POSTFIELDS,http_build_query($data)); }
        curl_setopt($this->curl,CURLOPT_CUSTOMREQUEST,$method); curl_setopt($this->curl,CURLOPT_HTTPHEADER,$headers);
        $body=curl_exec($this->curl); if($body===false) throw new RuntimeException('Loopback HTTP failed');
        return ['status'=>curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'redirect'=>curl_getinfo($this->curl,CURLINFO_REDIRECT_URL),'body'=>$body,'json'=>json_decode($body,true)];
    }
    public function login(string $email,string $password): void
    {
        $page=$this->request('GET','/login',[],null,false);
        dr_assert($page['status']===200 && preg_match('/name="_token"\s+value="([^"]+)"/',$page['body'],$m)===1,'HTTP login form and CSRF token');
        $r=$this->request('POST','/login',['email'=>$email,'password'=>$password,'_token'=>html_entity_decode($m[1])],null,false);
        dr_assert($r['status']===302 && !str_ends_with($r['redirect'],'/login'),'real login redirects to authorized page');
    }
    public function token(): string
    {
        $page=$this->request('GET','/agenda',[],null,false);
        dr_assert($page['status']===200 && preg_match('/name="csrf-token"\s+content="([^"]+)"/',$page['body'],$m)===1,'authenticated agenda and session');
        return html_entity_decode($m[1]);
    }
}

function dr_functional($app,$owner): void
{
    $q=new \Symfony\Component\Console\Question\Question('Reintroduce la contraseña DEL PROPIETARIO DE DESARROLLO para comprobar login HTTP: ');
    $q->setHidden(true)->setHiddenFallback(false);
    $password=(new \Symfony\Component\Console\Helper\QuestionHelper())->ask(new \Symfony\Component\Console\Input\ArgvInput(),new \Symfony\Component\Console\Output\ConsoleOutput(),$q);
    dr_assert(is_string($password) && \Illuminate\Support\Facades\Hash::check($password,$owner->password),'owner login matches stored hash');
    $client=new DevelopmentRebuildHttp(); $client->login($owner->email,$password); unset($password);
    $token=$client->token();
    dr_assert($client->request('GET','/api/clients')['status']===200,'owner clients session authorized');
    dr_assert($client->request('GET','/inventario',[],null,false)['status']===200,'product catalog HTTP 200');
    dr_assert($client->request('GET','/servicios',[],null,false)['status']===200,'service catalog HTTP 200');
    $anonymous=new DevelopmentRebuildHttp();
    dr_assert($anonymous->request('GET','/api/clients')['status']===401,'anonymous client data rejected');
    dr_assert($anonymous->request('GET','/api/appointments')['status']===401,'anonymous appointments rejected');
    dr_assert(\App\Models\Cita::count()===0 && \App\Models\Cliente::count()===0,'no historical clients or appointments');
    $marker=fopen(dr_file('functional-started'),'x'); if(!$marker) throw new RuntimeException('Functional writes already attempted'); fwrite($marker,json_encode(['at'=>gmdate('c')])); fclose($marker);
    $service=\App\Models\Servicio::create(['name'=>'Servicio de comprobación H13-D','price'=>200,'duration'=>45,'is_active'=>true]);
    dr_assert(!\App\Models\Articulo::whereKey($service->id)->exists(),'H13 service exists only in services');
    dr_assert($client->request('POST','/api/clients',['name'=>'Cliente de comprobación H13-D'],$token)['status']===200,'authorized verification client created');
    $customer=\App\Models\Cliente::where('name','Cliente de comprobación H13-D')->sole();
    $stylist=\App\Models\Usuario::where('role','estilista')->sole();
    $day=now(config('app.timezone'))->addDay()->toDateString();
    $payload=['client_id'=>$customer->id,'service_id'=>$service->id,'stylists'=>[$stylist->id],'appointment_date'=>$day.'T10:00','duration_minutes'=>45,'notes'=>'Comprobación H13-D, sin facturación'];
    $page=$anonymous->request('GET','/login',[],null,false);
    dr_assert(preg_match('/name="_token"\s+value="([^"]+)"/',$page['body'],$m)===1,'anonymous session token');
    dr_assert($anonymous->request('POST','/api/appointments',$payload,html_entity_decode($m[1]))['status']===401,'anonymous creation rejected with valid CSRF');
    dr_assert($client->request('POST','/api/appointments',$payload)['status']===419,'real missing CSRF rejected');
    dr_assert($client->request('POST','/api/appointments',$payload,'invalid-token')['status']===419,'real invalid CSRF rejected');
    dr_assert(\App\Models\Cita::count()===0,'rejections created no appointments');
    $first=$client->request('POST','/api/appointments',$payload,$token);
    dr_assert($first['status']===201 && is_int($first['json']['appointment']['id']??null),'first appointment real ID confirmed');
    $payload['appointment_date']=$day.'T12:00';
    $second=$client->request('POST','/api/appointments',$payload,$token);
    dr_assert($second['status']===201 && ($second['json']['appointment']['appointment_date']??'')===$day.' 12:00:00','second same-stylist appointment saved at local time');
    $future=$client->request('GET','/api/appointments?fecha='.$day);
    dr_assert($future['status']===200 && count($future['json'])===2,'selected future date lists both appointments');
    $today=$client->request('GET','/api/appointments');
    dr_assert($today['status']===200 && count($today['json'])===0,'default today excludes future appointments');
    $html=$client->request('GET','/agenda?fecha='.$day,[],null,false);
    dr_assert($html['status']===200 && str_contains($html['body'],\Carbon\Carbon::parse($day)->format('d/m/Y')),'HTML agenda date navigation');
    dr_assert($client->request('GET','/api/appointments?fecha=2026-02-30')['status']===422,'invalid date rejected');
    $account=$app['db']->table('cuentas_contables')->where('codigo','3.3')->first();
    dr_assert($client->request('DELETE','/catalogo/'.$account->id,[],$token)['status']===403,'base account protected against deletion');
    $oldHash=$stylist->password; $temporary=bin2hex(random_bytes(24));
    try {
        $stylist->password=$temporary; $stylist->save();
        $employee=new DevelopmentRebuildHttp(); $employee->login($stylist->email,$temporary);
        dr_assert($employee->request('GET','/agenda',[],null,false)['status']===200,'stylist agenda authorized');
        dr_assert($employee->request('GET','/catalogo')['status']===403,'stylist accounting denied');
    } finally { $stylist->password=$oldHash; $stylist->save(); unset($oldHash,$temporary); }
    dr_schema($app); dr_no_financial($app);
    dr_record('functional',['passed'=>true,'date'=>$day,'appointment_ids'=>[$first['json']['appointment']['id'],$second['json']['appointment']['id']],'service_id'=>$service->id,'client_id'=>$customer->id,'financial_operations'=>0,'real_http_auth_session_csrf_roles'=>true]);
}
