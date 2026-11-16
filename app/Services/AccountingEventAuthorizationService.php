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
    private function append(array $data,int $actor,string $reference,string $reason,?array $administration=null): object {
        $data+=['actor_id'=>$actor,'document_reference'=>$this->text($reference,160),'reason'=>$this->text($reason,2000),'recorded_at'=>now('UTC')->format('Y-m-d H:i:s')];
        $e=['version'=>1,'authorization'=>$data]+($administration===null?[]:['administration'=>$administration]);$data['evidence']=AccountingPolicyService::canonical($e);$data['evidence_hash']=hash('sha256',$data['evidence']);
        return $this->row(DB::table('accounting_policy_authorizations')->insertGetId($data));
    }
    private function scope(object $r): array {
        $keys=['policy_version_id','policy_hash','purpose','event_key','bank_account_id','currency','environment','valid_from','valid_until'];$a=[];foreach($keys as $k)$a[$k]=$r->$k;return $a;
    }
    private function verifyPolicy(object $v,int $bankId,DateTimeImmutable $at,bool $pinned=false): void {
        $requests=[
            ['role'=>'fondos.banco','scope_type'=>'bank_account','resource_id'=>$bankId],
            ['role'=>'gasto.cargo_bancario','scope_type'=>'bank_account','resource_id'=>$bankId]];
        $resolver=app(AccountingAccountResolver::class);
        $r=$pinned?$resolver->inspectPinnedForInternalHarness($requests,$at,(int)$v->id,\App\Support\AccountingSimulationBoundary::CONTRACT):$resolver->inspect($requests,$at,(int)$v->id);
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
        $a=$this->eligibleSimulation($id,$at);
        $v=$this->policy($a->policy_version_id,false);$this->verifyPolicy($v,(int)$a->bank_account_id,$at);return $a;
    }
    /** Solo fija versión/autorización: el arnés tomará banco X antes de verificar recursos. */
    public function pinForInternalHarness(int $id,DateTimeImmutable $at,string $contract): object {
        \App\Support\AccountingSimulationBoundary::assert($contract);
        if(DB::transactionLevel()===0)throw new \LogicException('Fijación requiere transacción.');
        // Descubrir candidatos sin bloquear el recorrido completo (incluye borradores en un full scan).
        // La decisión usa después la versión/autoridad releídas por PK bajo S. AC-03A no permite
        // publicar un solapamiento y toma las versiones publicadas X antes de confirmar.
        $seed=DB::table('accounting_policy_authorizations')->find($id);if(!$seed)$this->reject('Referencia inexistente.');$stamp=$this->stamp($at);
        $eligible=DB::table('accounting_policy_versions')->where('status','published')->where('kind','enterprise')->where('valid_from','<=',$stamp)->where('valid_until','>',$stamp)->orderBy('id')->get();
        if($eligible->count()!==1||(int)$eligible[0]->id!==(int)$seed->policy_version_id)$this->reject('Versión elegible inexistente o ambigua.');
        return $this->eligibleSimulation($id,$at);
    }
    public function validatePinnedForInternalHarness(int $id,DateTimeImmutable $at,string $contract): object {
        \App\Support\AccountingSimulationBoundary::assert($contract);$a=$this->eligibleSimulation($id,$at);
        $this->verifyPolicy($this->policy($a->policy_version_id,false),(int)$a->bank_account_id,$at,true);return $a;
    }
    private function eligibleSimulation(int $id,DateTimeImmutable $at): object {
        if(!app()->environment('testing'))abort(403);
        if(DB::transactionLevel()===0)throw new \LogicException('La inspección de habilitación requiere transacción.');
        $seed=DB::table('accounting_policy_authorizations')->find($id);if(!$seed)$this->reject('Referencia inexistente.');
        $v=$this->policy($seed->policy_version_id,false);$this->actor();$a=$this->row($id);$stamp=$this->stamp($at);
        if($a->action!=='enablement'||$a->purpose!=='simulation'||$a->environment!=='testing'||$a->currency!=='NIO'||$a->event_key!==self::EVENT
            ||$a->policy_hash!==$v->content_hash||$a->valid_from>$stamp||$a->valid_until<=$stamp||$this->revoked($id))$this->reject('Habilitación de ensayo no elegible.');
        $parent=$this->row($a->parent_id);if($parent->action!=='approval'||$this->revoked($parent->id))$this->reject('Aprobación retirada.');
        return $a;
    }
    public function assertBusinessAuthority(int $id): never {
        throw new \LogicException('AC-04B1 no emite autoridad empresarial ni habilita consumidores financieros.');
    }

    /** Origen exclusivamente de creación; nunca adjuntar o reconstruir para una versión anterior. */
    private function administrativeOrigin(object $v): array {
        $a=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->where('event_sequence',1)->sharedLock()->first();
        $e=$a?json_decode($a->evidence,true,512,JSON_THROW_ON_ERROR):[];$o=$e['origin']??null;
        if(!$a||$a->event!=='created'||$a->before_hash!==null||!is_array($o)||($o['version']??null)!==1
            ||($o['workflow']??null)!=='accounting_administration'||($o['execution']??null)!=='synthetic_test'||($o['economic_approval']??null)!==false
            ||($o['schema']??null)!=='salon_erp_test'||($o['policy_id']??null)!==(int)$v->id||($o['created_by']??null)!==(int)$v->created_by
            ||(int)$a->actor_id!==(int)$v->created_by||($o['initial_hash']??null)!==$a->after_hash
            ||($o['recorded_at']??null)!==$a->recorded_at||($e['origin_hash']??null)!==hash('sha256',AccountingPolicyService::canonical($o))
            ||hash('sha256',AccountingPolicyService::canonical($e['after']??[]))!==$a->after_hash)$this->reject('Procedencia administrativa de creación ausente o incompatible. No promover registros anteriores.');
        return ['contract_version'=>1,'synthetic'=>true,'economic_approval'=>false,'financial_authority'=>false,
            'installation'=>'salon_erp_test','origin_audit_id'=>(int)$a->id,'origin_hash'=>$e['origin_hash']];
    }
    private function administrationBoundary(): void {
        \App\Support\AccountingSimulationBoundary::assert(\App\Support\AccountingSimulationBoundary::CONTRACT);
    }
    public function reviewForAdministration(int $id,int $bankId): array {
        $this->administrationBoundary();
        return DB::transaction(function()use($id,$bankId){
            $v=$this->policy($id,false);$this->actor();$origin=$this->administrativeOrigin($v);
            if($v->valid_until<=now('UTC')->format('Y-m-d H:i:s'))$this->reject('Política expirada.');
            $at=new DateTimeImmutable($v->valid_from,new DateTimeZone('UTC'));$this->verifyPolicy($v,$bankId,$at);
            return ['policy'=>$v,'origin'=>$origin,'content'=>app(AccountingPolicyService::class)->contentForInspection($v),
                'bank'=>DB::table('cuentas_bancarias')->where('id',$bankId)->sharedLock()->first(),
                'history'=>DB::table('accounting_policy_authorizations')->where('policy_version_id',$id)->where('bank_account_id',$bankId)->where('purpose','business')->orderBy('id')->sharedLock()->get()];
        });
    }
    private function administrationWindow(string $from,string $until,object $parent): array {
        $convert=function($s){$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',str_replace('Z','+00:00',$s));
            if(!$d||$d->format('Y-m-d\TH:i:sP')!==str_replace('Z','+00:00',$s))$this->reject('Instante UTC con zona explícita requerido.');return $this->stamp($d);};
        $f=$convert($from);$u=$convert($until);
        if($f>=$u||$f<$parent->valid_from||$u>$parent->valid_until||$u<=now('UTC')->format('Y-m-d H:i:s'))$this->reject('Vigencia incompatible.');return [$f,$u];
    }
    public function approveForAdministration(int $id,int $bankId,string $hash,string $from,string $until,string $reference,string $reason): object {
        $this->administrationBoundary();
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($id,$bankId,$hash,$from,$until,$reference,$reason){
            $v=$this->policy($id,true);$actor=$this->actor();$review=$this->reviewForAdministration($id,$bankId);
            if(!hash_equals($v->content_hash,$hash))$this->reject('La revisión cambió; revisar nuevamente.');[$f,$u]=$this->administrationWindow($from,$until,$v);
            foreach(DB::table('accounting_policy_authorizations')->where('policy_version_id',$id)->where('bank_account_id',$bankId)->where('purpose','business')->where('action','approval')->lockForUpdate()->get() as $old)
                if(!$this->revoked($old->id))$this->reject('Existe una aprobación documental no retirada.');
            $meta=$review['origin']+['event_contract'=>1,'reviewed_bindings'=>$review['content']['bindings']];
            return $this->append(['policy_version_id'=>$id,'policy_hash'=>$v->content_hash,'purpose'=>'business','event_key'=>self::EVENT,'bank_account_id'=>$bankId,
                'currency'=>'NIO','environment'=>'testing','action'=>'approval','parent_id'=>null,'parent_action'=>null,'valid_from'=>$f,'valid_until'=>$u],$actor,$reference,$reason,$meta);
        });
    }
    private function administrativeRow(int $id): object {
        $a=$this->row($id);$m=json_decode($a->evidence,true)['administration']??[];
        $origin=$this->administrativeOrigin($this->policy($a->policy_version_id,false));
        foreach($origin as $key=>$value)if(($m[$key]??null)!==$value)$this->reject('Evidencia administrativa incompatible.');
        if($a->purpose!=='business'||$a->environment!=='testing'||($m['event_contract']??null)!==1||!is_array($m['reviewed_bindings']??null))$this->reject('Acto ajeno al contrato documental de ensayo.');return $a;
    }
    public function enableForAdministration(int $id,string $from,string $until,string $reference,string $reason): object {
        $this->administrationBoundary();$seed=DB::table('accounting_policy_authorizations')->find($id);if(!$seed)$this->reject('Acto inexistente.');
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($seed,$id,$from,$until,$reference,$reason){
            $this->policy($seed->policy_version_id,true);$actor=$this->actor();$a=$this->administrativeRow($id);
            if($a->action!=='approval'||$this->revoked($id))$this->reject('Aprobación no elegible.');
            $review=$this->reviewForAdministration($a->policy_version_id,$a->bank_account_id);
            if(AccountingPolicyService::canonical($review['content']['bindings'])!==AccountingPolicyService::canonical(json_decode($a->evidence,true)['administration']['reviewed_bindings']))$this->reject('Asociaciones revisadas incompatibles.');
            [$f,$u]=$this->administrationWindow($from,$until,$a);
            if(DB::table('accounting_policy_authorizations')->where('parent_id',$id)->where('action','enablement')->lockForUpdate()->exists())$this->reject('Aprobación ya habilitada; no reutilizarla.');
            $scope=$this->scope($a);$scope['valid_from']=$f;$scope['valid_until']=$u;
            return $this->append($scope+['action'=>'enablement','parent_id'=>$id,'parent_action'=>'approval'],$actor,$reference,$reason,json_decode($a->evidence,true)['administration']);
        });
    }
    public function revokeForAdministration(int $id,string $reference,string $reason): object {
        $this->administrationBoundary();$seed=DB::table('accounting_policy_authorizations')->find($id);if(!$seed)$this->reject('Acto inexistente.');
        return app(AccountIntegrityService::class)->withAdministrationLock(function()use($seed,$id,$reference,$reason){
            $this->policy($seed->policy_version_id,true);$actor=$this->actor();$a=$this->administrativeRow($id);
            if(!in_array($a->action,['approval','enablement'],true)||$this->revoked($id))$this->reject('Retirada repetida o acto incompatible.');
            return $this->append($this->scope($a)+['action'=>'revocation','parent_id'=>$id,'parent_action'=>$a->action],$actor,$reference,$reason,json_decode($a->evidence,true)['administration']);
        });
    }
}
