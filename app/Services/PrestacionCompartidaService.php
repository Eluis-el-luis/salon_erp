<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\{ComisionGenerada, DetalleVenta, ServiceParticipation, Usuario, Venta};
use App\Support\InventoryDecimal as D;
use Illuminate\Support\Facades\{DB, Validator};

/** Contrato prospectivo. No convierte líneas legacy ni habilita composición/inventario V2. */
class PrestacionCompartidaService
{
    public static function nueva(Venta $venta): bool { return $venta->getRawOriginal('service_contract_version') !== null; }
    public static function solicitada(array $input): bool
    {
        return array_key_exists('service_contract_version', $input) || array_key_exists('creation_key', $input)
            || collect($input['cart'] ?? [])->contains(fn ($r) => is_array($r) && (isset($r['performance_key']) || isset($r['participants'])));
    }
    public function exigirHabilitado(): void
    {
        if (!app()->environment('testing') || config('salon.shared_services.enabled') !== true || config('salon.shared_services.sql_verified') !== true) {
            throw new ContabilidadException('Contrato compartido deshabilitado: requiere validación SQL independiente y habilitación expresa.');
        }
    }
    protected function composicionDisponible(): bool { return CapturaConsumoService::activa(); }
    public function exigirAplicacion(): void
    {
        $this->exigirHabilitado();
        if (!$this->composicionDisponible()) throw new ContabilidadException('El contrato compartido requiere H03 autorizado; no se permite fallback legacy.');
    }
    public function disponible(): bool
    {
        try { $this->exigirAplicacion(); return true; } catch (ContabilidadException) { return false; }
    }
    public function entrada(array $input, ?array $directIntent = null): array
    {
        $this->exigirHabilitado();
        Validator::make($input, [
            'service_contract_version' => 'required|integer|in:1', 'creation_key' => 'required|uuid',
            'document_revision' => 'nullable|integer|min:1', 'cart' => 'required|array|min:1|list',
            'cart.*' => 'required|array',
            'cart.*.performance_key' => 'nullable|uuid', 'cart.*.participants' => 'nullable|array|list',
            'cart.*.participants.*' => 'integer|min:1', 'cart.*.detail_id' => 'nullable|integer|min:1',
        ])->validate();
        $cart = $input['cart']; $keys = []; $users = [];
        foreach ($cart as &$line) {
            // Etiquetas de recarga: presentación, nunca identidad ni intención económica.
            unset($line['producto'], $line['participants_display']);
            if (($line['type'] ?? null) !== 'servicio') {
                if (!empty($line['participants']) || !empty($line['performance_key'])) throw new ContabilidadException('Un producto no representa participación de servicio.');
                continue;
            }
            if (empty($line['performance_key']) || !isset($line['participants']) || !is_array($line['participants']) || !empty($line['stylist_id'])) {
                throw new ContabilidadException('Declare una prestación canónica y su lista de participantes; no se admiten fracciones por estilista.');
            }
            foreach (array_keys($line) as $field) if (!in_array($field, ['id','type','quantity','precio_c','variant_id','appointment_id','composition_reference','consumo','performance_key','participants','stylist_id','detail_id','producto'], true)) {
                throw new ContabilidadException('Campo de prestación no reconocido: '.$field.'. No se combinan capturas por participante.');
            }
            $line['performance_key'] = strtolower($line['performance_key']);
            if (isset($keys[$line['performance_key']])) throw new ContabilidadException('Prestación repetida en el documento.');
            $keys[$line['performance_key']] = true;
            $line['participants'] = array_map('intval', $line['participants']); sort($line['participants'], SORT_NUMERIC);
            if (count(array_unique($line['participants'])) !== count($line['participants'])) throw new ContabilidadException('Participante duplicado.');
            $users = array_merge($users, $line['participants']);
            if (!empty($line['appointment_id']) && D::scaled($line['quantity'], 0, 2147483647) !== 1) throw new ContabilidadException('La cita ordinaria admite una prestación; múltiples ejecuciones requieren evidencia independiente.');
        }
        unset($line);
        $intent = $directIntent ?? array_intersect_key($input, array_flip(['action','discount','client_id','payments','payment_method','currency','exchange_rate','change_currency','requiere_aprobacion']));
        if ($directIntent === null) $intent['cart'] = $cart;
        return ['cart' => $cart, 'creation_key' => strtolower($input['creation_key']),
            'hash' => self::huella($intent), 'revision' => $input['document_revision'] ?? null,
            'draft_id' => $input['draft_id'] ?? null, 'users' => array_values(array_unique($users))];
    }
    public static function huella(array $intent): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($intent), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
    public function bloquearContexto(array $data): void
    {
        $ids = array_values(array_unique(array_filter(array_column($data['cart'], 'appointment_id'))));
        $users = array_merge($data['users'], [auth()->id()]);
        if ($data['draft_id']) {
            $old = Venta::findOrFail($data['draft_id']);
            if ($old->appointment_id) $ids[] = (int)$old->appointment_id;
            $users = array_merge($users, ServiceParticipation::where('sale_id', $old->id)->pluck('user_id')->all());
        }
        if ($ids) app(AgendaService::class)->bloquearCitas($ids, $users);
        else {
            sort($users, SORT_NUMERIC);
            foreach (array_unique($users) as $id) if (!Usuario::whereKey($id)->lockForUpdate()->first()) throw new ContabilidadException('Participante no disponible.');
        }
    }
    public function repeticion(array $data, bool $lock = true): ?Venta
    {
        if ($data['draft_id']) return null;
        $query = Venta::where('creation_key', $data['creation_key']);
        $sale = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$sale) return null;
        if ((int)$sale->cashier_id !== (int)auth()->id()) throw new ContabilidadException('La clave pertenece a otra operación.', 409);
        if (!self::nueva($sale) || !hash_equals((string)$sale->getRawOriginal('creation_request_hash'), $data['hash'])) throw new ContabilidadException('La clave ya se utilizó con otra intención.', 409);
        return $sale;
    }
    public function validarCarrito(array $cart, array $data): void
    {
        $appointments = [];
        foreach ($cart as $i => $line) if ($line['type'] === 'servicio') {
            foreach ($data['cart'][$i]['participants'] as $id) if (!Usuario::whereKey($id)->exists()) throw new ContabilidadException('El participante no existe.');
            if ($line['appointment_id']) {
                if (isset($appointments[$line['appointment_id']])) throw new ContabilidadException('No se interpretan varias líneas H16 como ejecuciones independientes.');
                $appointments[$line['appointment_id']] = true;
                $cita = app(AgendaService::class)->bloquearCitas([$line['appointment_id']])->get($line['appointment_id']);
                $assigned = $cita->estilistas->modelKeys(); sort($assigned, SORT_NUMERIC);
                if ($assigned !== $data['cart'][$i]['participants']) throw new ContabilidadException('La participación no coincide con la asignación bloqueada de la cita.');
            }
        }
    }
    public function metadatos(array $data): array
    {
        return ['service_contract_version' => 1, 'creation_key' => $data['creation_key'], 'creation_request_hash' => $data['hash'], 'document_revision' => 1];
    }
    public function validarEdicion(Venta $sale, array $data): void
    {
        if (!self::nueva($sale) || (int)$sale->getRawOriginal('service_contract_version') !== 1) throw new ContabilidadException('Un borrador legacy no se convierte al contrato compartido.');
        if ($sale->status !== Venta::ESTADO_BORRADOR || $sale->getRawOriginal('creation_key') !== $data['creation_key']
            || (int)$sale->getRawOriginal('document_revision') !== (int)$data['revision']) throw new ContabilidadException('Propuesta desactualizada o no editable.', 409);
        $details = $sale->detalles()->orderBy('id')->lockForUpdate()->get();
        if (ServiceParticipation::where('sale_id', $sale->id)->whereNotNull('commission_resolution_snapshot')->exists()) throw new ContabilidadException('La propuesta ya contiene comisiones resueltas.');
        $expected = $details->map(fn ($d) => $d->performance_key ?: 'product:'.$d->id)->sort()->values()->all();
        $received = collect($data['cart'])->map(fn ($r) => ($r['type'] ?? '') === 'servicio' ? $r['performance_key'] : 'product:'.($r['detail_id'] ?? ''))->sort()->values()->all();
        if ($expected !== $received) throw new ContabilidadException('Retirada o alta estructural: anule explícitamente el borrador sin efectos y cree una nueva propuesta. No se eliminan prestaciones guardadas.', 409);
        if (ComisionGenerada::where('venta_id', $sale->id)->exists() || $sale->pagos()->exists()
            || DB::table('stock_ledger_entries')->where('referencia_tipo', 'venta')->where('referencia_id', $sale->id)->exists()
            || DB::table('asientos_contables')->where('referencia_id', $sale->id)->whereIn('modulo_origen', ['ventas','inventario'])->exists()) throw new ContabilidadException('El borrador tiene efectos incompatibles; requiere conciliación.');
    }
    public function guardarDetalle(Venta $sale, array $attributes, array $line, array $meta): DetalleVenta
    {
        $detail = null;
        if ($meta['draft_id']) {
            $query = $sale->detalles();
            $detail = $line['type'] === 'servicio' ? $query->where('performance_key', $meta['performance_key'])->firstOrFail()
                : $query->whereKey($meta['detail_id'])->firstOrFail();
            if ((int)($detail->service_id ?: $detail->item_id) !== (int)$line['id']) throw new ContabilidadException('Cambiar identidad requiere nueva propuesta.');
        }
        $detail ??= new DetalleVenta;
        $detail->fill($attributes);
        if ($line['type'] === 'servicio') {
            $service = \App\Models\Servicio::findOrFail($line['id']);
            $variant = $line['variant_id'] ? \App\Models\ServicioVariante::findOrFail($line['variant_id']) : null;
            $detail->performance_key = $meta['performance_key']; $detail->appointment_id = $line['appointment_id'];
            $detail->commercial_identity_snapshot = ['version' => 1, 'service_id' => $service->id, 'service_name' => $service->name,
                'variant_id' => $variant?->id, 'variant_name' => $variant?->name];
            $detail->stylist_id = null;
        }
        $detail->save();
        if ($line['type'] === 'servicio') {
            $old = $detail->participantes()->get();
            foreach ($old as $p) if (!in_array((int)$p->user_id, $meta['participants'], true)) $p->delete(); // Solo propuesta validada sin efectos.
            foreach ($meta['participants'] as $user) if (!$old->contains('user_id', $user)) ServiceParticipation::create(['sale_id' => $sale->id, 'sale_detail_id' => $detail->id, 'user_id' => $user]);
        }
        return $detail;
    }
    public function validarDocumento(Venta $sale): void
    {
        if (!self::nueva($sale)) return;
        $this->exigirAplicacion();
        if ((int)$sale->getRawOriginal('service_contract_version') !== 1) throw new ContabilidadException('Versión de prestación desconocida.');
        $seen = []; $appointments = [];
        foreach ($sale->detalles as $detail) {
            if (!$detail->service_id) {
                if ($detail->performance_key || $detail->appointment_id || $detail->participantes->isNotEmpty()) throw new ContabilidadException('Producto con identidad o participantes de prestación.');
                continue;
            }
            if (!$detail->performance_key || isset($seen[$detail->performance_key]) || $detail->stylist_id || ($detail->consumption_snapshot['version'] ?? null) !== 2) throw new ContabilidadException('Prestación o fotografía inválida; no se usa fallback legacy.');
            $seen[$detail->performance_key] = true;
            $identity = $detail->commercial_identity_snapshot;
            if (($identity['version'] ?? null) !== 1 || (int)($identity['service_id'] ?? 0) !== (int)$detail->service_id) throw new ContabilidadException('Identidad comercial incompatible.');
            if ($detail->service_variant_id && (int)($identity['variant_id'] ?? 0) !== (int)$detail->service_variant_id) throw new ContabilidadException('Variante comercial incompatible.');
            $photo = $detail->consumption_snapshot;
            if (!in_array($photo['mode'] ?? null, ['standard', 'complete'], true) || !is_array($photo['ingredients'] ?? null)
                || !is_string($photo['reference'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', $photo['reference'])) throw new ContabilidadException('Fotografía incompleta; no se usa receta actual como sustituto.');
            $ingredients = [];
            foreach ($photo['ingredients'] as $ingredient) {
                $id = $ingredient['item_id'] ?? null;
                if (!$id || isset($ingredients[$id]) || !array_key_exists('actual', $ingredient) || !array_key_exists('standard', $ingredient)) throw new ContabilidadException('Fotografía duplicada o incompleta.');
                D::scaled($ingredient['actual']); D::scaled($ingredient['standard']); $ingredients[$id] = true;
            }
            $projection = $detail->consumos->keyBy('item_id');
            if ($projection->count() !== $detail->consumos->count()) throw new ContabilidadException('Proyección de consumo duplicada.');
            if ($photo['mode'] === 'standard' && $projection->isNotEmpty()) throw new ContabilidadException('La proyección contradice la fotografía estándar.');
            if ($photo['mode'] === 'complete') {
                if (count($ingredients) !== $projection->count()) throw new ContabilidadException('Captura completa sin proyección íntegra.');
                foreach ($photo['ingredients'] as $ingredient) {
                    $row = $projection->get($ingredient['item_id']);
                    if (!$row || D::scaled($row->getRawOriginal('quantity_actual')) !== D::scaled($ingredient['actual'])
                        || D::scaled($row->getRawOriginal('quantity_standard')) !== D::scaled($ingredient['standard'])) throw new ContabilidadException('Captura y fotografía contradictorias.');
                }
            }
            if ($detail->appointment_id) {
                if ((int)$detail->appointment_id !== (int)$sale->appointment_id || isset($appointments[$detail->appointment_id]) || (int)$detail->quantity !== 1) throw new ContabilidadException('Vínculo de prestación ambiguo.');
                $appointments[$detail->appointment_id] = true;
            }
            $participants = $detail->participantes;
            if ($participants->pluck('user_id')->unique()->count() !== $participants->count()) throw new ContabilidadException('Participante repetido.');
            foreach ($participants as $p) if ((int)$p->sale_id !== (int)$sale->id || (int)$p->sale_detail_id !== (int)$detail->id) throw new ContabilidadException('Participación ajena al documento.');
        }
        if ($sale->appointment_id && !$appointments) throw new ContabilidadException('Cabecera de cita sin prestación vinculada.');
    }
    public function presentar(Venta $sale): array
    {
        return ['service_contract_version' => self::nueva($sale) ? (int)$sale->getRawOriginal('service_contract_version') : null,
            'creation_key' => $sale->getRawOriginal('creation_key'),
            'document_revision' => self::nueva($sale) ? (int)$sale->getRawOriginal('document_revision') : null];
    }
    public function respuestaRepeticion(Venta $sale): array
    {
        return ['sale_id' => $sale->id, 'status' => $sale->status, 'replayed' => true,
            'pendiente_aprobacion' => $sale->status === Venta::ESTADO_PENDIENTE_APROBACION];
    }
}
