<?php

namespace App\Services;

use App\Models\MovimientoBancario;
use App\Support\AccountingSimulationBoundary;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Solo acreditación completa sintética. No rutas ni autoridad económica empresarial. */
final class BankChargeRefundSimulation
{
    public const CONTRACT='AC04B2B3A_CREDITED_REFUND_SIMULATION_V1';
    public function guard(string $contract): void {
        if($contract!==self::CONTRACT)throw new \LogicException('Contrato de devolución no autorizado.');
        AccountingSimulationBoundary::assert(AccountingSimulationBoundary::CONTRACT);
    }
    private function reject(string $m): never {throw ValidationException::withMessages(['refund'=>$m]);}
    private function canonical(array $a): string {return AccountingPolicyService::canonical($a);}
    public function normalize(array $input): array {
        $allowed=['uuid','reason_code','bank_reference','authorization_reference','evidence_description','effective_date','recognition_date'];
        if(array_diff(array_keys($input),$allowed)||array_diff($allowed,array_keys($input)))$this->reject('Contrato incompleto o campos autoritativos no admitidos.');
        $uuid=is_string($input['uuid'])?strtolower(trim($input['uuid'])):'';
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$uuid)||$input['reason_code']!=='credited_full_refund')$this->reject('Solo devolución completa acreditada, con UUID propio.');
        foreach(['bank_reference'=>60,'authorization_reference'=>160,'evidence_description'=>1000] as $k=>$max){$v=$input[$k];if(!is_string($v)||trim($v)===''||!mb_check_encoding($v,'UTF-8')||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x1f]/',$v))$this->reject('Comprobante y descripción sintéticos explícitos requeridos.');$input[$k]=trim($v);}
        foreach(['bank_reference','authorization_reference'] as $k)if(!str_starts_with($input[$k],'SYNTHETIC-'))$this->reject('Referencia de ensayo SYNTHETIC- requerida.');
        foreach(['effective_date','recognition_date'] as $k){$s=$input[$k];$d=is_string($s)?DateTimeImmutable::createFromFormat('!Y-m-d',$s):false;if(!$d||$d->format('Y-m-d')!==$s||$s>now()->toDateString())$this->reject('Fecha documental inválida o futura.');}
        if($input['effective_date']!==$input['recognition_date'])$this->reject('Fechas efectivas/contables distintas quedan fuera de este ensayo.');
        $input['uuid']=$uuid;return $input;
    }
    private function historicalAuthorization(object $o): void {
        $a=DB::table('accounting_policy_authorizations')->where('id',$o->authorization_id)->sharedLock()->first();
        foreach([$a,$a?DB::table('accounting_policy_authorizations')->where('id',$a->parent_id)->sharedLock()->first():null] as $index=>$row){
            if(!$row)$this->reject('Autoridad histórica incompleta.');$e=json_decode($row->evidence,true,512,JSON_THROW_ON_ERROR);
            $required=['policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment','valid_from','valid_until','action','parent_id','parent_action','actor_id','document_reference','reason','recorded_at'];
            $keys=array_keys($e['authorization']??[]);sort($required);sort($keys);
            if(($e['version']??null)!==1||$keys!==$required||hash('sha256',$this->canonical($e))!==$row->evidence_hash||$row->action!==($index===0?'enablement':'approval'))$this->reject('Evidencia de autorización original incompatible.');
            foreach($e['authorization'] as $k=>$v)if(!property_exists($row,$k)||($v===null?$row->$k!==null:(string)$row->$k!==(string)$v))$this->reject('Autorización histórica manipulada.');
            foreach(['policy_version_id','policy_hash','event_key','bank_account_id','currency','purpose','environment'] as $k)if((string)$row->$k!==(string)$o->$k)$this->reject('Alcance histórico cruzado.');
        }
        // No exigir vigencia o ausencia de retirada AHORA: esto es procedencia histórica.
    }
    /** Versión por PK antes de banco; después documentos actuales. Nunca resuelve cuentas vigentes. */
    private function original(int $id): array {
        if(DB::transactionLevel()===0)throw new \LogicException('Origen requiere transacción.');
        $seed=DB::table('accounting_event_operations')->find($id);if(!$seed)$this->reject('Original inexistente; no se admiten movimientos legacy por coincidencia.');
        $v=DB::table('accounting_policy_versions')->where('id',$seed->policy_version_id)->sharedLock()->first();
        if(!$v)$this->reject('Política original ausente.');
        $bank=app(BankBalanceService::class)->lockAccounts([(int)$seed->bank_account_id])->get((int)$seed->bank_account_id);
        $o=DB::table('accounting_event_operations')->where('id',$id)->lockForUpdate()->first();app(AccountingEventIdentityService::class)->verifyDocument($o);
        if($o->status!=='confirmed'||$o->purpose!=='simulation'||$o->environment!=='testing'||$o->event_key!==AccountingEventAuthorizationService::EVENT||!in_array($o->classification,['maintenance','commission'],true)||$o->currency!=='NIO'||(int)$o->bank_account_id!==$bank->id||(int)$o->policy_version_id!==(int)$v->id)$this->reject('Original ajeno al cargo simulado confirmado.');
        $content=app(AccountingPolicyService::class)->contentForInspection($v);
        if($v->status!=='published'||$v->kind!=='enterprise'||(int)$v->contract_version!==1||$v->content_hash!==$o->policy_hash||hash('sha256',$this->canonical($content))!==$o->policy_hash)$this->reject('Política histórica manipulada.');
        $audit=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->orderByDesc('event_sequence')->sharedLock()->first();$publication=$audit?json_decode($audit->evidence,true):[];
        if(!$audit||$audit->event!=='published'||$audit->after_hash!==$o->policy_hash||(int)$audit->actor_id!==(int)$v->approved_by||$this->canonical($publication['after']??[])!==$this->canonical($content))$this->reject('Publicación histórica incompleta.');
        foreach($content['bindings'] as $binding)if($binding['scope_type']==='item'||($binding['scope_type']==='bank_account'&&(int)$binding['resource_id']!==$bank->id))$this->reject('Original con recursos fuera del piloto delimitado.');
        $this->historicalAuthorization($o);
        $m=DB::table('movimientos_bancarios')->where('id',$o->result_movement_id)->lockForUpdate()->first();$e=DB::table('asientos_contables')->where('id',$o->result_asiento_id)->lockForUpdate()->first();
        $lines=DB::table('detalle_asientos')->where('asiento_id',$o->result_asiento_id)->orderBy('id')->lockForUpdate()->get();$res=DB::table('accounting_entry_line_resolutions')->where('operation_id',$o->id)->orderBy('id')->lockForUpdate()->get();$nio=DB::table('monedas')->where('codigo','NIO')->value('id');
        $c=json_decode($o->content,true);
        if(!$m||!$e||$e->estado!=='contabilizado'||$m->tipo!=='cargo'||(int)$m->cuenta_bancaria_id!==$bank->id||(int)$bank->moneda_id!==(int)$nio||(int)$m->moneda_id!==(int)$nio||$m->monto!==$o->amount||$m->fecha!==$o->recognition_date||$m->referencia_banco!==$c['reference']||(int)$m->asiento_contable_id!==(int)$e->id||$e->modulo_origen!=='cargos_bancarios'||(int)$e->referencia_id!==(int)$m->id||$e->fecha!==$o->recognition_date||(int)$e->accounting_policy_version_id!==(int)$v->id||$e->accounting_policy_hash!==$o->policy_hash||(int)$e->accounting_authorization_id!==(int)$o->authorization_id||$lines->count()!==2||$res->count()!==2)$this->reject('Documento original sin cobertura financiera completa.');
        if($res->pluck('semantic_role')->sort()->values()->all()!==['fondos.banco','gasto.cargo_bancario'])$this->reject('Roles históricos incompatibles.');
        foreach($res as $r){$l=$lines->firstWhere('id',$r->detail_id);$expense=$r->semantic_role==='gasto.cargo_bancario';
            if(!$l||(int)$l->cuenta_id!==(int)$r->account_id||(int)$l->moneda_id!==(int)$nio||($expense?$l->debe:$l->haber)!==$o->amount||(float)($expense?$l->haber:$l->debe)!==0.0||(!$expense&&(int)$l->cuenta_id!==(int)$bank->cuenta_contable_id))$this->reject('Líneas o vínculo del banco distintos del original.');}
        if(DB::table('asientos_contables')->where('modulo_origen','reversion')->where('referencia_id',$e->id)->exists())$this->reject('Original con reversión previa ajena al contrato.');
        return compact('o','bank','m','e','lines','res');
    }
    private function content(object $o,array $n): array {
        return ['version'=>1,'contract'=>self::CONTRACT,'purpose'=>'simulation','created_by'=>(int)auth()->id(),'original_operation_id'=>(int)$o->id,
            'original_movement_id'=>(int)$o->result_movement_id,'original_asiento_id'=>(int)$o->result_asiento_id,'policy_version_id'=>(int)$o->policy_version_id,'policy_hash'=>$o->policy_hash,
            'authorization_id'=>(int)$o->authorization_id,'bank_account_id'=>(int)$o->bank_account_id,'amount'=>$o->amount,'currency'=>'NIO','input'=>array_diff_key($n,['uuid'=>true])];
    }
    private function verifyDocument(object $d): array {
        $c=json_decode($d->content,true,512,JSON_THROW_ON_ERROR);$e=json_decode($d->evidence,true,512,JSON_THROW_ON_ERROR);
        if(($c['version']??null)!==1||($c['contract']??null)!==self::CONTRACT||hash('sha256',$this->canonical($c))!==$d->content_hash||hash('sha256',$this->canonical($e))!==$d->evidence_hash||($e['version']??null)!==1||($e['synthetic']??null)!==true||($e['economic_authority']??null)!==false||($e['actor_id']??null)!==(int)$d->created_by||($e['recorded_at']??null)!==$d->recorded_at||($e['content_hash']??null)!==$d->content_hash)$this->reject('Evidencia propia de devolución incompatible.');
        if(($e['contract']??null)!==self::CONTRACT||($e['authorization_reference']??null)!==($c['input']['authorization_reference']??null)||($e['bank_reference']??null)!==($c['input']['bank_reference']??null)||($e['description']??null)!==($c['input']['evidence_description']??null))$this->reject('La acreditación no corresponde al contenido.');
        foreach(['purpose','created_by','original_operation_id','original_movement_id','original_asiento_id','policy_version_id','policy_hash','authorization_id','bank_account_id','amount','currency'] as $k)if(!isset($c[$k])||(string)$c[$k]!==(string)$d->$k)$this->reject('Contenido y columnas de devolución incompatibles.');
        foreach(['effective_date','recognition_date','reason_code'] as $k)if(($c['input'][$k]??null)!==$d->$k)$this->reject('Fechas/motivo de devolución manipulados.');return $c;
    }
    public function confirmedResult(string $uuid,string $contract): object {
        $this->guard($contract);$q=DB::table('bank_charge_reversals')->where('operation_uuid',strtolower($uuid));$d=(DB::transactionLevel()>0?$q->sharedLock():$q)->first();
        if(!$d||(int)$d->created_by!==(int)auth()->id())abort(403);$this->verifyDocument($d);
        if($d->status!=='confirmed'||!$d->result_movement_id||!$d->result_asiento_id)$this->reject('No hay devolución confirmada íntegra.');
        $current=fn($q)=>DB::transactionLevel()>0?$q->sharedLock():$q;
        $links=$current(DB::table('bank_charge_reversal_lines')->where('reversal_id',$d->id))->get();$m=$current(DB::table('movimientos_bancarios')->where('id',$d->result_movement_id))->first();$entry=$current(DB::table('asientos_contables')->where('id',$d->result_asiento_id))->first();
        if($links->count()!==2||!$m||!$entry||$m->tipo!=='abono'||$m->modulo_origen!=='bank_charge_refunds'||(int)$m->referencia_id!==(int)$d->id||(int)$m->asiento_contable_id!==(int)$d->result_asiento_id||$m->monto!==$d->amount||$m->fecha!==$d->effective_date||$entry->fecha!==$d->recognition_date||$entry->modulo_origen!=='bank_charge_refunds'||(int)$entry->referencia_id!==(int)$d->id)$this->reject('Resultado inverso incompleto.');
        foreach($links as $link){$a=$current(DB::table('detalle_asientos')->where('id',$link->original_detail_id))->first();$b=$current(DB::table('detalle_asientos')->where('id',$link->reversal_detail_id))->first();
            if(!$a||!$b||(int)$a->cuenta_id!==(int)$b->cuenta_id||(int)$a->moneda_id!==(int)$b->moneda_id||(int)$link->currency_id!==(int)$a->moneda_id||$a->centro_costo_id!==$b->centro_costo_id||$a->tasa_cambio_id!==$b->tasa_cambio_id||$a->debe!==$b->haber||$a->haber!==$b->debe||$link->original_debe!==$a->debe||$link->original_haber!==$a->haber||$link->reversal_debe!==$b->debe||$link->reversal_haber!==$b->haber)$this->reject('Línea inversa distinta de su original.');}
        return $d;
    }
    public function validateForPosting(int $id,object $movement,string $contract): array {
        $this->guard($contract);if(DB::transactionLevel()===0)throw new \LogicException('Requiere transacción exterior.');$seed=DB::table('bank_charge_reversals')->find($id);if(!$seed)$this->reject('Preparado inexistente.');
        $s=$this->original((int)$seed->original_operation_id);$d=DB::table('bank_charge_reversals')->where('id',$id)->lockForUpdate()->first();$this->verifyDocument($d);
        if($d->status!=='prepared'||$d->purpose!=='simulation'||$d->amount!==$s['o']->amount||(int)$d->created_by!==(int)auth()->id()||$movement->tipo!=='abono'||(int)$movement->cuenta_bancaria_id!==$s['bank']->id||(int)$movement->moneda_id!==(int)$s['bank']->moneda_id||(string)$movement->monto!==$d->amount||$movement->modulo_origen!=='bank_charge_refunds'||(int)$movement->referencia_id!==$id||\Carbon\Carbon::parse($movement->fecha)->toDateString()!==$d->effective_date)$this->reject('Movimiento inverso no autorizado por el documento.');
        return $s+['document'=>$d];
    }
    public function execute(int $originalId,array $input,string $contract): object {
        $this->guard($contract);$n=$this->normalize($input);$existing=DB::table('bank_charge_reversals')->where('operation_uuid',$n['uuid'])->first();
        $same=function($d)use($n,$originalId){$c=$this->verifyDocument($d);if((int)$d->created_by!==(int)auth()->id()||(int)$d->original_operation_id!==$originalId||$this->canonical($c['input'])!==$this->canonical(array_diff_key($n,['uuid'=>true])))$this->reject('UUID con otro contenido, original o actor.');};
        if($existing){$same($existing);return $this->confirmedResult($n['uuid'],$contract);}
        $level=DB::transactionLevel();
        try{return DB::transaction(function()use($originalId,$n,$contract,$same){
            $s=$this->original($originalId);$o=$s['o'];$bank=$s['bank'];$date=$n['recognition_date'];
            $last=app(FondosService::class)->ultimaConciliacionCerrada($bank,true);
            if($date<$o->recognition_date||($last&&$date<=$last->fecha->toDateString()))$this->reject('La devolución no puede retroceder al cargo ni a un extracto cerrado.');
            $periods=DB::table('periodos_contables')->where('fecha_inicio','<=',$date)->where('fecha_fin','>=',$date)->orderBy('id')->sharedLock()->get();$oldPeriod=DB::table('periodos_contables')->where('id',$s['e']->periodo_id)->sharedLock()->first();
            if($periods->count()!==1||$periods[0]->estado!=='abierto'||!$oldPeriod||($oldPeriod->estado!=='abierto'&&$date<=$oldPeriod->fecha_fin))$this->reject('Período destino abierto y posterior verificable requerido.');
            foreach($s['lines']->pluck('cuenta_id')->unique()->sort() as $account)app(AccountIntegrityService::class)->account((int)$account,true);
            $c=$this->content($o,$n);$hash=hash('sha256',$this->canonical($c));$at=now('UTC')->format('Y-m-d H:i:s');$e=['version'=>1,'synthetic'=>true,'economic_authority'=>false,'contract'=>self::CONTRACT,'actor_id'=>(int)auth()->id(),'authorization_reference'=>$n['authorization_reference'],'bank_reference'=>$n['bank_reference'],'description'=>$n['evidence_description'],'recorded_at'=>$at,'content_hash'=>$hash];
            $row=array_diff_key($c,['version'=>true,'contract'=>true,'input'=>true])+['operation_uuid'=>$n['uuid'],'reason_code'=>$n['reason_code'],'effective_date'=>$n['effective_date'],'recognition_date'=>$date,'content'=>$this->canonical($c),'content_hash'=>$hash,'evidence'=>$this->canonical($e),'evidence_hash'=>hash('sha256',$this->canonical($e)),'recorded_at'=>$at,'status'=>'prepared'];
            try{$id=DB::table('bank_charge_reversals')->insertGetId($row);}catch(QueryException $ex){if(($ex->errorInfo[1]??0)!==1062)throw $ex;$d=DB::table('bank_charge_reversals')->where('operation_uuid',$n['uuid'])->lockForUpdate()->first();if(!$d)$this->reject('Original ya cubierto por otra devolución completa.');$same($d);return $this->confirmedResult($n['uuid'],$contract);}
            $m=MovimientoBancario::create(['cuenta_bancaria_id'=>$bank->id,'moneda_id'=>$bank->moneda_id,'tipo'=>'abono','monto'=>$o->amount,'fecha'=>$n['effective_date'],'concepto'=>'SYNTHETIC devolución bancaria completa','modulo_origen'=>'bank_charge_refunds','referencia_id'=>$id,'referencia_banco'=>$n['bank_reference'],'estado_conciliacion'=>'pendiente']);$m->refresh();
            $entry=app(ContabilidadService::class)->reversarCargoBancarioSimulado($id,$m,$contract);$m->asiento_contable_id=$entry;$m->save();
            DB::table('bank_charge_reversals')->where('id',$id)->update(['status'=>'confirmed','result_movement_id'=>$m->id,'result_asiento_id'=>$entry]);
            $inverse=DB::table('detalle_asientos')->where('asiento_id',$entry)->orderBy('id')->get();
            foreach($s['lines'] as $index=>$a){$b=$inverse[$index];DB::table('bank_charge_reversal_lines')->insert(['reversal_id'=>$id,'original_asiento_id'=>$o->result_asiento_id,'reversal_asiento_id'=>$entry,'original_detail_id'=>$a->id,'reversal_detail_id'=>$b->id,'account_id'=>$a->cuenta_id,'currency_id'=>$a->moneda_id,'original_debe'=>$a->debe,'original_haber'=>$a->haber,'reversal_debe'=>$b->debe,'reversal_haber'=>$b->haber]);}
            $bank->saldo_actual=round((float)$bank->saldo_actual+(float)$o->amount,2);$bank->save();
            return $this->confirmedResult($n['uuid'],$contract);
        });}catch(\Throwable $e){while(DB::transactionLevel()>$level)DB::rollBack();if($e instanceof QueryException&&in_array($e->errorInfo[1]??0,[1205,1213],true))$this->reject('Contención; no se reintentó la devolución.');throw $e;}
    }
}
