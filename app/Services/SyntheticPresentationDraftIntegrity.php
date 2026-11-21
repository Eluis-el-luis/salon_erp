<?php

namespace App\Services;

use DomainException;

/** Pure documentary verification shared by the locked repository and snapshot reader. */
final class SyntheticPresentationDraftIntegrity
{
    public const PROVENANCE_CONTRACT = 'AC05B3B2_SYNTHETIC_DRAFTS_CLI_V1';

    public function header(array $row, int $actor): array
    {
        $r = $this->content($row['canonical_content'], $actor);
        $p = json_decode($row['provenance'], true, 512, JSON_THROW_ON_ERROR);
        $expected = ['contract'=>self::PROVENANCE_CONTRACT,'synthetic'=>true,'actor_selection'=>'first_active_accounting_harness_actor'];
        if (!is_array($p)) throw new DomainException('document.provenance');
        ksort($p); ksort($expected);
        if ((int)$row['actor_id'] !== $actor || $row['purpose'] !== 'simulation' || $row['origin'] !== 'synthetic_fixture'
            || $row['installation_scope'] !== 'TEST-FIXTURE-ONLY' || !in_array($row['status'], ['draft','frozen_simulation'], true)
            || $p !== $expected || $r['hash'] !== $row['content_hash'] || $r['canonical_json'] !== $row['canonical_content']
            || $r['canonical_content']['family_uuid'] !== $row['family_uuid'] || $r['canonical_content']['version_number'] !== (int)$row['version_number']) {
            throw new DomainException('document.integrity');
        }
        return $r;
    }

    public function verify(array $row, array $history, int $actor): array
    {
        $r = $this->header($row, $actor); $previous = null; $requests = [];
        foreach ($history as $index=>$a) {
            $after = $this->content($a['after_content'], $actor);
            foreach (['family_uuid','version_number','purpose','origin','installation_scope','author','prepared_at'] as $field) {
                if ($after['canonical_content'][$field] !== $r['canonical_content'][$field]) throw new DomainException('audit.identity_changed');
            }
            if ((int)$a['draft_id'] !== (int)$row['id'] || (int)$a['actor_id'] !== $actor
                || (int)$a['revision'] !== $index + 1 || (int)$a['expected_revision'] !== $index
                || $a['event'] !== ($index === 0 ? 'created' : 'revised') || $after['hash'] !== $a['after_hash']
                || $after['canonical_json'] !== $a['after_content'] || $a['before_content'] !== ($previous['after_content'] ?? null)
                || $a['before_hash'] !== ($previous['after_hash'] ?? null) || isset($requests[$a['request_uuid']])) throw new DomainException('audit.integrity');
            if ($index === 0 && $a['request_uuid'] !== $row['document_uuid']) throw new DomainException('audit.creation_identity');
            $requests[$a['request_uuid']] = true; $previous = $a;
        }
        if (!$previous || count($history) !== (int)$row['revision'] || $previous['after_hash'] !== $row['content_hash']
            || $previous['after_content'] !== $row['canonical_content']) throw new DomainException('audit.incomplete');
        return $r;
    }

    private function content(string $json, int $actor): array
    {
        $content = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($content)) throw new DomainException('content.invalid_or_actor_manipulated');
        $r = (new FinancialPresentationContractValidator)->validate($content);
        if ($r['hash'] === null || $r['canonical_content']['author'] !== 'SYNTHETIC:executor_'.$actor) throw new DomainException('content.invalid_or_actor_manipulated');
        return $r;
    }
}
