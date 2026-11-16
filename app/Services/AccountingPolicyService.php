<?php

namespace App\Services;

use App\Support\AccountingPolicyRoles as Roles;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Solo administración documental. No resuelve cuentas para contabilizar ni activa políticas. */
class AccountingPolicyService
{
    private function fail(string $message): never {throw ValidationException::withMessages(['policy'=>$message]);}
    private function keys(array $data,array $allowed): void
    {
        if (array_diff(array_keys($data),$allowed)) $this->fail('Campos desconocidos o autoritativos no admitidos.');
    }
    private function text($value,int $max): string
    {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || trim($value)==='' || mb_strlen(trim($value))>$max
            || preg_match('/[\x00-\x1F]/',$value)) $this->fail('Referencia, nombre o motivo inválido.');
        return trim($value);
    }
    private function date($value): ?string
    {
        if ($value===null) return null;
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)) $this->fail('Vigencia requiere instante con zona explícita.');
        $v=str_replace('Z','+00:00',$value);$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$v);
        if (!$d || $d->format('Y-m-d\TH:i:sP')!==$v) $this->fail('Fecha de vigencia inválida.');
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function canonical(array $value): string
    {
        $sort=function (array $v) use (&$sort): array {if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$x)if(is_array($x))$x=$sort($x);return $v;};
        return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private function slot(array $row): array
    {
        $role=$this->text($row['role']??null,80);
        try {$rule=Roles::rule($role);} catch (\DomainException $e) {$this->fail($e->getMessage());}
        $scope=$row['scope_type']??null;$id=$row['resource_id']??null;
        if (!in_array($scope,$rule['scopes'],true) || ($scope==='global'?$id!==null:(!is_int($id)||$id<=0))) $this->fail('Ámbito incompatible o recurso inválido.');
        return ['role'=>$role,'scope_type'=>$scope,'resource_id'=>$id];
    }
    private function slots(array $rows,bool $accounts): array
    {
        if (!array_is_list($rows)) $this->fail('Lista documental inválida.');
        $out=[];$seen=[];$scopes=[];
        foreach ($rows as $row) {
            if (!is_array($row)) $this->fail('Asociación inválida.');
            $this->keys($row,$accounts?['role','scope_type','resource_id','account_id']:['role','scope_type','resource_id']);
            $slot=$this->slot($row);$key=$slot['role'].'|'.$slot['scope_type'].':'.($slot['resource_id']??'');
            if (isset($seen[$key])) $this->fail('Papel y ámbito duplicados.');
            $seen[$key]=true;$scopes[$slot['role']][]=$slot['scope_type'];
            if ($accounts) {
                if (!is_int($row['account_id']??null) || $row['account_id']<=0) $this->fail('Cuenta inválida.');
                $slot['account_id']=$row['account_id'];
            }
            $out[$key]=$slot;
        }
        foreach ($scopes as $k=>$s) if (in_array('global',$s,true) && count($s)>1) $this->fail('Ámbito global y específico ambiguos para el mismo papel.');
        ksort($out,SORT_STRING);return array_values($out);
    }
    private function actor(): int
    {
        $id=auth()->id();
        $u=$id?DB::table('users')->where('id',$id)->sharedLock()->first():null;
        if (!$u || !$u->is_active || !in_array($u->role,['alvaro','contador'],true)) abort(403,'Se requiere identidad contable autenticada.');
        return (int)$u->id;
    }
    private function administrative(callable $action)
    {
        if (!Schema::hasTable('accounting_policy_versions')) $this->fail('Esquema de políticas no instalado; no hay fallback.');
        return app(AccountIntegrityService::class)->withAdministrationLock(fn ()=>$action($this->actor()));
    }
    private function version(int $id): object
    {
        $v=DB::table('accounting_policy_versions')->where('id',$id)->lockForUpdate()->first();
        if (!$v) abort(404);
        return $v;
    }
    private function draft(object $v,string $expectedHash): void
    {
        if ($v->status!=='draft') $this->fail('Una versión publicada es inmutable.');
        if (!hash_equals($v->content_hash,$expectedHash)) $this->fail('El borrador cambió; vuelva a revisarlo.');
        if (!hash_equals($v->content_hash,hash('sha256',self::canonical($this->content($v))))) $this->fail('Hash documental incompatible; requiere revisión.');
    }
    /** Lectura técnica; el llamador debe obtener la versión real bajo bloqueo compartido. */
    public function contentForInspection(object $v): array
    {
        return $this->content($v,false);
    }
    private function content(object $v,bool $exclusive=true): array
    {
        $q=DB::table('accounting_account_bindings')->where('policy_version_id',$v->id)->orderBy('semantic_role')->orderBy('scope_key');
        $bindings=($exclusive?$q->lockForUpdate():$q->sharedLock())->get();
        return ['contract_version'=>1,'version_number'=>(int)$v->version_number,'kind'=>$v->kind,'label'=>$v->label,'reason'=>$v->reason,
            'created_by'=>(int)$v->created_by,'valid_from'=>$v->valid_from,'valid_until'=>$v->valid_until,
            'status'=>$v->status,'approved_by'=>$v->approved_by===null?null:(int)$v->approved_by,
            'approval_reference'=>$v->approval_reference,'published_at'=>$v->published_at,
            'coverage'=>json_decode($v->coverage_manifest,true,512,JSON_THROW_ON_ERROR),
            'bindings'=>$bindings->map(fn($b)=>['role'=>$b->semantic_role,'scope_type'=>$b->scope_type,
                'resource_id'=>$b->scope_type==='item'?(int)$b->item_id:($b->scope_type==='bank_account'?(int)$b->bank_account_id:null),
                'account_id'=>(int)$b->account_id,'rules'=>json_decode($b->rules_snapshot,true,512,JSON_THROW_ON_ERROR),
                'account'=>json_decode($b->account_snapshot,true,512,JSON_THROW_ON_ERROR)])->all()];
    }
    private function audit(int $id,int $actor,string $event,string $reason,?array $before,array $after,?string $reference=null): void
    {
        $seq=(int)DB::table('accounting_policy_audits')->where('policy_version_id',$id)->lockForUpdate()->max('event_sequence')+1;
        DB::table('accounting_policy_audits')->insert(['policy_version_id'=>$id,'event_sequence'=>$seq,'actor_id'=>$actor,'event'=>$event,
            'reason'=>$reason,'before_hash'=>$before===null?null:hash('sha256',self::canonical($before)),
            'after_hash'=>hash('sha256',self::canonical($after)),
            'evidence'=>self::canonical(['version'=>1,'before'=>$before,'after'=>$after,'approval_reference'=>$reference]),
            'recorded_at'=>now('UTC')->format('Y-m-d H:i:s')]);
    }
    public function createDraft(array $data): object
    {
        $this->keys($data,['kind','label','reason','valid_from','valid_until','coverage']);
        if (!in_array($data['kind']??null,['demo','enterprise'],true)) $this->fail('Indique explícitamente demostración o empresa.');
        return $this->administrative(function ($actor) use ($data) {
            if(!is_array($data['coverage']??null))$this->fail('Manifiesto explícito requerido.');
            $coverage=$this->slots($data['coverage']??[],false);
            $from=$this->date($data['valid_from']??null);$until=$this->date($data['valid_until']??null);
            if ($from!==null && $until!==null && $from>=$until) $this->fail('Intervalo inválido.');
            $number=(int)DB::table('accounting_policy_versions')->lockForUpdate()->max('version_number')+1;
            $id=DB::table('accounting_policy_versions')->insertGetId(['version_number'=>$number,'contract_version'=>1,
                'kind'=>$data['kind'],'status'=>'draft','label'=>$this->text($data['label']??null,160),'reason'=>$this->text($data['reason']??null,2000),
                'created_by'=>$actor,'valid_from'=>$from,'valid_until'=>$until,'coverage_manifest'=>self::canonical($coverage),
                'content_hash'=>str_repeat('0',64),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $v=$this->version($id);$after=$this->content($v);
            DB::table('accounting_policy_versions')->where('id',$id)->update(['content_hash'=>hash('sha256',self::canonical($after))]);
            $this->audit($id,$actor,'created',$v->reason,null,$after);
            return $this->version($id);
        });
    }
    /** Reutilizada por publicación y consulta técnica; no selecciona cuentas por código. */
    public function accountSnapshot(array $slot): array
    {
        $a=app(AccountIntegrityService::class)->account($slot['account_id']);$rule=Roles::rule($slot['role']);
        if (($rule['type']!==null && $a->tipo!==$rule['type']) || ($rule['nature']!==null && $a->naturaleza!==$rule['nature'])) $this->fail('Cuenta incompatible con el papel semántico.');
        $resource=null;
        if ($slot['scope_type']==='item') {
            $i=DB::table('items')->where('id',$slot['resource_id'])->sharedLock()->first();
            if (!$i || $i->deleted_at!==null || !in_array($i->type,['producto_venta','producto_interno'],true)) $this->fail('Artículo archivado o inexistente.');
            $supplies=(bool)$i->is_fractionable || $i->type!=='producto_venta';
            if (($slot['role']==='inventario.reventa' && $supplies) || ($slot['role']==='inventario.insumos' && !$supplies)) $this->fail('Clasificación de artículo incompatible.');
            $resource=['id'=>(int)$i->id,'type'=>$i->type,'fractional'=>(bool)$i->is_fractionable];
        } elseif ($slot['scope_type']==='bank_account') {
            $b=DB::table('cuentas_bancarias')->where('id',$slot['resource_id'])->sharedLock()->first();
            if (!$b || !$b->activa) $this->fail('Recurso bancario inexistente o inactivo.');
            $resource=['id'=>(int)$b->id,'currency_id'=>(int)$b->moneda_id];
        }
        return ['version'=>1,'id'=>(int)$a->id,'code'=>$a->codigo,'name'=>$a->nombre,'type'=>$a->tipo,'nature'=>$a->naturaleza,
            'active'=>(bool)$a->activa,'imputable'=>(bool)$a->permite_movimiento,'resource'=>$resource];
    }
    public function reviseDraft(int $id,string $expectedHash,array $data): object
    {
        $this->keys($data,['label','reason','valid_from','valid_until','coverage']);
        return $this->administrative(function ($actor) use($id,$expectedHash,$data) {
            $v=$this->version($id);$this->draft($v,$expectedHash);$before=$this->content($v);
            $from=array_key_exists('valid_from',$data)?$this->date($data['valid_from']):$v->valid_from;
            $until=array_key_exists('valid_until',$data)?$this->date($data['valid_until']):$v->valid_until;
            if($from!==null && $until!==null && $from>=$until)$this->fail('Intervalo inválido.');
            if(array_key_exists('coverage',$data) && !is_array($data['coverage']))$this->fail('Manifiesto inválido.');
            $coverage=array_key_exists('coverage',$data)?$this->slots($data['coverage'],false):$before['coverage'];
            $reason=$this->text($data['reason']??null,2000);
            DB::table('accounting_policy_versions')->where('id',$id)->update(['label'=>$this->text($data['label']??$v->label,160),
                'reason'=>$reason,'valid_from'=>$from,'valid_until'=>$until,'coverage_manifest'=>self::canonical($coverage),'updated_at'=>now('UTC')]);
            $after=$this->content($this->version($id));
            DB::table('accounting_policy_versions')->where('id',$id)->update(['content_hash'=>hash('sha256',self::canonical($after))]);
            $this->audit($id,$actor,'draft_changed',$reason,$before,$after);return $this->version($id);
        });
    }
    public function replaceBindings(int $id,string $expectedHash,array $rows,string $reason): object
    {
        $slots=$this->slots($rows,true);$reason=$this->text($reason,2000);
        return $this->administrative(function ($actor) use($id,$expectedHash,$slots,$reason) {
            $v=$this->version($id);$this->draft($v,$expectedHash);$before=$this->content($v);
            // Recursos antes de cuentas, en orden estable; no invertir el protocolo de inventario.
            foreach (['item','bank_account'] as $kind) {
                $ids=array_unique(array_column(array_filter($slots,fn($s)=>$s['scope_type']===$kind),'resource_id'));sort($ids,SORT_NUMERIC);
                foreach ($ids as $resourceId) DB::table($kind==='item'?'items':'cuentas_bancarias')->where('id',$resourceId)->sharedLock()->first();
            }
            $ids=array_unique(array_column($slots,'account_id'));sort($ids,SORT_NUMERIC);
            foreach($ids as $accountId) app(AccountIntegrityService::class)->account($accountId);
            DB::table('accounting_account_bindings')->where('policy_version_id',$id)->delete(); // Solo borrador; antes/after quedan en auditoría.
            foreach ($slots as $s) DB::table('accounting_account_bindings')->insert(['policy_version_id'=>$id,'semantic_role'=>$s['role'],
                'scope_type'=>$s['scope_type'],'item_id'=>$s['scope_type']==='item'?$s['resource_id']:null,
                'bank_account_id'=>$s['scope_type']==='bank_account'?$s['resource_id']:null,'account_id'=>$s['account_id'],
                'rules_snapshot'=>self::canonical(Roles::rule($s['role'])),'account_snapshot'=>self::canonical($this->accountSnapshot($s)),
                'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $after=$this->content($v);DB::table('accounting_policy_versions')->where('id',$id)->update(['content_hash'=>hash('sha256',self::canonical($after)),'updated_at'=>now('UTC')]);
            $this->audit($id,$actor,'bindings_changed',$reason,$before,$after);return $this->version($id);
        });
    }
    public function publish(int $id,string $expectedHash,string $reference,string $reason): object
    {
        $reference=$this->text($reference,160);$reason=$this->text($reason,2000);
        return $this->administrative(function ($actor) use($id,$expectedHash,$reference,$reason) {
            $v=$this->version($id);$this->draft($v,$expectedHash);$content=$this->content($v);
            if ($v->kind!=='enterprise') $this->fail('Una demostración no puede publicarse como política empresarial.');
            if (!$v->valid_from || !$v->valid_until || $v->valid_from >= $v->valid_until || $v->valid_from<now('UTC')->format('Y-m-d H:i:s')) $this->fail('Publicación requiere vigencia futura finita y explícita.');
            $coverage=$this->slots($content['coverage'],false);
            if (array_diff(Roles::CORE,array_column($coverage,'role'))) $this->fail('Cobertura incompleta de la familia ventas/inventario.');
            $actual=array_map(fn($b)=>array_intersect_key($b,array_flip(['role','scope_type','resource_id'])),$content['bindings']);
            if (self::canonical($this->slots($actual,false))!==self::canonical($coverage)) $this->fail('Faltan asociaciones declaradas o hay asociaciones fuera del manifiesto.');
            $all=DB::table('accounting_policy_versions')->where('status','published')->orderBy('id')->lockForUpdate()->get();
            foreach($all as $p) if($p->valid_from<$v->valid_until && $p->valid_until>$v->valid_from) $this->fail('La vigencia se solapa con una versión publicada.');
            $slots=$content['bindings'];
            foreach($slots as $b) if (Roles::rule($b['role'])['publication_blocked']) $this->fail('Apertura pendiente de decisiones H01-B2B; no se publica esa asociación.');
            foreach (['item','bank_account'] as $kind) {
                $ids=array_unique(array_column(array_filter($slots,fn($s)=>$s['scope_type']===$kind),'resource_id'));sort($ids,SORT_NUMERIC);
                foreach($ids as $resourceId) DB::table($kind==='item'?'items':'cuentas_bancarias')->where('id',$resourceId)->sharedLock()->first();
            }
            $ids=array_unique(array_column($slots,'account_id'));sort($ids,SORT_NUMERIC);foreach($ids as $accountId)app(AccountIntegrityService::class)->account($accountId);
            foreach($slots as $b) if(self::canonical($b['rules'])!==self::canonical(Roles::rule($b['role']))
                || self::canonical($b['account'])!==self::canonical($this->accountSnapshot($b))) $this->fail('La cuenta o recurso cambió desde la preparación; vuelva a revisarlo.');
            DB::table('accounting_policy_versions')->where('id',$id)->update(['status'=>'published','approved_by'=>$actor,
                'approval_reference'=>$reference,'published_at'=>now('UTC'),'updated_at'=>now('UTC')]);
            $after=$this->content($this->version($id));
            DB::table('accounting_policy_versions')->where('id',$id)->update(['content_hash'=>hash('sha256',self::canonical($after))]);
            $this->audit($id,$actor,'published',$reason,$content,$after,$reference);return $this->version($id);
        });
    }
}
