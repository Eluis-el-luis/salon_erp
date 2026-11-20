<?php

namespace App\Services;

use DateTimeImmutable;
use DomainException;

/** In-memory reading experiment. No posting authority, persistence or operational consumer. */
final class SyntheticAccountingPresentation
{
    private const GROUPS = [
        'asset' => 'debit', 'liability' => 'credit', 'equity' => 'credit',
        'income' => 'credit', 'cost' => 'debit', 'operating_expense' => 'debit', 'other_expense' => 'debit',
    ];

    public function read(array $classification, iterable $details, array $parameters): array
    {
        if (($classification['contract'] ?? null) !== 'synthetic-presentation-v1'
            || ($classification['purpose'] ?? null) !== 'simulation'
            || !is_string($classification['version'] ?? null) || $classification['version'] === '') {
            throw new DomainException('Explicit synthetic classification required.');
        }
        $assignments = [];
        foreach ($classification['assignments'] ?? [] as $assignment) {
            $id = $assignment['account_id'] ?? null;
            $group = $assignment['group'] ?? '';
            if (!is_int($id) || $id < 1 || !isset(self::GROUPS[$group]) || isset($assignments[$id])) {
                throw new DomainException('Invalid, ambiguous or contradictory account assignment.');
            }
            $assignments[$id] = $group;
        }
        ksort($assignments, SORT_NUMERIC);
        $report = $parameters['report'] ?? null;
        $from = $parameters['from'] ?? null;
        $until = $this->date($parameters['until'] ?? '');
        if (!in_array($report, ['result', 'balance'], true)
            || !in_array($parameters['null_origin'] ?? null, ['include', 'exclude'], true)
            || !isset($parameters['states']) || !is_array($parameters['states']) || !$parameters['states']
            || array_diff($parameters['states'], ['borrador', 'contabilizado', 'anulado'])) {
            throw new DomainException('Explicit reading universe required.');
        }
        if ($report === 'result') {
            $from = $this->date($from ?? '');
            if ($from > $until) throw new DomainException('Invalid interval.');
        } elseif ($from !== null || isset($parameters['period_id'])) {
            throw new DomainException('Balance must accumulate through its cutoff.');
        }
        if (isset($parameters['period_id']) && (!is_int($parameters['period_id']) || $parameters['period_id'] < 1)) {
            throw new DomainException('Invalid period reference.');
        }
        $groups = array_fill_keys(array_keys(self::GROUPS), 0);
        $accounts = $rows = $seen = $detailContributions = [];
        $debit = $credit = 0;
        foreach ($details as $row) {
            foreach (['detail_id', 'entry_id', 'account_id', 'period_id'] as $key) {
                if (!is_int($row[$key] ?? null) || $row[$key] < 1) throw new DomainException('Incomplete original reference.');
            }
            if (isset($seen[$row['detail_id']])) throw new DomainException('Repeated original detail.');
            $seen[$row['detail_id']] = true;
            $date = $this->date($row['date'] ?? '');
            if (!array_key_exists('origin', $row) || (!is_null($row['origin']) && !is_string($row['origin']))) {
                throw new DomainException('Missing original origin.');
            }
            $d = $this->cents($row['debit'] ?? null);
            $h = $this->cents($row['credit'] ?? null);
            if ($date > $until || ($from !== null && $date < $from)
                || !in_array($row['state'] ?? null, $parameters['states'], true)
                || ($row['origin'] === null && $parameters['null_origin'] === 'exclude')
                || (isset($parameters['period_id']) && $row['period_id'] !== $parameters['period_id'])
                || ($report === 'result' && $row['origin'] === 'cierre_fiscal')) continue;
            $group = $assignments[$row['account_id']] ?? null;
            if ($group === null) {
                if ($d !== 0 || $h !== 0) throw new DomainException('Relevant original detail has no classification.');
                continue;
            }
            $contribution = self::GROUPS[$group] === 'debit' ? $d - $h : $h - $d;
            $groups[$group] = $this->sum($groups[$group], $contribution);
            $debit = $this->sum($debit, $d);
            $credit = $this->sum($credit, $h);
            $account = $row['account_id'];
            $accounts[$account] ??= ['group' => $group, 'debit_cents' => 0, 'credit_cents' => 0, 'contribution_cents' => 0];
            foreach (['debit_cents' => $d, 'credit_cents' => $h, 'contribution_cents' => $contribution] as $key => $value) {
                $accounts[$account][$key] = $this->sum($accounts[$account][$key], $value);
            }
            // Originals remain unchanged; no role/binding or hierarchy join can multiply them.
            $rows[$row['detail_id']] = $row;
            $detailContributions[$row['detail_id']] = $contribution;
        }
        ksort($accounts, SORT_NUMERIC);
        ksort($rows, SORT_NUMERIC);
        $gross = $this->sum($groups['income'], -$groups['cost']);
        $operating = $this->sum($gross, -$groups['operating_expense']);
        $result = $this->sum($operating, -$groups['other_expense']);
        $hash = hash('sha256', json_encode([
            'contract' => $classification['contract'], 'purpose' => 'simulation',
            'version' => $classification['version'], 'assignments' => $assignments, 'group_signs' => self::GROUPS,
        ], JSON_THROW_ON_ERROR));
        return ['issued_statement' => false, 'classification_hash' => $hash, 'classification_version' => $classification['version'],
            'parameters' => $parameters, 'groups_cents' => $groups, 'accounts' => $accounts, 'details' => $rows,
            'detail_contributions_cents' => $detailContributions,
            'debit_cents' => $debit, 'credit_cents' => $credit,
            // Derived visual subtotals never contribute a second time to economic groups.
            'subtotals_cents' => ['gross' => $gross, 'operating' => $operating, 'result' => $result],
            'pending_result_cents' => $report === 'balance' ? $result : null,
            'balance_difference_cents' => $report === 'balance'
                ? $this->sum($this->sum($groups['asset'], -$groups['liability']), -$this->sum($groups['equity'], $result)) : null];
    }

    private function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException('Exact date required.');
        return $date;
    }

    private function cents(mixed $value): int
    {
        // Signed legacy values are preserved, including pathological closing lines.
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,12})\.[0-9]{2}$/D', $value)) {
            throw new DomainException('Original DECIMAL(15,2) string required.');
        }
        return (str_starts_with($value, '-') ? -1 : 1) * (int) str_replace(['-', '.'], '', $value);
    }

    private function sum(int $a, int $b): int
    {
        $sum = $a + $b;
        if (!is_int($sum)) throw new DomainException('Accumulated amount exceeds integer cents.');
        return $sum;
    }
}
