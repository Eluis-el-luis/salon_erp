<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\Articulo;
use App\Models\Lote;
use App\Models\StockLedger;
use App\Support\InventoryDecimal as D;
use Illuminate\Support\Facades\DB;

class InventarioFraccionableService
{
    public function exige(Articulo $i): bool
    {
        return (int) $i->inventory_representation_version === 2 || (CapturaConsumoService::activa() && $i->is_fractionable);
    }

    public function estado(Articulo $i): array
    {
        if (! CapturaConsumoService::activa() || DB::transactionLevel() === 0 || (int) $i->inventory_representation_version !== 2 || ! $i->is_fractionable) {
            throw new ContabilidadException('Inventario fraccionable no activado o sin clasificación conciliada.');
        }
        if (! extension_loaded('bcmath') || ! in_array($i->unit_measure, ['ml', 'gr', 'oz'], true)) {
            throw new ContabilidadException('Unidad o aritmética decimal no disponible.');
        }
        $c = D::scaled($i->total_volume);
        if (! $c) {
            throw new ContabilidadException('Capacidad inválida.');
        }
        $lots = Lote::where('item_id', $i->id)->orderBy('fecha_entrada')->orderBy('id')->lockForUpdate()->get();
        $closed = 0;
        $open = 0;
        $opened = 0;
        foreach ($lots as $l) {
            if ((int) $l->inventory_representation_version !== 2 || $l->unidad_nativa !== $i->unit_measure || D::scaled($l->capacidad_envase) !== $c) {
                throw new ContabilidadException('No se mezclan lotes heredados ni medidas distintas.');
            }
            $closed += D::scaled($l->getRawOriginal('cantidad_disponible'), 0, 99999999);
            $o = D::scaled($l->volumen_abierto);
            $open += $o;
            $opened += (int) ($o > 0);
        }
        $q = $closed * $c + $open;
        if ($opened > 1 || $closed !== (int) $i->existencia_actual || $open !== D::scaled($i->current_volume) || $q > 9999999999999) {
            throw new ContabilidadException('Artículo y lotes no conciliados.');
        }
        $entries = StockLedger::where('item_id', $i->id)->orderBy('id')->lockForUpdate()->get();
        if ($entries->contains(fn ($e) => (int) $e->inventory_representation_version !== 2)) {
            throw new ContabilidadException('Kárdex heredado incompatible.');
        }
        $last = $entries->last();
        $v = $last ? D::scaled($last->getRawOriginal('saldo_valor'), 2, 999999999999999) : 0;
        if (($last ? D::stored($last->getRawOriginal('saldo_volumen')) : 0) !== $q || ($q === 0 && $v !== 0)) {
            throw new ContabilidadException('Kárdex no conciliado o residuo monetario; requiere revisión.');
        }
        foreach ($entries as $entry) {
            $a = DB::table('stock_ledger_lot_allocations')->where('stock_ledger_entry_id', $entry->id)->orderBy('id')->lockForUpdate()->get();
            if ($a->sum(fn ($r) => D::signed($r->physical_delta)) !== D::signed($entry->getRawOriginal('volumen'))
                || $a->sum(fn ($r) => D::signed($r->value_delta)) !== D::signed($entry->getRawOriginal('valor'))) {
                throw new ContabilidadException('Asignaciones no conciliadas.');
            }
        }

        return compact('c', 'q', 'v', 'lots');
    }

    public function validar(Articulo $i, int $q): void
    {
        if ($q <= 0 || $this->estado($i)['q'] < $q) {
            throw new ContabilidadException('Volumen insuficiente o cantidad inválida para '.$i->producto.'.');
        }
    }

    public function ingresar(Articulo $i, $line, $invoice, ?int $actor): void
    {
        $s = $this->estado($i);
        $n = D::scaled($line->getRawOriginal('cantidad'), 0, 99999999);
        if (! $n) {
            throw new ContabilidadException('La compra requiere envases enteros positivos.');
        }
        $q = $n * $s['c'];
        $v = D::scaled($line->getRawOriginal('valor_neto'), 2, 999999999999999);
        if ($q + $s['q'] > 9999999999999 || (int) $i->existencia_actual + $n > 2147483647) {
            throw new ContabilidadException('Acumulado físico fuera de límite.');
        }
        $l = new Lote;
        $l->forceFill(['item_id' => $i->id, 'purchase_invoice_line_id' => $line->id, 'lote' => $line->lote ?: 'L'.date('ymd'),
            'fecha_entrada' => $invoice->fecha_factura, 'fecha_vencimiento' => $line->fecha_vencimiento, 'cantidad_entrada' => $n, 'cantidad_disponible' => $n,
            'costo_unitario' => $line->getRawOriginal('costo_unitario_final'), 'inventory_representation_version' => 2,
            'volumen_entrada' => D::text($q), 'volumen_abierto' => '0.00', 'capacidad_envase' => $i->total_volume, 'unidad_nativa' => $i->unit_measure])->save();
        $i->existencia_actual += $n;
        $i->costo_promedio = D::text(D::ratio($s['v'] + $v, $s['c'] * 10000, $s['q'] + $q), 6);
        $i->save();
        $this->registrar($i, $s, $q, $v, [[$l, 0, 0, $n, 0, $q]], 'compra', 'factura_compra', $invoice->id, null, $actor);
        $this->estado($i->refresh());
    }

    public function consumir(Articulo $i, int $q, bool $sealed, array $ctx, ?int $detail = null): int
    {
        $s = $this->estado($i);
        $this->validar($i, $q);
        $c = $s['c'];
        if ($sealed && ($q % $c !== 0 || (int) $i->existencia_actual < intdiv($q, $c))) {
            throw new ContabilidadException('Envases cerrados insuficientes.');
        }
        $pending = $q;
        $changes = [];
        $ordered = $sealed ? $s['lots'] : $s['lots']->sortBy(fn ($l) => D::scaled($l->volumen_abierto) > 0 ? 0 : 1, SORT_REGULAR);
        foreach ($ordered as $l) {
            if (! $pending) {
                break;
            }
            $before = D::scaled($l->getRawOriginal('cantidad_disponible'), 0, 99999999);
            $ob = D::scaled($l->volumen_abierto);
            $closed = $before;
            $open = $ob;
            $used = 0;
            if (! $sealed) {
                $take = min($open, $pending);
                $open -= $take;
                $pending -= $take;
                $used += $take;
            }
            $full = min($closed, intdiv($pending, $c));
            $closed -= $full;
            $pending -= $full * $c;
            $used += $full * $c;
            if (! $sealed && $pending && $closed) {
                $closed--;
                $open = $c;
                $take = min($open, $pending);
                $open -= $take;
                $pending -= $take;
                $used += $take;
            }
            if ($used) {
                $l->cantidad_disponible = $closed;
                $l->volumen_abierto = D::text($open);
                $l->save();
                $changes[] = [$l, $before, $ob, $closed, $open, -$used];
            }
        }
        if ($pending) {
            throw new ContabilidadException('Cobertura física incompleta.');
        }
        $v = D::ratio($s['v'], $q, $s['q']);
        $i->existencia_actual = $s['lots']->sum(fn ($l) => (int) $l->cantidad_disponible);
        $i->current_volume = D::text($s['lots']->sum(fn ($l) => D::scaled($l->volumen_abierto)));
        $i->save();
        $this->registrar($i, $s, -$q, -$v, $changes, $ctx['origen'], $ctx['referencia_tipo'], $ctx['referencia_id'], $detail, auth()->id());
        $this->estado($i->refresh());

        return $v;
    }

    private function registrar(Articulo $i, array $s, int $q, int $v, array $changes, string $origin, string $type, $id, ?int $detail, ?int $actor): void
    {
        $after = $s['q'] + $q;
        $value = $s['v'] + $v;
        if ($after < 0 || $value < 0 || $value > 999999999999999 || ($after === 0 && $value !== 0)) {
            throw new ContabilidadException('Saldo físico o monetario inválido.');
        }
        // Costo informativo a seis decimales. Nunca se usa su redondeo para calcular valor.
        $unitCost = $q < 0 ? D::ratio($s['v'], 1000000, $s['q']) : D::ratio($v, 1000000, $q);
        $average = $after ? D::ratio($value, 1000000, $after) : 0;
        if ($unitCost > 999999999999999 || $average > 999999999999999) {
            throw new ContabilidadException('El costo por unidad nativa excede la precisión del kárdex.');
        }
        $e = new StockLedger;
        $e->forceFill(['item_id' => $i->id, 'fecha' => now(), 'movimiento' => $q > 0 ? 'entrada' : 'salida', 'origen' => $origin, 'referencia_tipo' => $type, 'referencia_id' => $id,
            'cantidad' => D::text(D::ratio($q, 10000, $s['c']), 4), 'volumen' => D::text($q), 'valor' => D::text($v),
            'saldo_cantidad' => D::text(D::ratio($after, 10000, $s['c']), 4), 'saldo_volumen' => D::text($after), 'saldo_valor' => D::text($value),
            'costo_unitario' => D::text($unitCost, 6), 'costo_promedio' => D::text($average, 6),
            'usuario_id' => $actor ?? auth()->id(), 'inventory_representation_version' => 2, 'sale_detail_id' => $detail])->save();
        $remaining = abs($v);
        $shares = [];
        foreach ($changes as $k => $a) {
            $shares[$k] = (int) bcdiv(bcmul((string) abs($v), (string) abs($a[5]), 0), (string) abs($q), 0);
            $remaining -= $shares[$k];
        }
        foreach ($shares as &$share) {
            if ($remaining > 0) {
                $share++;
                $remaining--;
            }
        }
        unset($share);
        foreach ($changes as $k => [$l,$b,$ob,$a,$oa,$delta]) {
            DB::table('stock_ledger_lot_allocations')->insert([
                'item_id' => $i->id, 'stock_ledger_entry_id' => $e->id, 'lote_id' => $l->id, 'sale_detail_id' => $detail, 'physical_delta' => D::text($delta),
                'native_unit' => $i->unit_measure, 'container_capacity' => $i->total_volume, 'closed_before' => $b, 'closed_after' => $a,
                'open_before' => D::text($ob), 'open_after' => D::text($oa), 'value_delta' => D::text(($v < 0 ? -1 : 1) * $shares[$k]), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
