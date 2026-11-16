<?php

namespace App\Services;

use App\Models\MovimientoBancario;
use App\Support\{AccountingSimulationBoundary as Boundary,AccountingSimulationPosting};
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Escritor interno AC-04B2A. Sin rutas, comandos empresariales o permisos de producción. */
final class AccountingBankChargeSimulation
{
    private function reject(string $m): never {throw ValidationException::withMessages(['simulation'=>$m]);}
    private function expected(object $g,array $n): array {return app(AccountingEventIdentityService::class)->simulationContent($g,$n,(int)auth()->id());}
    private function sameContent(object $o,object $g,array $n): void {
        if((int)$o->created_by!==(int)auth()->id())abort(403);app(AccountingEventIdentityService::class)->verifyDocument($o);
        if($o->purpose!=='simulation'||$o->environment!=='testing'||$o->content_hash!==hash('sha256',AccountingPolicyService::canonical($this->expected($g,$n))))$this->reject('UUID existente con contenido, actor o autorización distintos.');
    }
    public function executeForInternalHarness(int $grantId,array $input,string $contract): object {
        Boundary::assert($contract);
        return $this->executeWithinBoundary($grantId,$input,$contract);
    }
    public function executeForSyntheticHttp(int $grantId,array $input): object {
        $contract=\App\Support\AccountingHttpSimulationBoundary::CONTRACT;
        \App\Support\AccountingHttpSimulationBoundary::assert($contract);
        $g=DB::table('accounting_policy_authorizations')->find($grantId);
        if(!$g||(int)$g->actor_id!==(int)auth()->id())abort(403);
        try { return $this->executeWithinBoundary($grantId,$input,$contract); }
        catch(ValidationException $e){if(str_contains($e->getMessage(),'UUID existente'))abort(409,'UUID original con contenido distinto.');throw $e;}
    }
    private function executeWithinBoundary(int $grantId,array $input,string $contract): object {
        \App\Support\AccountingSimulationAccess::assert($contract);$identity=app(AccountingEventIdentityService::class);$n=$identity->normalize($input);
        // Un resultado ya confirmado es historia: no volver a resolver cuentas o habilitación vigente.
        $peek=DB::table('accounting_event_operations')->where('operation_uuid',$n['uuid'])->first();
        if($peek&&$peek->status==='confirmed') {
            $g=DB::table('accounting_policy_authorizations')->find($grantId);if(!$g)$this->reject('Referencia inexistente.');$this->sameContent($peek,$g,$n);return $this->verifiedResult($peek);
        }
        $entryLevel=DB::transactionLevel();$at=DateTimeImmutable::createFromInterface(now('UTC'));
        try {
            return DB::transaction(function()use($grantId,$n,$contract,$identity,$at,$entryLevel){
                $g=app(AccountingEventAuthorizationService::class)->pinForSimulationContext($grantId,$at,$contract);
                AccountingSimulationPosting::assertPolicyScope((int)$g->policy_version_id,(int)$g->bank_account_id);
                // Igual orden relativo que el preparador B1: recursos/cuentas antes de UUID.
                app(BankBalanceService::class)->lockAccounts([(int)$g->bank_account_id]);
                app(AccountingEventAuthorizationService::class)->validatePinnedForSimulationContext($g->id,$at,$contract);
                $content=$this->expected($g,$n);$hash=hash('sha256',AccountingPolicyService::canonical($content));
                $attrs=['operation_uuid'=>$n['uuid'],'content_hash'=>$hash,'content'=>AccountingPolicyService::canonical($content),
                    'policy_version_id'=>$g->policy_version_id,'policy_hash'=>$g->policy_hash,'purpose'=>'simulation','event_key'=>$g->event_key,
                    'bank_account_id'=>$g->bank_account_id,'currency'=>'NIO','environment'=>'testing','authorization_id'=>$g->id,'created_by'=>auth()->id(),
                    'classification'=>$n['classification'],'amount'=>$n['amount'],'recognition_date'=>$n['recognition_date'],'status'=>'prepared','recorded_at'=>$at->format('Y-m-d H:i:s')];
                try {$opId=DB::table('accounting_event_operations')->insertGetId($attrs);}
                catch(QueryException $e) {
                    if(($e->errorInfo[1]??0)!==1062)throw $e;
                    $old=DB::table('accounting_event_operations')->where('operation_uuid',$n['uuid'])->lockForUpdate()->first();if(!$old)throw $e;
                    $this->sameContent($old,$g,$n);if($old->status==='confirmed')return $this->verifiedResult($old);
                    if($old->status!=='prepared'||$old->result_movement_id!==null||$old->result_asiento_id!==null)$this->reject('Preparado incompleto o incompatible.');$opId=$old->id;
                }
                $permit=AccountingSimulationPosting::forContext($opId,$grantId,$at,$contract);
                $bank=app(BankBalanceService::class)->lockAccounts([$permit->bankId])->get($permit->bankId);$previous=(float)$bank->saldo_actual;
                $movement=MovimientoBancario::create(['cuenta_bancaria_id'=>$bank->id,'moneda_id'=>$bank->moneda_id,'tipo'=>'cargo','monto'=>$n['amount'],
                    'fecha'=>$n['recognition_date'],'concepto'=>$n['description'],'modulo_origen'=>'cargos_bancarios','referencia_id'=>0,
                    'referencia_banco'=>$n['reference'],'estado_conciliacion'=>'pendiente']);$movement->refresh();
                $entry=app(ContabilidadService::class)->contabilizarMovimientoBancario($movement,$permit->expenseId,$permit);
                $movement->asiento_contable_id=$entry;$movement->save();
                DB::table('accounting_event_operations')->where('id',$opId)->update(['status'=>'confirmed','result_movement_id'=>$movement->id,'result_asiento_id'=>$entry]);
                $lines=DB::table('detalle_asientos')->where('asiento_id',$entry)->orderBy('id')->get();if($lines->count()!==2)$this->reject('Dos líneas requeridas.');
                foreach($permit->resolutions as $r) {
                    $line=$lines->firstWhere('cuenta_id',$r['account_id']);if(!$line)$this->reject('Cuenta de línea incompatible.');
                    DB::table('accounting_entry_line_resolutions')->insert(['operation_id'=>$opId,'policy_version_id'=>$g->policy_version_id,'policy_hash'=>$g->policy_hash,
                        'authorization_id'=>$g->id,'bank_account_id'=>$bank->id,'movement_id'=>$movement->id,'asiento_id'=>$entry,'detail_id'=>$line->id,
                        'binding_id'=>$r['binding_id'],'account_id'=>$r['account_id'],'semantic_role'=>$r['role'],'contract_version'=>1,'recorded_at'=>$at->format('Y-m-d H:i:s')]);
                }
                $bank->saldo_actual=round($previous-(float)$n['amount'],2);$bank->save();
                $op=DB::table('accounting_event_operations')->where('id',$opId)->lockForUpdate()->first();$result=$this->verifiedResult($op);
                if((int)round((float)$bank->refresh()->saldo_actual*100)!==(int)round(($previous-(float)$n['amount'])*100))$this->reject('Saldo no conciliado.');
                app(ContabilidadService::class)->validarCuadre($entry);
                if(DB::transactionLevel()!==$entryLevel+1)throw new \LogicException('Nivel transaccional inesperado.');
                return $result;
            });
        } catch(\Throwable $e) {
            // También cubre Throwable que deje abierta una transacción anidada heredada.
            while(DB::transactionLevel()>$entryLevel)DB::rollBack();
            if($e instanceof QueryException&&in_array($e->errorInfo[1]??0,[1205,1213],true))$this->reject('Contención transaccional; no se reintentó el cargo.');throw $e;
        }
    }
    private function verifiedResult(object $o): object {
        app(AccountingEventIdentityService::class)->confirmedResult($o->operation_uuid);
        $current=fn($q)=>DB::transactionLevel()>0?$q->sharedLock():$q;
        $c=json_decode($o->content,true);$m=$current(DB::table('movimientos_bancarios')->where('id',$o->result_movement_id))->first();$e=$current(DB::table('asientos_contables')->where('id',$o->result_asiento_id))->first();
        if(!$m||!$e||$m->tipo!=='cargo'||(int)$m->cuenta_bancaria_id!==(int)$o->bank_account_id||(int)$m->asiento_contable_id!==(int)$e->id
            ||$m->monto!==$o->amount||$m->fecha!==$o->recognition_date||$m->referencia_banco!==$c['reference']||$e->fecha!==$o->recognition_date
            ||$e->modulo_origen!=='cargos_bancarios'||(int)$e->referencia_id!==(int)$m->id||(int)$e->accounting_policy_version_id!==(int)$o->policy_version_id
            ||$e->accounting_policy_hash!==$o->policy_hash||(int)$e->accounting_authorization_id!==(int)$o->authorization_id)$this->reject('Resultado documental incoherente.');
        $base=DB::table('monedas')->where('codigo','NIO')->value('id');$lines=$current(DB::table('detalle_asientos')->where('asiento_id',$e->id))->get();$res=$current(DB::table('accounting_entry_line_resolutions')->where('operation_id',$o->id))->get();
        if($lines->count()!==2||$res->count()!==2)$this->reject('Resultado sin dos correspondencias.');
        foreach($res as $r) {
            $l=$lines->firstWhere('id',$r->detail_id);$debit=$r->semantic_role==='gasto.cargo_bancario';
            if(!$l||(int)$l->cuenta_id!==(int)$r->account_id||(int)$l->moneda_id!==(int)$base||($debit?$l->debe:$l->haber)!==$o->amount
                ||(float)($debit?$l->haber:$l->debe)!==0.0)$this->reject('Importe o cuenta original de línea incoherentes.');
        }
        return $o;
    }
}
