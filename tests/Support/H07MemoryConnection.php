<?php

namespace Tests\Support;

use Illuminate\Database\MySqlConnection;

/** Doble estricto para Eloquent. No abre PDO ni demuestra aislamiento InnoDB. */
final class H07MemoryConnection extends MySqlConnection
{
    public array $rows = [];
    public array $trace = [];
    public array $queries = [];
    public array $snapshots = [];
    public ?string $failAfterInsert = null;
    public bool $unbalancedAggregate = false;
    public ?\Closure $afterInsert = null;

    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido: H07 es puro.'), 'h07-memory-only', '', ['name' => 'mysql']);
        foreach (['employees', 'users', 'salary_structures', 'salary_structure_details', 'salary_slips',
            'payroll_runs', 'salary_slip_details', 'payrolls', 'attendances', 'leave_applications', 'leave_types',
            'comisiones_generadas', 'tips', 'adelanto_cuotas', 'adelantos', 'asientos_contables', 'detalle_asientos'] as $table) {
            $this->rows[$table] = [];
        }
    }

    public function beginTransaction()
    {
        $this->snapshots[] = $this->rows;
        ++$this->transactions;
        $this->trace[] = 'begin:'.$this->transactions;
    }

    public function commit()
    {
        array_pop($this->snapshots);
        $this->trace[] = 'commit:'.$this->transactions;
        --$this->transactions;
    }

    public function rollBack($toLevel = null)
    {
        if ($toLevel !== null || !$this->snapshots) throw new \LogicException('Rollback inesperado.');
        $this->rows = array_pop($this->snapshots);
        $this->trace[] = 'rollback:'.$this->transactions;
        --$this->transactions;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->queries[] = [$query, $bindings];
        if (!preg_match('/from `([a-z_]+)`/', $query, $m) || !isset($this->rows[$m[1]])) {
            throw new \LogicException('SELECT inesperado: '.$query);
        }
        $table = $m[1];
        $rows = array_values($this->rows[$table]);
        if ($table === 'adelanto_cuotas' && str_contains($query, 'exists')) {
            $userId = array_shift($bindings);
            $rows = array_filter($rows, fn ($r) => ($this->rows['adelantos'][$r['adelanto_id']]['user_id'] ?? null) == $userId);
            $query = preg_replace('/exists \(select .*?\) and /', '', $query);
        }
        if (preg_match('/ where (.*?)(?: order by| limit | for update|$)/', $query, $m)) {
            $rows = $this->filter($rows, $m[1], $bindings);
        } elseif ($bindings) {
            throw new \LogicException('Bindings sin filtro: '.$query);
        }
        if (str_contains($query, 'SUM(debe)')) {
            return [(object)['total_debe' => array_sum(array_column($rows, 'debe')),
                'total_haber' => array_sum(array_column($rows, 'haber')) + ($this->unbalancedAggregate ? 0.01 : 0)]];
        }
        // El doble también debe respetar el orden SQL de cuotas y conceptos.
        if (preg_match('/ order by (.*?)(?: limit | for update|$)/', $query, $order)) {
            preg_match_all('/`(?:[a-z_]+`\.)?([a-z_]+)` (asc|desc)/', $order[1], $columns, PREG_SET_ORDER);
            usort($rows, function ($a, $b) use ($columns) {
                foreach ($columns as $column) {
                    $result = ($a[$column[1]] ?? null) <=> ($b[$column[1]] ?? null);
                    if ($result) return $column[2] === 'asc' ? $result : -$result;
                }
                return 0;
            });
        }
        if (str_contains($query, 'limit 1')) $rows = array_slice($rows, 0, 1);
        return array_map(fn ($r) => (object)$r, array_values($rows));
    }

    private function filter(array $rows, string $where, array $bindings): array
    {
        $where = preg_replace('/`[a-z_]+`\./', '', $where);
        $where = preg_replace('/date\((`[a-z_]+`)\)/', '$1', $where);
        $pattern = '/`([a-z_]+)` (between \? and \?|(?:not )?in \([^)]*\)|is not null|is null|(?:not like|!=|<>|<=|>=|=|<|>) \?)/';
        preg_match_all($pattern, $where, $matches, PREG_SET_ORDER);
        $residue = preg_replace($pattern, '', $where);
        if (trim(str_replace(['and', '(', ')'], '', $residue))) throw new \LogicException('Filtro inesperado: '.$where);
        $cursor = 0;
        foreach ($matches as $m) {
            [$full, $column, $op] = $m;
            if (str_starts_with($op, 'between')) {
                $low = (string)$bindings[$cursor++]; $high = (string)$bindings[$cursor++];
                $rows = array_filter($rows, fn ($r) => $r[$column] >= $low && $r[$column] <= $high);
            } elseif (str_contains($op, 'in (')) {
                $count = substr_count($op, '?');
                $values = $count ? array_slice($bindings, $cursor, $count) : explode(',', preg_replace('/.*\((.*)\)/', '$1', $op));
                $cursor += $count;
                $rows = array_filter($rows, fn ($r) => in_array($r[$column] ?? null, $values) !== str_starts_with($op, 'not'));
            } elseif (str_starts_with($op, 'is')) {
                $rows = array_filter($rows, fn ($r) => (($r[$column] ?? null) !== null) === ($op === 'is not null'));
            } else {
                $value = $bindings[$cursor++];
                $rows = array_filter($rows, fn ($r) => match (str_replace(' ?', '', $op)) {
                    '=' => ($r[$column] ?? null) == $value,
                    '!=', '<>' => ($r[$column] ?? null) != $value,
                    '<=' => $r[$column] <= $value,
                    '>=' => $r[$column] >= $value,
                    'not like' => !str_starts_with($r[$column], rtrim($value, '%')),
                    default => throw new \LogicException('Operador inesperado: '.$op),
                });
            }
        }
        if ($cursor !== count($bindings)) throw new \LogicException('Bindings no consumidos: '.$where);
        return $rows;
    }

    public function insert($query, $bindings = [], $sequence = null)
    {
        if (!preg_match('/insert into `([a-z_]+)` \((.*?)\) values/', $query, $m)) throw new \LogicException($query);
        $table = $m[1];
        $this->assertWritable($table);
        preg_match_all('/`([a-z_]+)`/', $m[2], $columns);
        $row = array_combine($columns[1], $bindings);
        $row['id'] = $this->lastInsertId = ($this->rows[$table] ? max(array_keys($this->rows[$table])) : 0) + 1;
        $this->rows[$table][$row['id']] = $row;
        $this->trace[] = 'insert:'.$table;
        if ($this->afterInsert) ($this->afterInsert)($this, $table, $row);
        if ($table === $this->failAfterInsert) throw new \Error('h07.after.'.$table);
        return true;
    }

    public function update($query, $bindings = [])
    {
        if (!preg_match('/update `([a-z_]+)` set (.*?) where (.*)/', $query, $m)) throw new \LogicException($query);
        $table = $m[1]; $this->assertWritable($table);
        preg_match_all('/`([a-z_]+)` = \?/', $m[2], $columns);
        $changes = array_combine($columns[1], array_splice($bindings, 0, count($columns[1])));
        $rows = $this->filter($this->rows[$table], $m[3], $bindings);
        foreach ($rows as $row) $this->rows[$table][$row['id']] = array_replace($row, $changes);
        $this->trace[] = 'update:'.$table;
        return count($rows);
    }

    private function assertWritable(string $table): void
    {
        if (!$this->transactions || !in_array($table, ['payroll_runs', 'salary_slips', 'salary_slip_details',
            'comisiones_generadas', 'tips', 'adelanto_cuotas', 'asientos_contables', 'detalle_asientos'])) {
            throw new \LogicException('Escritura inesperada en doble: '.$table);
        }
    }
}
