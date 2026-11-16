<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\CuentaContable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Validación de catálogo e imputación; no resuelve papeles ni cambia importes. */
class AccountIntegrityService
{
    public function account(int $id, bool $historical = false, ?array $types = null): CuentaContable
    {
        $q=CuentaContable::whereKey($id);
        if (DB::transactionLevel()>0) $q->sharedLock();
        $account=$q->first();
        if (!$account || (!$historical && (!$account->activa || !$account->permite_movimiento))) {
            throw new ContabilidadException('Cuenta inexistente, inactiva o no imputable.');
        }
        if (!$historical && $types!==null && !in_array($account->tipo,$types,true)) throw new ContabilidadException('Tipo de cuenta incompatible con la operación.');
        return $account;
    }

    /** Solo al final de una transacción, antes de confirmar el asiento. */
    public function posting(int $entryId, ?int $originalId = null): void
    {
        if (DB::transactionLevel()===0) throw new \LogicException('La imputación requiere una transacción activa.');
        $entry=DB::table('asientos_contables')->where('id',$entryId)->first();
        if (!$entry) throw new ContabilidadException('Asiento inexistente.');
        $period=DB::table('periodos_contables')->where('id',$entry->periodo_id)->sharedLock()->first();
        if (!$period || $period->estado!=='abierto' || $entry->fecha<$period->fecha_inicio || $entry->fecha>$period->fecha_fin) {
            throw new ContabilidadException('La fecha del asiento no pertenece a un período abierto.');
        }
        $lines=DB::table('detalle_asientos')->where('asiento_id',$entryId)->orderBy('cuenta_id')->get();
        if ($originalId!==null) {
            $original=DB::table('asientos_contables')->where('id',$originalId)->lockForUpdate()->first();
            $source=DB::table('detalle_asientos')->where('asiento_id',$originalId)->orderBy('id')->lockForUpdate()->get();
            if (!$original || $source->isEmpty() || $entryId===$originalId) throw new ContabilidadException('Reversa sin origen verificable.');
            $signature=fn ($rows,$inverse)=>$rows->map(fn ($l)=>json_encode([(int)$l->cuenta_id,(int)$l->moneda_id,
                (string)($inverse?$l->haber:$l->debe),(string)($inverse?$l->debe:$l->haber)]))->sort()->values()->all();
            if ($signature($source,true)!==$signature($lines,false)) throw new ContabilidadException('La reversa no coincide con las líneas originales.');
        }
        foreach ($lines->pluck('cuenta_id')->unique()->sort() as $id) $this->account((int)$id,$originalId!==null);
    }

    public function dependencies(int $id): array
    {
        $found=[];
        foreach (['detalle_asientos'=>'cuenta_id','cuentas_bancarias'=>'cuenta_contable_id',
            'tipos_gasto'=>'cuenta_contable_id','salary_structure_details'=>'cuenta_contable_id'] as $table=>$column) {
            if (DB::table($table)->where($column,$id)->exists()) $found[]=$table;
        }
        if (Schema::hasTable('inventory_openings') && DB::table('inventory_openings')->where('counterpart_account_id',$id)->exists()) $found[]='inventory_openings';
        if (Schema::hasTable('accounting_account_bindings') && DB::table('accounting_account_bindings')->where('account_id',$id)->exists()) $found[]='accounting_account_bindings';
        return $found;
    }

    /** Serializa únicamente operaciones administrativas del árbol, no operaciones financieras. */
    public function catalogue(callable $action)
    {
        return $this->withAdministrationLock(function () use ($action) {
            $accounts=CuentaContable::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            return $action($accounts);
        });
    }

    /** Mismo mutex administrativo, sin bloquear todo el catálogo para publicar políticas. */
    public function withAdministrationLock(callable $action)
    {
        $name='salon-cat:'.substr(hash('sha256',DB::connection()->getDatabaseName()),0,40);
        if ((int)DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired',[$name])->acquired!==1) {
            throw ValidationException::withMessages(['cuenta_padre_id'=>'Catálogo en edición; intente de nuevo.']);
        }
        try {
            return DB::transaction($action);
        } finally { DB::selectOne('SELECT RELEASE_LOCK(?) AS released',[$name]); }
    }

    public function save(array $data, ?int $id=null): CuentaContable
    {
        return $this->catalogue(function ($accounts) use ($data,$id) {
            $account=$id===null?new CuentaContable:$accounts->get($id);
            if (!$account) abort(404);
            if ($account->exists && $account->is_system_account) {
                $account->update(['nombre'=>$data['nombre']]);
                return $account->refresh();
            }
            $parentId=array_key_exists('cuenta_padre_id',$data)?($data['cuenta_padre_id']?(int)$data['cuenta_padre_id']:null):$account->cuenta_padre_id;
            $parent=$parentId?$accounts->get($parentId):null;
            if ($parentId && !$parent) $this->invalid('El padre no existe.');
            $seen=$id===null?[]:[$id=>true]; $cursor=$parent;
            while ($cursor) {
                if (isset($seen[$cursor->id])) $this->invalid('La relación produce un ciclo.');
                $seen[$cursor->id]=true; $cursor=$cursor->cuenta_padre_id?$accounts->get($cursor->cuenta_padre_id):null;
            }
            if ($parent && ($parent->tipo!==$data['tipo'] || !$parent->activa)) $this->invalid('Padre inactivo o de tipo incompatible.');
            // Naturaleza opuesta es válida para correctoras: descuentos, retiros, etc.
            $deps=$account->exists?$this->dependencies($account->id):[];
            $children=$accounts->where('cuenta_padre_id',$id)->when($id===null,fn ($c)=>collect());
            $protected=$deps;$pending=$children->all();$seenChildren=[];
            while ($pending) {
                $child=array_pop($pending);
                if (isset($seenChildren[$child->id])) $this->invalid('Árbol existente cíclico; requiere revisión.');
                $seenChildren[$child->id]=true;
                $protected=array_merge($protected,$this->dependencies($child->id));
                foreach ($accounts->where('cuenta_padre_id',$child->id) as $descendant) $pending[]=$descendant;
            }
            foreach (['codigo','tipo','naturaleza','cuenta_padre_id','permite_movimiento'] as $field) {
                $new=$field==='cuenta_padre_id'?$parentId:($data[$field]??$account->$field);
                if ($account->exists && $protected && (string)$new!==(string)$account->$field) $this->invalid('Cuenta o rama utilizada: no se modifica su clasificación, código, jerarquía o imputabilidad.');
            }
            if ($account->exists && array_diff($deps,['detalle_asientos']) && isset($data['activa']) && !$data['activa']) $this->invalid('Existen asociaciones que requieren esta cuenta activa.');
            if ($account->exists && isset($data['activa']) && !$data['activa'] && $children->where('activa',true)->isNotEmpty()) $this->invalid('Existen subcuentas activas.');
            if ($children->isNotEmpty() && (($data['tipo']??$account->tipo)!==$account->tipo || ($data['naturaleza']??$account->naturaleza)!==$account->naturaleza)) $this->invalid('No se reclasifica una rama con descendientes.');
            $account->fill($data); $account->cuenta_padre_id=$parentId; $account->nivel=$parent?($parent->nivel+1):1;
            $account->save();
            $walk=function ($node) use (&$walk,$accounts) {
                foreach ($accounts->where('cuenta_padre_id',$node->id) as $child) {
                    if ($child->nivel!==$node->nivel+1) {
                        if ($this->dependencies($child->id)) $this->invalid('No se modifica una rama utilizada.');
                        $child->nivel=$node->nivel+1; $child->save();
                    }
                    $walk($child);
                }
            };
            $walk($account);
            return $account->refresh();
        });
    }

    public function remove(int $id): void
    {
        $this->catalogue(function ($accounts) use ($id) {
            $account=$accounts->get($id); if (!$account) abort(404);
            if ($account->is_system_account || $this->dependencies($id) || $accounts->where('cuenta_padre_id',$id)->isNotEmpty()) abort(403,'Cuenta protegida, utilizada o con descendientes: no se elimina.');
            $account->delete();
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['cuenta_padre_id'=>$message]);
    }
}
