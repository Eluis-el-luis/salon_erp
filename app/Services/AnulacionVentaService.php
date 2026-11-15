<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\{Articulo, AsientoContable, Cita, StockLedger, Venta};
use App\Support\InventoryDecimal as D;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Contrato completo de anulación. No inicia ni confirma transacciones. */
class AnulacionVentaService
{
    public function preparar(Venta $venta, Request $request, int $supervisor, bool $sinEfectos): array
    {
        if (! CapturaConsumoService::activa() || DB::transactionLevel() === 0 || $venta->annulment_evidence !== null) {
            throw new ContabilidadException('Contrato de anulación no disponible o ya registrado.');
        }
        $data = Validator::make($request->all(), [
            'inventory_intent' => 'required|in:sin_restitucion,devolucion_completa,correccion_completa',
            'verification_reference' => 'nullable|string|max:160',
            'verification_description' => 'nullable|string|max:2000',
            'verified_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'verification_confirmed' => 'sometimes|accepted',
            'execution_declaration' => 'nullable|in:prestada,no_prestada,no_determinada',
            'execution_reference' => 'nullable|string|max:160',
            'execution_description' => 'nullable|string|max:2000',
            'execution_verified_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'execution_attended_on' => 'prohibited_unless:execution_declaration,prestada|nullable|date_format:Y-m-d|before_or_equal:execution_verified_on',
            'execution_confirmed' => 'sometimes|accepted',
        ])->validate();
        $physical = $data['inventory_intent'] !== 'sin_restitucion';
        if ($sinEfectos && $physical) throw new ContabilidadException('Un documento sin efectos no admite una restitución física.');
        if ($physical) {
            Validator::make($data, [
                'verification_reference' => 'required|string|min:3', 'verification_description' => 'required|string|min:10',
                'verified_on' => 'required', 'verification_confirmed' => 'required|accepted',
            ])->validate();
        }
        $entries = StockLedger::where('referencia_tipo', 'venta')->where('referencia_id', $venta->id)->orderBy('id')->lockForUpdate()->get();
        if ($entries->contains(fn ($e) => $e->origen !== 'venta' || $e->movimiento !== 'salida')) {
            throw new ContabilidadException('La venta contiene movimientos incompatibles o una restitución anterior.');
        }
        if ($physical && $entries->isEmpty()) throw new ContabilidadException('No existen salidas físicas que restituir. Seleccione sin restitución y documente la ejecución correspondiente.');
        $details = $venta->detalles()->orderBy('id')->sharedLock()->get();
        $venta->setRelation('detalles', $details);
        if (!$sinEfectos && ($details->isEmpty() || $details->contains(fn ($d) => ($d->consumption_snapshot['version']??null)!==2))) {
            throw new ContabilidadException('Documento aplicado sin fotografía verificable; requiere conciliación histórica.');
        }
        $evidence = ['version' => 1, 'inventory_intent' => $data['inventory_intent'], 'reason' => trim($request->motivo),
            'executor_id' => auth()->id(), 'supervisor_id' => $supervisor, 'recorded_at' => now()->toIso8601String(),
            'sale_id' => $venta->id, 'effects_applied' => !$sinEfectos, 'original_movement_ids' => $entries->modelKeys(), 'detail_ids' => $details->modelKeys(),
            'physical_verification' => $physical ? ['attestation_version' => 1,
                'confirmed_conditions' => ['original_lots', 'closed_containers_still_sealed_when_applicable', 'same_open_container_when_applicable'],
                'reference' => $data['verification_reference'], 'description' => $data['verification_description'],
                'verified_on' => $data['verified_on'], 'verifier_id' => auth()->id(), 'confirmed' => true] : null];
        if ($venta->appointment_id && ! $sinEfectos) {
            Validator::make($data, [
                'execution_declaration' => 'required', 'execution_reference' => 'required|string|min:3',
                'execution_description' => 'required|string|min:10', 'execution_verified_on' => 'required',
                'execution_confirmed' => 'required|accepted',
            ])->validate();
            if ($data['execution_declaration'] === 'no_determinada' || ($data['execution_declaration'] === 'no_prestada' && ! $physical && $entries->isNotEmpty())) {
                throw new ContabilidadException('La ejecución no puede conciliarse con esta modalidad; no se modificó la venta.');
            }
            $cita = Cita::whereKey($venta->appointment_id)->lockForUpdate()->firstOrFail();
            $affected = $details->where('service_id', $cita->service_id);
            if ($affected->isEmpty()) {
                throw new ContabilidadException('El alcance de la ejecución no coincide con los servicios facturados; requiere conciliación.');
            }
            $evidence['appointment_execution'] = ['version' => 1, 'declaration' => $data['execution_declaration'],
                'retrospective' => true, 'appointment_id' => $cita->id, 'service_id' => $cita->service_id, 'detail_ids' => $affected->modelKeys(),
                'reference' => $data['execution_reference'], 'description' => $data['execution_description'],
                'attended_on' => $data['execution_attended_on'] ?? null, 'verified_on' => $data['execution_verified_on'],
                'recorded_at' => $evidence['recorded_at'], 'executor_id' => auth()->id(), 'verifier_id' => auth()->id(), 'supervisor_id' => $supervisor];
        }
        $evidence['original_allocation_ids'] = DB::table('stock_ledger_lot_allocations')->whereIn('stock_ledger_entry_id', $entries->modelKeys())
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $evidence['original_asiento_ids'] = $this->comprobarAsientos($venta, $entries);
        return $evidence;
    }

    /** Verifica origen, cuentas e importes; jamás clasifica un costo por descripción. */
    private function comprobarAsientos(Venta $venta, $entries): array
    {
        $expected = [];
        foreach ($entries as $entry) {
            $detail = $venta->detalles->firstWhere('id', $entry->sale_detail_id);
            $item = Articulo::withTrashed()->find($entry->item_id);
            if (! $detail || ! $item) throw new ContabilidadException('Salida sin detalle original verificable.');
            $foto = $detail->consumption_snapshot;
            if (($foto['version']??null)!==2 || ($detail->item_id
                ? (int)$detail->item_id !== (int)$entry->item_id || (int)($foto['item_id']??0)!==(int)$entry->item_id
                : !collect($foto['ingredients']??[])->contains(fn ($r) => (int)$r['item_id']===(int)$entry->item_id && D::scaled($r['actual'])>0))) {
                throw new ContabilidadException('La salida no está respaldada por la fotografía original.');
            }
            $cost = $detail->service_id ? '5.2' : '5.1';
            $asset = app(ContabilidadService::class)->codigoInventarioArticulo($item);
            $v = -D::signed($entry->getRawOriginal('valor'));
            if ($v < 0) throw new ContabilidadException('Valor original incompatible.');
            $expected[$cost][$asset] = ($expected[$cost][$asset] ?? 0) + $v;
        }
        $headers = AsientoContable::where('referencia_id', $venta->id)->whereIn('modulo_origen', ['ventas', 'inventario'])
            ->orderBy('id')->lockForUpdate()->get();
        $seen = [];
        foreach ($headers as $h) {
            $lines = DB::table('detalle_asientos')->where('asiento_id', $h->id)->orderBy('id')->lockForUpdate()->get();
            // No tomar X sobre el catálogo común mediante un JOIN bloqueante.
            $codes = DB::table('cuentas_contables')->whereIn('id',$lines->pluck('cuenta_id')->unique()->all())
                ->orderBy('id')->sharedLock()->pluck('codigo','id');
            foreach ($lines as $line) $line->codigo = $codes[$line->cuenta_id] ?? null;
            $debit = $lines->sum(fn ($l) => D::stored($l->debe));
            $credit = $lines->sum(fn ($l) => D::stored($l->haber));
            if ($debit !== $credit || ! $lines->count() || str_starts_with($h->numero_asiento, 'REV-')) throw new ContabilidadException('Asiento incompatible o reversión previa.');
            if ($h->modulo_origen === 'inventario') {
                $costs = $lines->filter(fn ($l) => in_array($l->codigo, ['5.1','5.2'], true) && D::stored($l->debe) > 0);
                if ($costs->count() !== 1) throw new ContabilidadException('Costo original sin clasificación inequívoca.');
                $cost = $costs->first()->codigo;
                $assets = array_filter($expected[$cost] ?? []);
                if (isset($seen[$cost]) || array_sum($assets) !== $debit || $lines->count() !== count($assets) + 1) throw new ContabilidadException('Costo y kárdex no conciliados.');
                foreach ($lines as $l) {
                    if ($l->codigo === $cost) {
                        if (D::stored($l->haber) !== 0 || D::stored($l->debe) !== $debit) throw new ContabilidadException('Línea de costo incompatible.');
                    } elseif (D::stored($l->debe) !== 0 || !isset($assets[$l->codigo]) || D::stored($l->haber) !== $assets[$l->codigo]) throw new ContabilidadException('Cuenta de inventario no conciliada con su salida.');
                }
                $seen[$cost] = true;
            } elseif ($lines->contains(fn ($l) => in_array($l->codigo, ['5.1','5.2','1.1.4','1.1.5'], true))
                || ! preg_match('/^(ING|COB)-'.preg_quote(str_pad($venta->id,5,'0',STR_PAD_LEFT), '/').'$/D', $h->numero_asiento)) {
                throw new ContabilidadException('Asiento comercial no reconocido; requiere conciliación.');
            }
        }
        foreach ($expected as $cost => $assets) if (array_sum($assets) && !isset($seen[$cost])) throw new ContabilidadException('Falta el asiento del consumo original.');
        return $headers->modelKeys();
    }
}
