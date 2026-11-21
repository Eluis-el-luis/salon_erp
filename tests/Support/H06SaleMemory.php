<?php

namespace Tests\Support;

use Illuminate\Database\MySqlConnection;

/** Almacenamiento sintético para el controlador/libro reales; nunca resuelve PDO. */
final class H06SaleMemory extends MySqlConnection
{
    public array $rows = ['services' => [], 'cash_sessions' => [], 'sales' => [], 'sale_details' => [], 'pagos' => []];
    public array $effects = [];
    public array $trace = [];
    private array $snapshots = [];
    private array $next = [];

    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido en H06.'), 'h06-memory-only', '', ['name' => 'mysql']);
    }
    public function beginTransaction()
    {
        $this->snapshots[] = [$this->rows, $this->effects]; ++$this->transactions;
    }
    public function commit() { array_pop($this->snapshots); --$this->transactions; $this->trace[] = 'commit'; }
    public function rollBack($toLevel = null)
    {
        if (!$this->snapshots || $toLevel !== null) throw new \LogicException('Rollback inesperado.');
        [$this->rows, $this->effects] = array_pop($this->snapshots); --$this->transactions; $this->trace[] = 'rollback';
    }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (!preg_match('/from `([a-z_]+)`/', $sql, $table) || !isset($this->rows[$table[1]])) throw new \LogicException($sql);
        $rows = array_values($this->rows[$table[1]]);
        if (preg_match('/ where (.*?)(?:\) as| order by| limit | for update|$)/', $sql, $where)) $rows = $this->filter($rows, $where[1], $bindings);
        if (str_starts_with($sql, 'select exists')) return [(object)['exists' => (bool)$rows]];
        if (str_contains($sql, 'limit 1')) $rows = array_slice($rows, 0, 1);
        return array_map(fn ($r) => (object)$r, array_values($rows));
    }
    private function filter(array $rows, string $where, array $bindings): array
    {
        $where = preg_replace('/`[a-z_]+`\./', '', $where);
        $pattern = '/`([a-z_]+)` (= \?|in \([^)]*\))/';
        preg_match_all($pattern, $where, $clauses, PREG_SET_ORDER);
        if (trim(str_replace('and', '', preg_replace($pattern, '', $where)))) throw new \LogicException($where);
        $i = 0;
        foreach ($clauses as [, $column, $op]) {
            $n = substr_count($op, '?');
            $values = $n ? array_slice($bindings, $i, $n) : explode(',', preg_replace('/.*\((.*)\)/', '$1', $op));
            $i += $n;
            $rows = array_filter($rows, fn ($r) => in_array($r[$column] ?? null, $values));
        }
        if ($i !== count($bindings)) throw new \LogicException('Bindings no consumidos.');
        return $rows;
    }
    public function insert($sql, $bindings = [], $sequence = null)
    {
        if (!preg_match('/insert into `([a-z_]+)` \((.*?)\) values/', $sql, $m)) throw new \LogicException($sql);
        $table = $m[1]; $this->writable($table);
        preg_match_all('/`([a-z_]+)`/', $m[2], $cols);
        $row = array_combine($cols[1], $bindings);
        $this->next[$table] = ($this->next[$table] ?? 0) + 1;
        $row['id'] = $this->lastInsertId = $this->next[$table];
        $this->rows[$table][$row['id']] = $row; $this->trace[] = 'insert:'.$table;
        return true;
    }
    public function update($sql, $bindings = [])
    {
        if (!preg_match('/update `([a-z_]+)` set (.*?) where (.*)/', $sql, $m)) throw new \LogicException($sql);
        $table = $m[1]; $this->writable($table);
        preg_match_all('/`([a-z_]+)` = \?/', $m[2], $cols);
        $changes = array_combine($cols[1], array_splice($bindings, 0, count($cols[1])));
        $selected = $this->filter($this->rows[$table], $m[3], $bindings);
        foreach ($selected as $row) $this->rows[$table][$row['id']] = array_replace($row, $changes);
        return count($selected);
    }
    private function writable(string $table): void
    {
        if (!$this->transactions || !in_array($table, ['sales', 'sale_details', 'pagos'], true)) throw new \LogicException('Escritura no autorizada: '.$table);
    }
}
