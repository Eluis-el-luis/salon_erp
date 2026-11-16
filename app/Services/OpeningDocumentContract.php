<?php

namespace App\Services;

use App\Support\InventoryDecimal as D;
use DateTimeImmutable;
use DateTimeZone;
use App\Exceptions\OpeningDocumentValidationException;

/** Contrato puro, sin PDO, modelos, configuración empresarial ni persistencia. */
final class OpeningDocumentContract
{
    private function reject(string $field, string $message): never
    {
        throw new OpeningDocumentValidationException([$field => [$message]]);
    }

    private function keys(array $data, array $allowed, string $field): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            $this->reject($field, 'Campos desconocidos o identificadores autoritativos no admitidos.');
        }
    }

    private function text($value, int $max, string $field): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === ''
            || mb_strlen(trim($value)) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            $this->reject($field, 'Referencia o descripción obligatoria e inválida.');
        }
        return trim($value);
    }

    private function number($value, int $scale, int $max, string $field, bool $positive = true): int
    {
        // No admitir floats que ya hayan perdido precisión antes de llegar al contrato.
        if (!is_string($value) && !is_int($value)) $this->reject($field, 'Use un decimal explícito, sin redondeo.');
        try { $n = D::scaled($value, $scale, $max); }
        catch (\App\Exceptions\ContabilidadException $e) { $this->reject($field, $e->getMessage()); }
        if ($positive && $n === 0) $this->reject($field, 'La cantidad o valor debe ser positivo.');
        return $n;
    }

    private function evidence($value, string $field): array
    {
        if (!is_array($value) || ($value['version'] ?? null) !== 1) $this->reject($field, 'Evidencia versión 1 obligatoria.');
        $this->keys($value, ['version', 'reference', 'description'], $field);
        return ['version' => 1, 'reference' => $this->text($value['reference'] ?? null, 160, $field.'.reference'),
            'description' => $this->text($value['description'] ?? null, 2000, $field.'.description')];
    }

    private function date($value, string $field): string
    {
        if (!is_string($value)) $this->reject($field, 'Fecha YYYY-MM-DD obligatoria.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) $this->reject($field, 'Fecha inválida.');
        return $value;
    }

    private function instant($value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            $this->reject($field, 'Fecha y hora con zona explícita obligatorias.');
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $value));
        if (!$d || $d->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $value)) $this->reject($field, 'Fecha u hora inválida.');
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public function uuid($uuid): string
    {
        if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $uuid)) {
            $this->reject('operation_uuid', 'UUID canónico obligatorio.');
        }
        return strtolower($uuid);
    }

    /** Solo contexto de servidor. Nunca enlazar este parámetro al cuerpo HTTP. */
    public function policyProblems(?array $policy): array
    {
        $errors = [];
        foreach (range(1, 9) as $n) {
            if (($policy['approvals']['P'.$n] ?? null) !== true) $errors[] = 'P'.$n.': aprobación pendiente';
        }
        foreach (['reference', 'counterpart_code', 'cutoff', 'timezone'] as $key) {
            if (!is_string($policy[$key] ?? null) || trim($policy[$key]) === '') $errors[] = $key.': decisión requerida';
        }
        if (($policy['version'] ?? null) !== 1) $errors[] = 'Versión de política inválida';
        if (!is_array($policy['valuation_sources'] ?? null) || !$policy['valuation_sources']) $errors[] = 'Fuentes de valoración no aprobadas';
        if (!in_array($policy['zero_cost'] ?? null, ['reject', 'documented'], true)) $errors[] = 'Política de costo cero pendiente';
        if (!in_array($policy['unknown_lot'] ?? null, ['reject', 'internal_reference'], true)) $errors[] = 'Política de lote desconocido pendiente';
        if (($policy['expiry'] ?? null) !== 'reject_unknown_or_expired') $errors[] = 'Política de vencimiento pendiente o incompatible';
        if (!is_array($policy['classify_item_ids'] ?? null)) $errors[] = 'Lista de clasificación individual pendiente';
        if (($policy['counter_declares'] ?? null) !== true || !is_bool($policy['distinct_supervisor'] ?? null)) $errors[] = 'Responsables pendientes';
        if (($policy['legacy_contained'] ?? null) !== true) $errors[] = 'Contención heredada pendiente';
        if (!in_array($policy['accounting_basis'] ?? null, ['authorized_opening','documented_contribution','approved_opening_balance'], true)) $errors[] = 'Base contable pendiente';
        $account = $policy['counterpart'] ?? [];
        if (($account['code'] ?? null) !== ($policy['counterpart_code'] ?? null)
            || ($account['type'] ?? null) !== 'patrimonio' || ($account['nature'] ?? null) !== 'acreedora'
            || ($account['active'] ?? null) !== true || ($account['allows_entries'] ?? null) !== true
            || preg_match('/^3\.[23](?:\.|$)/', (string) ($policy['counterpart_code'] ?? ''))) $errors[] = 'Contrapartida ausente o incompatible';
        if (!is_array($policy['expiry_applicability'] ?? null)) $errors[] = 'Aplicabilidad de vencimientos pendiente';
        foreach ((is_array($policy['classify_item_ids'] ?? null) ? $policy['classify_item_ids'] : []) as $id) {
            if (!is_int($id) || $id <= 0) $errors[] = 'Lista de clasificación inválida';
        }
        foreach ((is_array($policy['valuation_sources'] ?? null) ? $policy['valuation_sources'] : []) as $source) {
            if (!is_string($source) || trim($source) === '') $errors[] = 'Fuente documental inválida';
        }
        return $errors;
    }

    /** Catálogo y política son fotografías confiables del servidor, no datos del navegador.
     * El resultado solo prepara datos en memoria: no acredita elegibilidad ni autorización PIN.
     */
    public function prepare(array $input, array $items, ?array $policy, int $actor): array
    {
        $this->keys($input, ['operation_uuid','document_reference','effective_at','counted_at','document_evidence',
            'count_declaration','total_value_nio','lines'], 'document');
        $problems = $this->policyProblems($policy);
        if ($problems) throw new OpeningDocumentValidationException(['policies' => $problems]);
        $this->keys($policy, ['version','reference','approvals','counterpart_code','accounting_basis','counterpart',
            'cutoff','timezone','valuation_sources','zero_cost','unknown_lot','expiry','expiry_applicability',
            'classify_item_ids','counter_declares','distinct_supervisor','legacy_contained'], 'policies');
        $this->keys($policy['counterpart'], ['code','type','nature','active','allows_entries'], 'policies.counterpart');
        $policy['reference'] = $this->text($policy['reference'],160,'policies.reference');
        $policy['counterpart_code'] = $this->text($policy['counterpart_code'],20,'policies.counterpart_code');
        if ($actor <= 0) $this->reject('actor', 'Ejecutor autenticado requerido.');
        try { $zone = new DateTimeZone($policy['timezone']); }
        catch (\Exception) { $this->reject('policies.timezone', 'Zona horaria inválida.'); }
        $cutoff = $this->instant($policy['cutoff'], 'policies.cutoff');
        $policy['cutoff'] = $cutoff;
        sort($policy['classify_item_ids'], SORT_NUMERIC);
        $policy['classify_item_ids'] = array_values(array_unique($policy['classify_item_ids']));
        sort($policy['valuation_sources'], SORT_STRING);
        $policy['valuation_sources'] = array_values(array_unique($policy['valuation_sources']));
        $effective = $this->instant($input['effective_at'] ?? null, 'effective_at');
        $counted = $this->instant($input['counted_at'] ?? null, 'counted_at');
        if ($effective !== $cutoff || $counted !== $cutoff) $this->reject('effective_at', 'Conteo y fecha efectiva deben representar el corte aprobado.');
        if (($input['count_declaration'] ?? null) !== true) $this->reject('count_declaration', 'Declare expresamente el conteo propio.');
        $reference = $this->text($input['document_reference'] ?? null, 160, 'document_reference');
        $evidence = $this->evidence($input['document_evidence'] ?? null, 'document_evidence');
        if (!is_array($input['lines'] ?? null) || !array_is_list($input['lines']) || !$input['lines']) $this->reject('lines', 'Posiciones obligatorias.');
        $lines = []; $positions = []; $totals = []; $sum = 0; $observations = [];
        foreach ($input['lines'] as $key => $line) {
            $path = 'lines.'.$key;
            if (!is_array($line)) $this->reject($path, 'Posición inválida.');
            $this->keys($line, ['position','item_id','physical_kind','native_unit','container_capacity','counted_units','counted_remainder',
                'manufacturer_lot','expiry_status','expires_on','physical_evidence','document_value_nio','valuation_source','valuation_evidence'], $path);
            $position = $this->number($line['position'] ?? null, 0, 4294967295, $path.'.position');
            $id = $this->number($line['item_id'] ?? null, 0, PHP_INT_MAX, $path.'.item_id');
            if (isset($positions[$position])) $this->reject($path.'.position', 'Posición duplicada.');
            $positions[$position] = true;
            $item = $items[$id] ?? null;
            if (!is_array($item) || ($item['deleted_at'] ?? null) !== null || !in_array($item['type'] ?? null, ['producto_venta','producto_interno'], true)) {
                $this->reject($path.'.item_id', 'Artículo inexistente, archivado o no inventariable.');
            }
            $fractional = ($item['is_fractionable'] ?? null) === true;
            if (!is_bool($item['is_fractionable'] ?? null)) $this->reject($path, 'Catálogo sin clasificación física verificable.');
            $unit = $item['unit_measure'] ?? null;
            if (!is_string($unit) || $unit === '' || strlen($unit) > 10 || ($line['native_unit'] ?? null) !== $unit
                || ($fractional && !in_array($unit, ['ml','gr','oz'], true))) $this->reject($path.'.native_unit', 'Unidad incompatible; no se realizan conversiones.');
            $version = $item['inventory_representation_version'] ?? null;
            if (($fractional && !in_array($version, [null, 2], true)) || (!$fractional && $version !== null)) $this->reject($path, 'Representación incompatible.');
            if ($fractional && $version === null && !in_array($id, $policy['classify_item_ids'], true)) $this->reject($path, 'Clasificación individual V2 no aprobada.');
            $kind = $line['physical_kind'] ?? null;
            if (!in_array($kind, $fractional ? ['closed','open'] : ['units'], true)) $this->reject($path.'.physical_kind', 'Clase física incompatible.');
            $capacity = null; $n = null; $r = null;
            if ($fractional) {
                $capacity = $this->number($item['total_volume'] ?? null, 2, 99999999, $path.'.capacity');
                if ($this->number($line['container_capacity'] ?? null, 2, 99999999, $path.'.container_capacity') !== $capacity) $this->reject($path, 'La capacidad difiere del catálogo.');
            } elseif (($line['container_capacity'] ?? null) !== null) $this->reject($path, 'Unidades no admiten capacidad ficticia.');
            if ($kind === 'open') {
                if (($line['counted_units'] ?? null) !== null) $this->reject($path, 'Un remanente no es un envase cerrado.');
                $r = $this->number($line['counted_remainder'] ?? null, 2, 99999999, $path.'.counted_remainder');
                if ($r > $capacity) $this->reject($path, 'El remanente supera la capacidad.');
                $q = $r;
            } else {
                if (($line['counted_remainder'] ?? null) !== null) $this->reject($path, 'La posición no admite remanente.');
                $n = $this->number($line['counted_units'] ?? null, 0, 99999999, $path.'.counted_units');
                // Ambos factores están acotados: su producto cabe en int64 antes de verificar el límite SQL.
                $q = $n * ($capacity ?? 100);
            }
            $totals[$id] ??= ['q'=>0,'closed'=>0,'open'=>0];
            $totals[$id]['q'] += $q;
            $totals[$id]['closed'] += $n ?? 0;
            $totals[$id]['open'] += (int) ($kind === 'open');
            if ($totals[$id]['q'] > 9999999999999 || $totals[$id]['closed'] > 2147483647 || $totals[$id]['open'] > 1) $this->reject($path, 'Acumulado fuera de límite o más de un abierto.');
            $physical = $this->evidence($line['physical_evidence'] ?? null, $path.'.physical_evidence');
            $lot = $line['manufacturer_lot'] ?? null;
            if ($lot === null) {
                if ($policy['unknown_lot'] !== 'internal_reference') $this->reject($path.'.manufacturer_lot', 'Lote de fabricante requerido por política.');
            } else $lot = $this->text($lot, 100, $path.'.manufacturer_lot');
            $observation = self::canonical([$id,$kind,$lot,$physical['reference']]);
            if (isset($observations[$observation])) $this->reject($path, 'La misma constatación física está duplicada.');
            $observations[$observation] = true;
            $expiry = $line['expiry_status'] ?? null;
            $expires = $line['expires_on'] ?? null;
            if ($expiry === 'known') {
                $expires = $this->date($expires, $path.'.expires_on');
                if ($expires <= (new DateTimeImmutable($cutoff))->setTimezone($zone)->format('Y-m-d')) $this->reject($path, 'Vencimiento no posterior al corte.');
            } elseif ($expiry !== 'not_applicable' || $expires !== null) $this->reject($path, 'Vencimiento desconocido o incompatible.');
            // La aplicabilidad viene del contexto aprobado, nunca solo de la declaración del navegador.
            if (!isset($policy['expiry_applicability'][$id]) || !is_bool($policy['expiry_applicability'][$id])
                || ($policy['expiry_applicability'][$id] && $expiry !== 'known')
                || (!$policy['expiry_applicability'][$id] && $expiry !== 'not_applicable')) $this->reject($path, 'Aplicabilidad del vencimiento no aprobada o contradictoria.');
            $valuation = $this->evidence($line['valuation_evidence'] ?? null, $path.'.valuation_evidence');
            $source = $this->text($line['valuation_source'] ?? null, 100, $path.'.valuation_source');
            if (!in_array($source, $policy['valuation_sources'], true)) $this->reject($path, 'Fuente de valoración no aprobada.');
            $value = $this->number($line['document_value_nio'] ?? null, 2, 999999999999999, $path.'.document_value_nio', $policy['zero_cost'] === 'reject');
            $sum += $value;
            if ($sum > 999999999999999) $this->reject('total_value_nio', 'Total documental fuera de límite.');
            $lines[] = ['position'=>$position, 'item_id'=>$id, 'item_type'=>$item['type'], 'physical_kind'=>$kind,
                'inventory_representation_version'=>$fractional ? 2 : null, 'native_unit'=>$unit,
                'container_capacity'=>$capacity === null ? null : D::text($capacity), 'counted_units'=>$n,
                'counted_remainder'=>$r === null ? null : D::text($r), 'native_quantity'=>D::text($q),
                'manufacturer_lot'=>$lot, 'lot_origin'=>$lot === null ? 'internal' : 'manufacturer',
                'expiry_status'=>$expiry, 'expires_on'=>$expires, 'physical_evidence'=>$physical,
                'document_value_nio'=>D::text($value), 'valuation_source'=>$source, 'valuation_evidence'=>$valuation];
        }
        if ($sum !== $this->number($input['total_value_nio'] ?? null, 2, 999999999999999, 'total_value_nio', false)) $this->reject('total_value_nio', 'El total no coincide con las posiciones.');
        usort($lines, fn ($a,$b) => $a['position'] <=> $b['position']);
        $content = ['contract_version'=>1, 'executed_by'=>$actor, 'policy'=>$policy, 'document_reference'=>$reference,
            'effective_at'=>$effective, 'counted_at'=>$counted, 'count_declaration'=>true,
            'document_evidence'=>$evidence, 'total_value_nio'=>D::text($sum), 'lines'=>$lines];
        $json = self::canonical($content);
        return ['operation_uuid'=>$this->uuid($input['operation_uuid'] ?? null), 'content'=>$content,
            'canonical_json'=>$json, 'content_hash'=>hash('sha256', $json), 'confirmation_allowed'=>false];
    }

    public static function canonical(array $data): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$child) if (is_array($child)) $child = $sort($child);
            return $value;
        };
        return json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function assertConfirmationUnavailable(): never
    {
        throw new \LogicException('H01-B2B-1 solo valida: confirmación física y financiera no implementada ni autorizada.');
    }
}
