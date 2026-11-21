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

    public function __construct(private bool $shared = false)
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido en H06.'), 'h06-memory-only', '', ['name' => 'mysql']);
        if ($shared) foreach (['users','items','clients','service_variants','service_formulas','service_participations','comisiones_generadas','sale_detail_consumptions','stock_ledger_entries','asientos_contables','esquemas_comision','esquema_rangos','notifications'] as $table) $this->rows[$table] = [];
    }
    public function beginTransaction()
    {
        $this->snapshots[] = [$this->rows, $this->effects]; ++$this->transactions;
    }
    public function commit() { array_pop($this->snapshots); --$this->transactions; $this->trace[] = 'commit'; }
    public function rollBack($toLevel = null)
    {
        if (!$this->snapshots || (!$this->shared && $toLevel !== null)) throw new \LogicException('Rollback inesperado.');
        $target = $toLevel ?? $this->transactions - 1;
        while ($this->transactions > $target) {
            [$this->rows, $this->effects] = array_pop($this->snapshots); --$this->transactions; $this->trace[] = 'rollback';
        }
    }
    public function transaction(\Closure $callback, $attempts = 1)
    {
        $level = $this->transactionLevel(); $this->beginTransaction();
        try { $result = $callback($this); $this->commit(); return $result; }
        catch (\Throwable $error) { $this->rollBack($level); throw $error; }
    }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if ($this->shared) $this->trace[] = $sql;
        if (!preg_match('/from `([a-z_]+)`/', $sql, $table) || !isset($this->rows[$table[1]])) throw new \LogicException($sql);
        $rows = array_values($this->rows[$table[1]]);
        // Catálogos sin filas: las consultas relacionales no fabrican coincidencias.
        if ($this->shared && !$rows) return str_starts_with($sql, 'select exists') ? [(object)['exists' => false]] : [];
        if (preg_match('/ where (.*?)(?:\) as| order by| limit | for update|$)/', $sql, $where)) $rows = $this->filter($rows, $where[1], $bindings);
        if (str_starts_with($sql, 'select exists')) return [(object)['exists' => (bool)$rows]];
        if (str_contains($sql, 'limit 1')) $rows = array_slice($rows, 0, 1);
        return array_map(fn ($r) => (object)$r, array_values($rows));
    }
    private function filter(array $rows, string $where, array $bindings): array
    {
        $where = preg_replace('/`[a-z_]+`\./', '', $where);
        $pattern = '/`([a-z_]+)` (= \?|in \([^)]*\)|is not null|is null)/';
        preg_match_all($pattern, $where, $clauses, PREG_SET_ORDER);
        if (trim(str_replace('and', '', preg_replace($pattern, '', $where)))) throw new \LogicException($where);
        $i = 0;
        foreach ($clauses as [, $column, $op]) {
            if ($op === 'is null' || $op === 'is not null') { $rows = array_filter($rows, fn ($r) => ($op === 'is null') === (($r[$column] ?? null) === null)); continue; }
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
        if ($this->shared && $table === 'notifications' && !$this->rows[$table]) return 0;
        preg_match_all('/`([a-z_]+)` = \?/', $m[2], $cols);
        $changes = array_combine($cols[1], array_splice($bindings, 0, count($cols[1])));
        $selected = $this->filter($this->rows[$table], $m[3], $bindings);
        foreach ($selected as $row) $this->rows[$table][$row['id']] = array_replace($row, $changes);
        return count($selected);
    }
    private function writable(string $table): void
    {
        $allowed = ['sales', 'sale_details', 'pagos'];
        if ($this->shared) $allowed = array_merge($allowed, ['service_participations','comisiones_generadas','sale_detail_consumptions','notifications']);
        if (!$this->transactions || !in_array($table, $allowed, true)) throw new \LogicException('Escritura no autorizada: '.$table);
    }
    public function delete($sql, $bindings = [])
    {
        if (!$this->shared || !preg_match('/delete from `([a-z_]+)` where (.*)/', $sql, $m) || !in_array($m[1], ['service_participations','sale_detail_consumptions'], true)) throw new \LogicException($sql);
        $this->writable($m[1]); $selected = $this->filter($this->rows[$m[1]], $m[2], $bindings);
        foreach ($selected as $row) unset($this->rows[$m[1]][$row['id']]);
        return count($selected);
    }
}
