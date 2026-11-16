<?php

namespace App\Services;

/** Interpretación pura: no concede éxito idempotente de efectos ni modifica documentos. */
final class OpeningDocumentIdentity
{
    public function classify(array $candidate, ?array $stored, bool $articleHasHistory): string
    {
        if ($stored === null) return $articleHasHistory ? 'new_key_history_conflict' : 'new_key_requires_eligibility';
        $contract = new OpeningDocumentContract;
        if ($contract->uuid($stored['operation_uuid'] ?? null) !== $contract->uuid($candidate['operation_uuid'] ?? null)) {
            return $articleHasHistory ? 'new_key_history_conflict' : 'different_key';
        }
        if (!is_string($stored['content_hash'] ?? null) || !is_string($candidate['content_hash'] ?? null)
            || !hash_equals($stored['content_hash'], $candidate['content_hash'])) return 'same_key_content_conflict';
        if (($stored['status'] ?? null) === 'prepared') return 'prepared_not_success';
        if (($stored['status'] ?? null) !== 'confirmed' || ($stored['contract_version'] ?? null) !== 1
            || !is_array($stored['content'] ?? null)
            || !hash_equals($stored['content_hash'], hash('sha256', OpeningDocumentContract::canonical($stored['content'])))
            || empty($stored['result_asiento_id']) || empty($stored['lines'])
            || ($stored['total_value_nio'] ?? null) !== ($candidate['content']['total_value_nio'] ?? null)
            || count($stored['lines']) !== count($candidate['content']['lines'] ?? [])) return 'confirmed_incomplete';
        $positions = []; $lots = []; $movements = [];
        foreach ($stored['lines'] as $line) {
            foreach (['result_lot_id','result_ledger_id','position','item_id'] as $field) {
                if (!is_int($line[$field] ?? null) || $line[$field] <= 0) return 'confirmed_incomplete';
            }
            if (isset($positions[$line['position']]) || isset($lots[$line['result_lot_id']])
                || isset($movements[$line['result_ledger_id']])) return 'confirmed_incomplete';
            $positions[$line['position']] = $line;
            $lots[$line['result_lot_id']] = $movements[$line['result_ledger_id']] = true;
        }
        foreach ($candidate['content']['lines'] as $line) {
            if (($positions[$line['position']]['item_id'] ?? null) !== $line['item_id']) return 'confirmed_incomplete';
        }
        // Resultado solo interpretativo, incluso con fotografía y vínculos completos en memoria.
        // Verificar permisos, filas, cantidades y asiento real bajo bloqueo queda para B2B.
        return 'same_key_confirmed_candidate_requires_locked_verification';
    }
}
