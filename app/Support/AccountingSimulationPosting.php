<?php

namespace App\Support;

use App\Services\{AccountingEventAuthorizationService,AccountingEventIdentityService,AccountingAccountResolver,BankBalanceService};
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Evidencia tipada de ensayo; se revalida al entrar en el motor contable. */
final readonly class AccountingSimulationPosting
{
    private function __construct(public int $operationId,public int $authorizationId,public int $bankId,public int $expenseId,public int $fundsId,public array $resolutions,public DateTimeImmutable $at) {}
    public static function assertPolicyScope(int $versionId,int $bankId): void {
        foreach(DB::table('accounting_account_bindings')->where('policy_version_id',$versionId)->orderBy('id')->sharedLock()->get() as $b) {
            if($b->item_id!==null||($b->bank_account_id!==null&&(int)$b->bank_account_id!==$bankId))throw new \LogicException('El piloto no admite recursos ajenos al banco fijado.');
        }
    }
    public static function forPrepared(int $id,int $authorizationId,DateTimeImmutable $at,string $contract): self {
        AccountingSimulationBoundary::assert($contract);if(DB::transactionLevel()===0)throw new \LogicException('Se requiere transacción exterior.');
        $auth=app(AccountingEventAuthorizationService::class);$g=DB::table('accounting_policy_authorizations')->find($authorizationId);
        if(!$g)throw new \LogicException('Habilitación inexistente.');
        DB::table('accounting_policy_versions')->where('id',$g->policy_version_id)->sharedLock()->first();
        self::assertPolicyScope((int)$g->policy_version_id,(int)$g->bank_account_id);
        $bank=app(BankBalanceService::class)->lockAccounts([(int)$g->bank_account_id])->get((int)$g->bank_account_id);
        $auth->validatePinnedForInternalHarness($g->id,$at,$contract);
        $o=DB::table('accounting_event_operations')->where('id',$id)->lockForUpdate()->first();app(AccountingEventIdentityService::class)->verifyDocument($o);
        if((int)$o->authorization_id!==$authorizationId||$o->status!=='prepared'||$o->purpose!=='simulation'||$o->environment!=='testing'||(int)$o->created_by!==(int)auth()->id()||$o->result_asiento_id!==null||$o->result_movement_id!==null)throw new \LogicException('Documento de ensayo incompatible.');
        $r=app(AccountingAccountResolver::class)->inspectPinnedForInternalHarness([
            ['role'=>'gasto.cargo_bancario','scope_type'=>'bank_account','resource_id'=>$bank->id],
            ['role'=>'fondos.banco','scope_type'=>'bank_account','resource_id'=>$bank->id]],$at,(int)$g->policy_version_id,$contract);
        if((int)$o->bank_account_id!==$bank->id||$r['resolved'][1]['account_id']!==(int)$bank->cuenta_contable_id||$r['policy_hash']!==$o->policy_hash)throw new \LogicException('No se permite cambiar la cuenta que conserva el saldo.');
        return new self($id,$authorizationId,$bank->id,$r['resolved'][0]['account_id'],$r['resolved'][1]['account_id'],$r['resolved'],$at);
    }
    public function assertMovement(object $m,int $expense): object {
        $fresh=self::forPrepared($this->operationId,$this->authorizationId,$this->at,AccountingSimulationBoundary::CONTRACT);
        if($fresh->resolutions!==$this->resolutions||$expense!==$this->expenseId||$fresh->fundsId!==$this->fundsId)throw new \LogicException('Evidencia de resolución cambió.');
        $o=DB::table('accounting_event_operations')->where('id',$this->operationId)->lockForUpdate()->first();
        $date=\Carbon\Carbon::parse($m->fecha)->toDateString();
        $currency=DB::table('cuentas_bancarias')->where('id',$this->bankId)->sharedLock()->value('moneda_id');
        if($m->tipo!=='cargo'||(int)$m->cuenta_bancaria_id!==$this->bankId||(int)$m->moneda_id!==(int)$currency||(string)$m->monto!==(string)$o->amount||$date!==$o->recognition_date)throw new \LogicException('Movimiento incompatible con el contrato de ensayo.');
        return $o;
    }
}
