<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Documentos administrativos AC-04B1. Solo escritor de ensayo; consumidor financiero cerrado. */
final class AccountingEventAuthorizationService
{
    public const EVENT='bancos.cargo_gasto.nio';
    private function reject(string $m): never {throw ValidationException::withMessages(['authorization'=>$m]);}
    private function text(mixed $v,int $max): string {
        if(!is_string($v)||trim($v)===''||!mb_check_encoding($v,'UTF-8')||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x1f]/',$v))$this->reject('Evidencia documental inválida.');return trim($v);
    }
    private function actor(): int {
        $u=auth()->id()?DB::table('users')->where('id',auth()->id())->sharedLock()->first():null;
        if(!app()->environment('testing')||!$u||!$u->is_active||!in_array($u->role,['alvaro','contador'],true))abort(403);
        return (int)$u->id;
    }
    private function policy(int $id,bool $exclusive): object {
        $q=DB::table('accounting_policy_versions')->where('id',$id);$v=($exclusive?$q->lockForUpdate():$q->sharedLock())->first();
        if(!$v||$v->status!=='published'||$v->kind!=='enterprise')$this->reject('Versión publicada requerida.');return $v;
    }
    private function stamp(DateTimeImmutable $at): string {return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
    private function row(int $id): object {
        $r=DB::table('accounting_policy_authorizations')->where('id',$id)->sharedLock()->first();
        if(!$r)$this->reject('Autorización inexistente.');$e=json_decode($r->evidence,true,512,JSON_THROW_ON_ERROR);
        if(($e['version']??null)!==1||!is_array($e['authorization']??null)||!hash_equals($r->evidence_hash,hash('sha256',AccountingPolicyService::canonical($e))))$this->reject('Hash de evidencia incompatible.');
        $required=['policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment','valid_from','valid_until','action','parent_id','parent_action','actor_id','document_reference','reason','recorded_at'];
        $actual=array_keys($e['authorization']);sort($required);sort($actual);if($actual!==$required)$this->reject('Evidencia estructurada incompleta.');
        foreach($e['authorization'] as $key=>$value)if(!property_exists($r,$key)||($value===null?$r->$key!==null:(string)$value!==(string)$r->$key))$this->reject('Evidencia manipulada.');
        return $r;
    }
    private function revoked(int $id): bool {return DB::table('accounting_policy_authorizations')->where('parent_id',$id)->where('action','revocation')->sharedLock()->exists();}
    private function append(array $data,int $actor,string $reference,string $reason): object {
        $data+=['actor_id'=>$actor,'document_reference'=>$this->text($reference,160),'reason'=>$this->text($reason,2000),'recorded_at'=>now('UTC')->format('Y-m-d H:i:s')];
        $e=['version'=>1,'authorization'=>$data];$data['evidence']=AccountingPolicyService::canonical($e);$data['evidence_hash']=hash('sha256',$data['evidence']);
        return $this->row(DB::table('accounting_policy_authorizations')->insertGetId($data));
    }
    private function scope(object $r): array {
        $keys=['policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment','valid_from','valid_until'];$a=[];foreach($keys as $k)$a[$k]=$r->$k;return $a;
    }
    private function verifyPolicy(object $v,int $bankId,DateTimeImmutable $at): void {
        $r=app(AccountingAccountResolver::class)->inspect([
            ['role'=>'fondos.banco','scope_type'=>'bank_account','resource_id'=>$bankId],
            ['role'=>'gasto.cargo_bancario','scope_type'=>'bank_account','resource_id'=>$bankId]],$at,(int)$v->id);
        $bank=DB::table('cuentas_bancarias')->where('id',$bankId)->sharedLock()->first();
        $currency=$bank?DB::table('monedas')->where('id',$bank->moneda_id)->value('codigo'):null;
        $base=DB::table('monedas')->where('es_base',true)->value('codigo');
        if($currency!=='NIO'||$base!=='NIO'||(int)$bank->cuenta_contable_id!==$r['resolved'][0]['account_id'])$this->reject('Banco/base NIO y vínculo al mayor originales requeridos.');
    }
    public function approveForSimulation(int $versionId,int $bankId,string $reference,string $reason): object {
        if(!app()->environment('testing'))abort(403);
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($versionId,$bankId,$reference,$reason){
            $v=$this->policy($versionId,true);$actor=$this->actor();
            $at=new DateTimeImmutable($v->valid_from,new DateTimeZone('UTC'));
            $this->verifyPolicy($v,$bankId,$at);
            foreach(DB::table('accounting_policy_authorizations')->where('policy_version_id',$versionId)->where('bank_account_id',$bankId)->where('action','approval')->lockForUpdate()->get() as $old)if(!$this->revoked($old->id))$this->reject('Ya existe aprobación de ese alcance.');
            return $this->append(['policy_version_id'=>$versionId,'policy_hash'=>$v->content_hash,'purpose'=>'simulation','event_key'=>self::EVENT,
                'bank_account_id'=>$bankId,'currency'=>'NIO','environment'=>'testing','action'=>'approval','parent_id'=>null,'parent_action'=>null,
                'valid_from'=>$v->valid_from,'valid_until'=>$v->valid_until],$actor,$reference,$reason);
        });
    }
    public function enableForSimulation(int $approvalId,string $reference,string $reason): object {
        if(!app()->environment('testing'))abort(403);
        $seed=DB::table('accounting_policy_authorizations')->find($approvalId);if(!$seed)$this->reject('Referencia inexistente.');
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($seed,$approvalId,$reference,$reason){
            $v=$this->policy($seed->policy_version_id,true);$actor=$this->actor();$a=$this->row($approvalId);
            if($a->action!=='approval'||$a->purpose!=='simulation'||$a->policy_hash!==$v->content_hash||$this->revoked($a->id))$this->reject('Aprobación no vigente o incompatible.');
            $this->verifyPolicy($v,(int)$a->bank_account_id,new DateTimeImmutable($a->valid_from,new DateTimeZone('UTC')));
            if(DB::table('accounting_policy_authorizations')->where('parent_id',$a->id)->where('action','enablement')->sharedLock()->exists())$this->reject('La aprobación ya tiene habilitación.');
            return $this->append($this->scope($a)+['action'=>'enablement','parent_id'=>$a->id,'parent_action'=>'approval'],$actor,$reference,$reason);
        });
    }
    public function revokeForSimulation(int $targetId,string $reference,string $reason): object {
        if(!app()->environment('testing'))abort(403);
        $seed=DB::table('accounting_policy_authorizations')->find($targetId);if(!$seed)$this->reject('Referencia inexistente.');
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($seed,$targetId,$reference,$reason){
            $this->policy($seed->policy_version_id,true);$actor=$this->actor();$a=$this->row($targetId);
            if(!in_array($a->action,['approval','enablement'],true)||$a->purpose!=='simulation'||$this->revoked($a->id))$this->reject('Retirada repetida o incompatible.');
            return $this->append($this->scope($a)+['action'=>'revocation','parent_id'=>$a->id,'parent_action'=>$a->action],$actor,$reference,$reason);
        });
    }
    /** Requiere transacción exterior para mantener versión S hasta que concluya la consulta documental. */
    public function inspectSimulation(int $id,DateTimeImmutable $at): object {
        if(!app()->environment('testing'))abort(403);
        if(DB::transactionLevel()===0)throw new \LogicException('La inspección de habilitación requiere transacción.');
        $seed=DB::table('accounting_policy_authorizations')->find($id);if(!$seed)$this->reject('Referencia inexistente.');
        $v=$this->policy($seed->policy_version_id,false);$this->actor();$a=$this->row($id);$stamp=$this->stamp($at);
        if($a->action!=='enablement'||$a->purpose!=='simulation'||$a->environment!=='testing'||$a->currency!=='NIO'||$a->event_key!==self::EVENT
            ||$a->policy_hash!==$v->content_hash||$a->valid_from>$stamp||$a->valid_until<=$stamp||$this->revoked($id))$this->reject('Habilitación de ensayo no elegible.');
        $parent=$this->row($a->parent_id);if($parent->action!=='approval'||$this->revoked($parent->id))$this->reject('Aprobación retirada.');
        $this->verifyPolicy($v,(int)$a->bank_account_id,$at);
        return $a;
    }
    public function assertBusinessAuthority(int $id): never {
        throw new \LogicException('AC-04B1 no emite autoridad empresarial ni habilita consumidores financieros.');
    }
}
