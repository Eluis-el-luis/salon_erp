<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Identidad documental de ensayo. No confirma cargos, movimientos o asientos. */
final class AccountingEventIdentityService
{
    private function reject(string $m): never {throw ValidationException::withMessages(['operation'=>$m]);}
    private function verifyDocument(object $r): void {
        $c=json_decode($r->content,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($c)||($c['version']??null)!==1||($c['event_contract']??null)!==1||!hash_equals($r->content_hash,hash('sha256',AccountingPolicyService::canonical($c))))$this->reject('Hash o contrato documental incompatible.');
        $columns=['event_key','bank_account_id','currency','purpose','environment','authorization_id','policy_version_id','policy_hash','created_by','classification','amount','recognition_date'];
        $keys=array_keys($c);$expected=array_merge($columns,['version','event_contract','reference','description']);sort($keys);sort($expected);if($keys!==$expected)$this->reject('Contenido incompleto.');
        foreach($columns as $key)if(!array_key_exists($key,$c)||(string)$c[$key]!==(string)$r->$key)$this->reject('Columnas y contenido incompatibles.');
        $this->normalize(['uuid'=>$r->operation_uuid,'classification'=>$c['classification'],'amount'=>$c['amount'],'recognition_date'=>$c['recognition_date'],'reference'=>$c['reference'],'description'=>$c['description']]);
    }
    public function normalize(array $input): array {
        if(array_diff(array_keys($input),['uuid','classification','amount','recognition_date','reference','description']))$this->reject('Campos autoritativos no admitidos.');
        $uuid=is_string($input['uuid']??null)?strtolower(trim($input['uuid'])):'';
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid))$this->reject('UUID inválido.');
        if(!in_array($input['classification']??null,['maintenance','commission'],true))$this->reject('Clasificación explícita requerida.');
        $amount=$input['amount']??null;if(!is_string($amount)||!preg_match('/^\d+(?:\.\d{1,2})?$/D',$amount))$this->reject('Importe decimal exacto requerido.');
        [$units,$fraction]=array_pad(explode('.',$amount),2,'');$units=ltrim($units,'0')?:'0';$amount=$units.'.'.str_pad($fraction,2,'0');
        if(strlen($units)>13||$amount==='0.00')$this->reject('Importe fuera de límites.');
        $date=$input['recognition_date']??null;$d=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if(!$d||$d->format('Y-m-d')!==$date||$date<'1000-01-01')$this->reject('Fecha contable inválida.');
        foreach(['reference'=>60,'description'=>150] as $key=>$max)if(!is_string($input[$key]??null)||trim($input[$key])===''||!mb_check_encoding($input[$key],'UTF-8')||mb_strlen(trim($input[$key]))>$max||preg_match('/[\x00-\x1f]/',$input[$key]))$this->reject('Referencia o descripción inválida.');
        return ['uuid'=>$uuid,'classification'=>$input['classification'],'amount'=>$amount,'recognition_date'=>$date,'reference'=>trim($input['reference']),'description'=>trim($input['description'])];
    }
    public function prepareSimulation(int $authorizationId,array $input,DateTimeImmutable $at): object {
        if(!app()->environment('testing'))abort(403);
        $n=$this->normalize($input);
        try {return DB::transaction(function()use($authorizationId,$n,$at){
            $a=app(AccountingEventAuthorizationService::class)->inspectSimulation($authorizationId,$at);$actor=(int)auth()->id();
            $content=['version'=>1,'event_contract'=>1,'event_key'=>$a->event_key,'bank_account_id'=>(int)$a->bank_account_id,'currency'=>$a->currency,
                'purpose'=>'simulation','environment'=>'testing','authorization_id'=>$authorizationId,'policy_version_id'=>(int)$a->policy_version_id,
                'policy_hash'=>$a->policy_hash,'created_by'=>$actor]+array_diff_key($n,['uuid'=>true]);
            $hash=hash('sha256',AccountingPolicyService::canonical($content));
            $old=DB::table('accounting_event_operations')->where('operation_uuid',$n['uuid'])->lockForUpdate()->first();
            if($old){if((int)$old->created_by!==$actor)abort(403);$this->verifyDocument($old);if($old->content_hash!==$hash||AccountingPolicyService::canonical(json_decode($old->content,true))!==AccountingPolicyService::canonical($content))$this->reject('Mismo UUID con contenido distinto o documento manipulado.');
                if($old->status!=='prepared'||$old->result_movement_id!==null||$old->result_asiento_id!==null)$this->reject('Estado no interpretable por el preparador.');return $old;}
            $id=DB::table('accounting_event_operations')->insertGetId(['operation_uuid'=>$n['uuid'],'content_hash'=>$hash,'content'=>AccountingPolicyService::canonical($content),
                'policy_version_id'=>$a->policy_version_id,'policy_hash'=>$a->policy_hash,'purpose'=>'simulation','event_key'=>$a->event_key,'bank_account_id'=>$a->bank_account_id,
                'currency'=>'NIO','environment'=>'testing','authorization_id'=>$authorizationId,'created_by'=>$actor,'classification'=>$n['classification'],'amount'=>$n['amount'],
                'recognition_date'=>$n['recognition_date'],'status'=>'prepared','recorded_at'=>now('UTC')->format('Y-m-d H:i:s')]);
            return DB::table('accounting_event_operations')->find($id);
        });} catch(\Illuminate\Database\QueryException $e) {
            if(in_array($e->errorInfo[1]??0,[1062,1205,1213],true))$this->reject('Identidad documental en competencia; consulte/revise antes de intentar otra solicitud.');throw $e;
        }
    }
    public function confirmedResult(string $uuid): object {
        $r=DB::table('accounting_event_operations')->where('operation_uuid',strtolower($uuid))->first();
        $u=auth()->id()?DB::table('users')->where('id',auth()->id())->first():null;if(!$u||!$u->is_active||!$r||((int)$r->created_by!==(int)$u->id&&!in_array($u->role,['alvaro','contador'],true)))abort(403);
        if($r->status!=='confirmed'||!$r->result_asiento_id||!$r->result_movement_id)$this->reject('Documento incompleto: no existe resultado financiero confirmado.');
        $rows=DB::table('accounting_entry_line_resolutions')->where('operation_id',$r->id)->get();
        if($rows->count()!==2||$rows->pluck('semantic_role')->sort()->values()->all()!==['fondos.banco','gasto.cargo_bancario'])$this->reject('Correspondencias incompletas.');
        $this->verifyDocument($r);
        return $r;
    }
    public function confirm(): never {throw new \LogicException('AC-04B1 no permite confirmar operaciones físicas o financieras.');}
}
