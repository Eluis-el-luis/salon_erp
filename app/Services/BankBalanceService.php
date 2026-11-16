<?php

namespace App\Services;

use App\Models\CuentaBancaria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Locks bancarios locales; no calcula importes ni crea movimientos/asientos. */
final class BankBalanceService
{
    /** @return Collection<int, CuentaBancaria> Modelos actuales bloqueados hasta el commit exterior. */
    public function lockAccounts(array $ids): Collection
    {
        if (DB::transactionLevel()===0) throw new \LogicException('El saldo bancario requiere una transacción activa.');
        foreach($ids as $id)if(!is_int($id)||$id<=0)throw new \InvalidArgumentException('Cuenta bancaria inválida.');
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$accounts=collect();
        foreach($ids as $id) {
            $account=CuentaBancaria::whereKey($id)->lockForUpdate()->firstOrFail();
            $account->load(['moneda','banco']);
            if(!$account->activa || !in_array(strtoupper($account->moneda->codigo??''),['NIO','USD'],true)) {
                throw new \RuntimeException('La cuenta bancaria está inactiva o su moneda no está admitida.');
            }
            $accounts->put($id,$account);
        }
        return $accounts;
    }
}
