<?php

namespace App\Services;

use App\Http\Controllers\{ContabilidadController, PeriodoContableController};
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Internal test reader only. Does not render views or acquire posting authority. */
final class AccountingReportExtractor
{
    // Hard ceiling for this in-memory demonstration, not a business reporting limit.
    public const MAX_LEDGER_DETAILS = 1000;
    public const MAX_LEDGER_ACCOUNTS = 500;
    public function extract(array $classification, array $parameters): array
    {
        return $this->run($classification, $parameters, false);
    }

    public function compare(array $classification, array $parameters): array
    {
        return $this->run($classification, $parameters, true);
    }

    private function run(array $classification, array $p, bool $compare): array
    {
        $this->validate($p);
        // Validate the in-memory contract even if extraction returns no details.
        app(SyntheticAccountingPresentation::class)->read($classification, [], $p);
        if ($compare && $p['report'] === 'result') {
            $date = new DateTimeImmutable($p['from']);
            if ($date->format('d') !== '01' || $date->format('Y-m-t') !== $p['until']) {
                throw new DomainException('Legacy comparison requires one complete calendar month; extraction supports arbitrary ranges.');
            }
        }
        $c = DB::connection('mysql');
        $actor = auth()->user();
        if (!app()->environment('testing') || !defined('SALON_TEST_DATABASE_BOOTSTRAPPED')
            || !defined('SALON_TEST_DATABASE_RESET_ALLOWED') || SALON_TEST_DATABASE_RESET_ALLOWED
            || !$actor || !$actor->is_active || !in_array($actor->role, ['alvaro', 'contador'], true)
            || DB::getDefaultConnection() !== 'mysql' || $c->getDatabaseName() !== 'salon_erp_test'
            || $c->getConfig('username') !== 'salon_erp_tester') {
            throw new DomainException('Internal restricted test reader required.');
        }
        $identity = $c->selectOne('SELECT DATABASE() db, CURRENT_USER() actor, @@port port, @@transaction_isolation isolation_level');
        if ($identity->db !== 'salon_erp_test' || $identity->actor !== 'salon_erp_tester@localhost' || (int)$identity->port !== 3306) {
            throw new DomainException('Unexpected reader identity.');
        }
        $outer = $c->transactionLevel();
        if ($outer && $identity->isolation_level !== 'REPEATABLE-READ') {
            throw new DomainException('Enclosing fixture transaction must use REPEATABLE READ.');
        }
        if (!$outer) $c->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $c->beginTransaction();
        try {
            // Same snapshot as extraction and global legacy reads; never silently truncate a report.
            if ($c->table('detalle_asientos')->count() > self::MAX_LEDGER_DETAILS
                || $c->table('cuentas_contables')->count() > self::MAX_LEDGER_ACCOUNTS) {
                throw new DomainException('Demonstration volume limit exceeded; no complete report was produced.');
            }
            if ($p['account_scope'] !== 'all' && $c->table('cuentas_contables')->whereIn('id',$p['account_scope'])->count() !== count($p['account_scope'])) {
                throw new DomainException('Unknown account scope reference.');
            }
            if (is_array($p['center_scope']) && $c->table('centros_costo')->whereIn('id',$p['center_scope'])->count() !== count($p['center_scope'])) {
                throw new DomainException('Unknown center scope reference.');
            }
            if (isset($p['period_id']) && !$c->table('periodos_contables')->where('id',$p['period_id'])->exists()) {
                throw new DomainException('Unknown period reference.');
            }
            $q = $c->table('detalle_asientos as d')->join('asientos_contables as a', 'a.id', '=', 'd.asiento_id')
                ->join('cuentas_contables as c', 'c.id', '=', 'd.cuenta_id')
                ->join('periodos_contables as p', 'p.id', '=', 'a.periodo_id')
                ->leftJoin('centros_costo as cc', 'cc.id', '=', 'd.centro_costo_id')
                ->whereDate('a.fecha', '<=', $p['until']);
            if ($p['report'] === 'result') $q->whereDate('a.fecha', '>=', $p['from']);
            if ($p['account_scope'] !== 'all') $q->whereIn('d.cuenta_id', $p['account_scope']);
            // Broad candidate set: do not silently restrict legacy to synthetic states/period/centers/origin.
            $source = $q->orderBy('d.id')->get(['d.*', 'a.fecha', 'a.periodo_id', 'a.modulo_origen', 'a.estado',
                'a.numero_asiento', 'a.referencia_id', 'a.created_at as entry_created_at',
                'a.tipo_asiento', 'a.accounting_policy_version_id', 'a.accounting_policy_hash', 'a.accounting_authorization_id',
                'c.codigo', 'c.nombre', 'c.tipo', 'c.naturaleza', 'c.activa', 'c.permite_movimiento', 'c.cuenta_padre_id',
                'p.estado as period_state', 'p.fecha_inicio as period_from', 'p.fecha_fin as period_until',
                'cc.nombre as center_name', 'cc.activo as center_active'])->map(fn($r) => [
                    'detail_id'=>(int)$r->id, 'entry_id'=>(int)$r->asiento_id, 'account_id'=>(int)$r->cuenta_id,
                    'period_id'=>(int)$r->periodo_id, 'date'=>$r->fecha, 'origin'=>$r->modulo_origen, 'state'=>$r->estado,
                    'debit'=>$r->debe, 'credit'=>$r->haber, 'currency_id'=>(int)$r->moneda_id,
                    'exchange_rate_id'=>$r->tasa_cambio_id, 'center_id'=>$r->centro_costo_id === null ? null : (int)$r->centro_costo_id,
                    'description'=>$r->descripcion, 'entry_number'=>$r->numero_asiento, 'reference_id'=>$r->referencia_id,
                    'entry_type'=>$r->tipo_asiento, 'original_policy_version_id'=>$r->accounting_policy_version_id,
                    'original_policy_hash'=>$r->accounting_policy_hash, 'original_authorization_id'=>$r->accounting_authorization_id,
                    'entry_created_at'=>$r->entry_created_at, 'period_state'=>$r->period_state,
                    'period_from'=>$r->period_from, 'period_until'=>$r->period_until,
                    'current_account'=>['code'=>$r->codigo, 'name'=>$r->nombre, 'type'=>$r->tipo, 'nature'=>$r->naturaleza,
                        'active'=>(bool)$r->activa, 'postable'=>(bool)$r->permite_movimiento, 'parent_id'=>$r->cuenta_padre_id],
                    'current_center'=>['name'=>$r->center_name, 'active'=>$r->center_active],
                ])->all();
            $selected = array_values(array_filter($source, fn($r) => $p['center_scope'] === 'all'
                || ($p['center_scope'] === 'unassigned' ? $r['center_id'] === null : in_array($r['center_id'], $p['center_scope'], true))));
            $legacy = $reported = $map = [];
            if ($compare) {
                if ($p['report'] === 'result') {
                    $view = app(ContabilidadController::class)->resultados(Request::create('/', 'GET', ['mes'=>substr($p['from'],0,7)]))->getData();
                    $ids = [];
                    foreach ($view['cuentasResultados'] as $account) foreach ($account->detalles as $detail) $ids[$detail->id] = true;
                    $legacy = array_values(array_filter($source, fn($r) => isset($ids[$r['detail_id']])));
                    $reported = array_intersect_key($view, array_flip(['ingresos','costos','gastosOperativos','otrosEgresos','utilidadNeta']));
                } else {
                    $view = app(PeriodoContableController::class)->balanceGeneral(Request::create('/', 'GET', ['fecha_corte'=>$p['until']]))->getData();
                    // Exact balance selection: all postable accounts through cutoff, including nominal/closing details.
                    $legacy = array_values(array_filter($source, fn($r) => $r['current_account']['postable']));
                    $reported = array_intersect_key($view, array_flip(['totalActivo','totalPasivo','totalPatrimonio']));
                }
                foreach ($legacy as $row) {
                    // This is the documented legacy adapter, never a source of proposed assignments.
                    $first = substr($row['current_account']['code'],0,1);
                    $map[$row['account_id']] = match($first) {
                        '1'=>'asset', '2'=>'liability', '3'=>'equity', '4'=>'income', '5'=>'cost', '6'=>'operating_expense', default=>'other_expense',
                    };
                }
            }
            $result = app(AccountingPresentationComparison::class)->read($classification, $p, $selected, $legacy, $map, $compare);
            $result['source_details'] = $source;
            $result['legacy_reported_global'] = $reported;
            $result['snapshot'] = ['isolation'=>'REPEATABLE READ', 'read_only_transaction'=>!$outer,
                'enclosing_fixture_transaction'=>(bool)$outer, 'connection_id'=>(int)$c->selectOne('SELECT CONNECTION_ID() id')->id,
                'captured_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'), 'issued_statement'=>false];
            $c->commit();
            return $result;
        } catch (\Throwable $e) {
            $c->rollBack();
            throw $e;
        }
    }

    private function validate(array $p): void
    {
        $allowed=['report','from','until','period_id','states','null_origin','account_scope','center_scope'];
        if (array_diff(array_keys($p),$allowed)) throw new DomainException('Unknown authoritative reading parameter.');
        foreach (['account_scope','center_scope'] as $key) {
            $value=$p[$key]??null;
            if ($value==='all' || ($key==='center_scope' && $value==='unassigned')) continue;
            if (!is_array($value) || !$value || !array_is_list($value) || count(array_unique($value,SORT_REGULAR))!==count($value)) throw new DomainException('Explicit unique reading scope required.');
            foreach ($value as $id) if (!is_int($id) || $id<1) throw new DomainException('Invalid scope ID.');
        }
        if (!isset($p['states']) || !array_is_list($p['states']) || count(array_unique($p['states']))!==count($p['states'])) throw new DomainException('Explicit unique states required.');
    }
}
