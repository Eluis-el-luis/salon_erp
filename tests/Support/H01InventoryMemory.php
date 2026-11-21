<?php

namespace Tests\Support;

use Illuminate\Database\MySqlConnection;

/** Persistencia sintética acotada H01. Todo intento de obtener PDO falla. No interpreta SQL general. */
final class H01InventoryMemory extends MySqlConnection
{
    public array $rows = [];
    public array $trace = [];
    public ?\Closure $afterWrite = null;
    private array $snapshots = [];
    private array $next = [];

    public function __construct()
    {
        parent::__construct(fn () => throw new \LogicException('PDO prohibido en H01-R.'), 'h01-memory-only', '', ['name' => 'mysql']);
        foreach (['items','users','lotes','stock_ledger_entries','purchase_invoices','purchase_invoice_lines','providers',
            'cuentas_por_pagar','sale_details','sale_detail_consumptions','service_formulas','mermas'] as $table) $this->rows[$table] = [];
    }
    public function beginTransaction() { $this->snapshots[] = $this->rows; ++$this->transactions; $this->trace[] = 'begin'; }
    public function commit() { array_pop($this->snapshots); --$this->transactions; $this->trace[] = 'commit'; }
    public function rollBack($toLevel = null)
    {
        $target = $toLevel ?? $this->transactions - 1;
        if ($target < 0 || $target >= $this->transactions) throw new \LogicException('Rollback inesperado en el doble H01.');
        while ($this->transactions > $target) { $this->rows = array_pop($this->snapshots); --$this->transactions; }
        $this->trace[] = 'rollback'; // next no se rebobina: no confundir filas con AUTO_INCREMENT.
    }
    public function transaction(\Closure $callback, $attempts = 1)
    {
        $level = $this->transactionLevel(); $this->beginTransaction();
        try { $result = $callback($this); $this->commit(); return $result; }
        catch (\Throwable $error) { $this->rollBack($level); throw $error; }
    }
    public function select($sql, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->trace[] = [$sql, $bindings];
        if (!preg_match('/from `([a-z_]+)`/', $sql, $m) || !isset($this->rows[$m[1]])) throw new \LogicException($sql);
        $rows = array_values($this->rows[$m[1]]);
        if (preg_match('/ where (.*?)(?:\) as| group by| order by| limit | for update| lock in share mode|$)/', $sql, $w)) $rows = $this->filter($rows, $w[1], $bindings);
        if (str_contains($sql, 'SUM(cantidad_disponible * costo_unitario)')) {
            $groups = [];
            foreach ($rows as $r) { $id = $r['item_id']; $groups[$id] ??= ['item_id' => $id, 'valor_total' => 0, 'cantidad_total' => 0]; $groups[$id]['valor_total'] += $r['cantidad_disponible'] * $r['costo_unitario']; $groups[$id]['cantidad_total'] += $r['cantidad_disponible']; }
            $rows = array_values($groups);
        }
        if (str_starts_with($sql, 'select exists')) return [(object)['exists' => (bool)$rows]];
        if (preg_match('/ order by (.*?)(?: limit | for update| lock in share mode|$)/', $sql, $order)) {
            preg_match_all('/`([a-z_]+)` (asc|desc)/', $order[1], $parts, PREG_SET_ORDER);
            usort($rows, function ($a, $b) use ($parts) { foreach ($parts as [, $key, $dir]) if ($cmp = (($a[$key] ?? null) <=> ($b[$key] ?? null))) return $dir === 'desc' ? -$cmp : $cmp; return 0; });
        }
        if (str_contains($sql, 'limit 1')) $rows = array_slice($rows, 0, 1);
        return array_map(fn ($row) => (object)$row, $rows);
    }
    private function filter(array $rows, string $where, array $bindings): array
    {
        $where = preg_replace('/`[a-z_]+`\./', '', $where);
        $pattern = '/`([a-z_]+)` (= \?|> \?|in \([^)]*\)|is null|is not null)/';
        preg_match_all($pattern, $where, $clauses, PREG_SET_ORDER);
        if (trim(str_replace('and', '', preg_replace($pattern, '', $where)))) throw new \LogicException($where);
        $i = 0;
        foreach ($clauses as [, $key, $op]) {
            if (str_starts_with($op, 'is ')) { $rows = array_filter($rows, fn ($r) => ($op === 'is null') === (($r[$key] ?? null) === null)); continue; }
            $n = substr_count($op, '?'); $values = $n ? array_slice($bindings, $i, $n) : explode(',', preg_replace('/.*\((.*)\)/', '$1', $op)); $i += $n;
            $rows = array_filter($rows, fn ($r) => $op === '> ?' ? ($r[$key] ?? null) > $values[0] : in_array($r[$key] ?? null, $values));
        }
        if ($i !== count($bindings)) throw new \LogicException('Bindings no consumidos.');
        return $rows;
    }
    public function insert($sql, $bindings = [], $sequence = null)
    {
        if (!preg_match('/insert into `([a-z_]+)` \((.*?)\) values/', $sql, $m)) throw new \LogicException($sql);
        $table = $m[1]; $this->writable($table);
        preg_match_all('/`([a-z_]+)`/', $m[2], $columns); $row = array_combine($columns[1], $bindings);
        $id = max($this->next[$table] ?? 0, ...array_merge([0], array_keys($this->rows[$table]))) + 1;
        $this->next[$table] = $this->lastInsertId = $row['id'] = $id; $this->rows[$table][$id] = $row;
        $this->wrote('insert', $table); return true;
    }
    public function update($sql, $bindings = [])
    {
        if (!preg_match('/update `([a-z_]+)` set (.*?) where (.*)/', $sql, $m)) throw new \LogicException($sql);
        $table = $m[1]; $this->writable($table); preg_match_all('/`([a-z_]+)` = \?/', $m[2], $cols);
        $changes = array_combine($cols[1], array_splice($bindings, 0, count($cols[1])));
        $selected = $this->filter($this->rows[$table], $m[3], $bindings);
        foreach ($selected as $row) $this->rows[$table][$row['id']] = array_replace($row, $changes);
        $this->wrote('update', $table); return count($selected);
    }
    private function writable(string $table): void
    {
        if (!isset($this->rows[$table]) || !in_array($table, ['items','lotes','stock_ledger_entries','purchase_invoices','purchase_invoice_lines','cuentas_por_pagar','mermas'], true)) throw new \LogicException('Escritor inesperado: '.$table);
        if ($table !== 'items' && !$this->transactions) throw new \LogicException('El ensayo exige transacción para efectos documentales.');
    }
    private function wrote(string $operation, string $table): void
    {
        $this->trace[] = "$operation:$table";
        if ($this->afterWrite) ($this->afterWrite)($operation, $table);
    }
}
