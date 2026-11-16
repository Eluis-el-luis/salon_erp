<?php

namespace App\Services;

use App\Support\AccountingPolicyRoles;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** AC-03B: consulta interna de servidor. Su resultado no autoriza una imputación. */
final class AccountingAccountResolver
{
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['policy'=>$message]);
    }

    /** Nunca llamar con IDs de política/recursos tomados como autoridad de un request HTTP. */
    public function inspect(array $requests,DateTimeImmutable $at,?int $technicalVersionId=null): array
    {
        return $this->inspectInternal($requests,$at,$technicalVersionId,false);
    }
    /** Revalidación interna de la versión seleccionada ANTES del banco; no selecciona otra versión. */
    public function inspectPinnedForInternalHarness(array $requests,DateTimeImmutable $at,int $versionId,string $contract): array
    {
        \App\Support\AccountingSimulationBoundary::assert($contract);
        return $this->inspectPinnedForSimulationContext($requests,$at,$versionId,$contract);
    }
    public function inspectPinnedForSimulationContext(array $requests,DateTimeImmutable $at,int $versionId,string $contract): array {
        \App\Support\AccountingSimulationAccess::assert($contract);
        if(DB::transactionLevel()===0)throw new \LogicException('Versión fijada requiere transacción exterior.');
        return $this->inspectInternal($requests,$at,$versionId,true);
    }
    private function inspectInternal(array $requests,DateTimeImmutable $at,?int $technicalVersionId,bool $pinned): array
    {
        if (!array_is_list($requests) || !$requests || count($requests)>100) $this->reject('Lista de resolución inválida.');
        foreach($requests as $r) $this->validateSlot($r);
        if ($technicalVersionId!==null && $technicalVersionId<=0) $this->reject('Referencia de versión inválida.');
        $instant=$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return DB::transaction(function() use($requests,$instant,$technicalVersionId,$pinned) {
            $actor=auth()->id();$user=$actor?DB::table('users')->where('id',$actor)->sharedLock()->first():null;
            if (!$user || !$user->is_active || !in_array($user->role,['alvaro','contador'],true)) abort(403);
            // Una selección por lote, mediante lectura actual; nunca una selección por línea.
            $q=DB::table('accounting_policy_versions')->where('status','published')->where('kind','enterprise')
                ->where('valid_from','<=',$instant)->where('valid_until','>',$instant)->orderBy('id');
            if($pinned)$q->where('id',$technicalVersionId);
            $eligible=$q->sharedLock()->get();
            if ($eligible->count()!==1) $this->reject('Se requiere exactamente una versión publicada y vigente.');
            $v=$eligible->first();
            if ($technicalVersionId!==null && (int)$v->id!==$technicalVersionId) $this->reject('La versión explícita no es la única publicada y vigente.');
            if ((int)$v->contract_version!==1 || !$v->approved_by || !$v->approval_reference || !$v->published_at) $this->reject('Publicación incompleta.');
            $service=app(AccountingPolicyService::class);$content=$service->contentForInspection($v);
            $canonical=AccountingPolicyService::canonical($content);
            if (!hash_equals($v->content_hash,hash('sha256',$canonical))) $this->reject('Contenido o hash de política incompatible.');
            $audit=DB::table('accounting_policy_audits')->where('policy_version_id',$v->id)->orderByDesc('event_sequence')->sharedLock()->first();
            $evidence=$audit?json_decode($audit->evidence,true,512,JSON_THROW_ON_ERROR):null;
            if (!$audit || $audit->event!=='published' || (int)$audit->actor_id!==(int)$v->approved_by
                || $audit->after_hash!==$v->content_hash || !is_array($evidence) || ($evidence['version']??null)!==1
                || ($evidence['approval_reference']??null)!==$v->approval_reference
                || !is_array($evidence['after']??null)
                || AccountingPolicyService::canonical($evidence['after']??[])!==$canonical) $this->reject('Auditoría de publicación incompatible.');

            $manifest=$this->index($content['coverage']);$bindings=$this->index($content['bindings']);
            if (array_keys($manifest)!==array_keys($bindings)
                || array_diff(AccountingPolicyRoles::CORE,array_column($content['coverage'],'role'))) $this->reject('Cobertura documental incompleta.');
            // Mismo orden de recursos/cuentas de AC-03A. No GET_LOCK ni bloqueo de documentos.
            foreach(['item','bank_account'] as $kind) {
                $ids=array_unique(array_column(array_filter($content['bindings'],fn($b)=>$b['scope_type']===$kind),'resource_id'));sort($ids,SORT_NUMERIC);
                foreach($ids as $id) DB::table($kind==='item'?'items':'cuentas_bancarias')->where('id',$id)->sharedLock()->first();
            }
            $ids=array_unique(array_column($content['bindings'],'account_id'));sort($ids,SORT_NUMERIC);
            foreach($ids as $id) app(AccountIntegrityService::class)->account((int)$id);
            foreach($bindings as $b) {
                $rule=AccountingPolicyRoles::rule($b['role']);
                if ($rule['publication_blocked'] || AccountingPolicyService::canonical($rule)!==AccountingPolicyService::canonical($b['rules'])
                    || AccountingPolicyService::canonical($service->accountSnapshot($b))!==AccountingPolicyService::canonical($b['account'])) $this->reject('Fotografía de reglas, cuenta o recurso incompatible.');
            }
            $rows=DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->sharedLock()->get()->keyBy(fn($b)=>$b->semantic_role.'|'.$b->scope_key);
            $resolved=[];
            foreach($requests as $r) {
                $key=$this->key($r);$b=$bindings[$key]??null;$row=$rows[$key]??null;
                if (!$b || !$row) $this->reject('El papel y ámbito solicitado carecen de cobertura exacta; no hay fallback.');
                $resolved[]=['account_id'=>(int)$b['account_id'],'role'=>$b['role'],'scope_type'=>$b['scope_type'],
                    'resource_id'=>$b['resource_id'],'scope_key'=>$row->scope_key,'binding_id'=>(int)$row->id,
                    'policy_version_id'=>(int)$v->id,'policy_hash'=>$v->content_hash,'rules'=>$b['rules'],'account_snapshot'=>$b['account']];
            }
            return ['contract_version'=>1,'mode'=>'technical_simulation','posting_authorized'=>false,
                'policy_version_id'=>(int)$v->id,'policy_hash'=>$v->content_hash,'at_utc'=>$instant,
                'valid_from'=>$v->valid_from,'valid_until'=>$v->valid_until,'publication_audit_id'=>(int)$audit->id,
                'resolved'=>$resolved];
        });
    }

    public function resolveForPosting(): never
    {
        throw new \LogicException('AC-03B no habilita contabilización: aprobación empresarial y evento operativo pendientes.');
    }

    private function validateSlot(mixed $r,bool $content=false): void
    {
        if (!is_array($r) || (!$content && array_diff(array_keys($r),['role','scope_type','resource_id']))
            || !is_string($r['role']??null)) $this->reject('Contexto desconocido o campos autoritativos no admitidos.');
        try {$rule=AccountingPolicyRoles::rule($r['role']);} catch(\DomainException $e) {$this->reject('Papel semántico no registrado.');}
        $scope=$r['scope_type']??null;$id=$r['resource_id']??null;
        if (!array_key_exists('resource_id',$r) || !in_array($scope,$rule['scopes'],true)
            || ($scope==='global'?$id!==null:(!is_int($id)||$id<=0))) $this->reject('Ámbito o referencia incompatible.');
    }

    private function key(array $r): string
    {
        return $r['role'].'|'.$r['scope_type'].($r['scope_type']==='global'?'':':'.$r['resource_id']);
    }

    private function index(array $rows): array
    {
        if (!array_is_list($rows)) $this->reject('Cobertura inválida.');
        $out=[];$scopes=[];
        foreach($rows as $r) {
            $this->validateSlot($r,true);$key=$this->key($r);
            if(isset($out[$key])) $this->reject('Asociación duplicada.');
            $out[$key]=$r;$scopes[$r['role']][]=$r['scope_type'];
        }
        foreach($scopes as $s) if(in_array('global',$s,true) && count($s)>1) $this->reject('Ámbitos global y específico ambiguos.');
        ksort($out,SORT_STRING);return $out;
    }
}
