<?php

namespace App\Services;

use App\Support\FinancialPresentationCanonicalizer;
use DateTimeImmutable;
use DomainException;

/** Synthetic contracts only. No SQL, auth, policy resolver, ledger or publication capability. */
final class FinancialPresentationContractValidator
{
    private const ROOTS = [
        'asset' => 'debit_minus_credit', 'liability' => 'credit_minus_debit',
        'equity' => 'credit_minus_debit', 'income' => 'credit_minus_debit',
        'cost' => 'debit_minus_credit', 'operating_expense' => 'debit_minus_credit',
        'other_expense' => 'debit_minus_credit',
    ];
    private const DECISIONS = ['taxonomy', 'universe', 'governance', 'temporal_selection', 'currency_basis'];

    /** relevantAccountIds is an optional explicit fixture universe, never a coverage claim about SQL. */
    public function validate(array $content, ?array $relevantAccountIds = null): array
    {
        try {
            (new FinancialPresentationCanonicalizer)->encode($content); // Reject floats/objects/invalid UTF-8 anywhere.
            $c = $this->content($content);
            $diagnostics = [];
            if ($relevantAccountIds === null) $diagnostics[] = 'coverage.not_verified';
            else {
                $ids = $this->ids($relevantAccountIds);
                $missing = array_values(array_diff($ids, array_column($c['assignments'], 'account_id')));
                if ($missing) $diagnostics[] = ['coverage.missing_accounts' => $missing];
            }
            foreach ($c['pending_decisions'] as $decision) $diagnostics[] = 'approval.pending.'.$decision;
            $canonical = (new FinancialPresentationCanonicalizer)->encode($c);
            $c = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
            return ['status' => $diagnostics ? 'REQUIRES_ACCOUNTING_APPROVAL' : 'VALID',
                'errors' => [], 'diagnostics' => $diagnostics, 'canonical_content' => $c,
                'canonical_json' => $canonical, 'hash' => hash('sha256', $canonical),
                'business_authority' => false, 'publishable' => false, 'issued_statement' => false];
        } catch (DomainException $e) {
            return ['status' => 'INVALID', 'errors' => [$e->getMessage()], 'diagnostics' => [],
                'canonical_content' => null, 'canonical_json' => null, 'hash' => null,
                'business_authority' => false, 'publishable' => false, 'issued_statement' => false];
        }
    }

    /** Optimistic edit of an in-memory synthetic draft. Frozen content is never edited. */
    public function revise(array $current, string $expectedHash, array $replacement, string $state): array
    {
        $old = $this->validate($current);
        if ($state !== 'draft' || $old['hash'] === null || !hash_equals($old['hash'], $expectedHash)) {
            throw new DomainException('revision.stale_or_frozen');
        }
        $new = $this->validate($replacement);
        if ($new['hash'] === null) throw new DomainException('revision.invalid_replacement');
        foreach (['family_uuid', 'version_number', 'purpose', 'origin', 'installation_scope', 'author', 'prepared_at'] as $field) {
            if ($old['canonical_content'][$field] !== $new['canonical_content'][$field]) throw new DomainException('revision.identity_changed');
        }
        return $new + ['previous_hash' => $old['hash'], 'previous_review_valid' => hash_equals($old['hash'], $new['hash'])];
    }

    /** Simulated documentary withdrawal only; no approval ID, persistence or economic authority. */
    public function withdrawal(array $content, string $expectedHash, string $reference, string $recordedAt): array
    {
        $r = $this->validate($content);
        if ($r['hash'] === null || !hash_equals($r['hash'], $expectedHash)) throw new DomainException('withdrawal.stale');
        $this->reference($reference);
        return ['contract' => 'financial-presentation-withdrawal-simulation-v1', 'purpose' => 'simulation',
            'subject_hash' => $r['hash'], 'reference' => $reference, 'recorded_at' => $this->utc($recordedAt),
            'business_authority' => false];
    }

    public function withinValidity(array $content, string $instant): bool
    {
        $r = $this->validate($content);
        if ($r['hash'] === null) throw new DomainException('validity.invalid_content');
        $time = $this->utc($instant);
        $c = $r['canonical_content'];
        return $time >= $c['valid_from'] && ($c['valid_until'] === null || $time < $c['valid_until']);
    }

    private function content(array $c): array
    {
        $this->fields($c, ['contract', 'canonicalizer', 'purpose', 'origin', 'installation_scope', 'family_uuid',
            'version_number', 'label', 'author', 'prepared_at', 'valid_from', 'valid_until',
            'groups', 'assignments', 'profiles', 'pending_decisions'], ['reviewer', 'reference']);
        $this->expect($c['contract'] === 'financial-presentation-v1', 'contract.unknown');
        $this->expect($c['canonicalizer'] === FinancialPresentationCanonicalizer::VERSION, 'canonicalizer.unknown');
        $this->expect($c['purpose'] === 'simulation' && $c['origin'] === 'synthetic_fixture'
            && $c['installation_scope'] === 'TEST-FIXTURE-ONLY', 'provenance.synthetic_only');
        $this->expect(is_string($c['family_uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $c['family_uuid']) === 1, 'identity.uuid');
        $this->positive($c['version_number']);
        $this->text($c['label']); $this->actor($c['author']);
        if (isset($c['reviewer'])) $this->actor($c['reviewer']);
        if (isset($c['reference'])) $this->reference($c['reference']);
        foreach (['prepared_at', 'valid_from'] as $field) $c[$field] = $this->utc($c[$field]);
        if ($c['valid_until'] !== null) {
            $c['valid_until'] = $this->utc($c['valid_until']);
            $this->expect($c['valid_from'] < $c['valid_until'], 'validity.inverted');
        }
        $this->list($c['pending_decisions']);
        foreach ($c['pending_decisions'] as $d) $this->expect(is_string($d) && in_array($d, self::DECISIONS, true), 'decisions.unknown');
        $this->expect(count(array_unique($c['pending_decisions'], SORT_REGULAR)) === count($c['pending_decisions']), 'decisions.duplicate');
        sort($c['pending_decisions'], SORT_STRING);
        $this->list($c['groups']); $groups = []; $roots = [];
        foreach ($c['groups'] as $g) {
            $this->fields($g, ['key', 'parent', 'basis', 'order', 'label']);
            $this->key($g['key']); $this->text($g['label']); $this->order($g['order']);
            $this->expect(!isset($groups[$g['key']]), 'groups.duplicate');
            $this->expect(in_array($g['basis'], ['debit_minus_credit', 'credit_minus_debit'], true), 'groups.basis');
            if ($g['parent'] === null) {
                $this->expect(isset(self::ROOTS[$g['key']]) && self::ROOTS[$g['key']] === $g['basis'], 'groups.root');
                $roots[] = $g['key'];
            } else $this->key($g['parent']);
            $groups[$g['key']] = $g;
        }
        $this->expect(count($roots) === count(self::ROOTS), 'groups.root_coverage');
        foreach ($groups as $g) {
            $seen = []; $node = $g;
            while ($node['parent'] !== null) {
                $this->expect(!isset($seen[$node['key']]), 'groups.cycle'); $seen[$node['key']] = true;
                $this->expect(isset($groups[$node['parent']]), 'groups.missing_parent');
                $parent = $groups[$node['parent']];
                $this->expect($parent['basis'] === $node['basis'], 'groups.inherited_basis'); $node = $parent;
            }
        }
        ksort($groups, SORT_STRING); $c['groups'] = array_values($groups);
        $this->list($c['assignments']); $assigned = [];
        foreach ($c['assignments'] as $a) {
            $this->fields($a, ['account_id', 'group_key', 'order', 'corrector'], ['label']);
            $this->positive($a['account_id']); $this->key($a['group_key']); $this->order($a['order']);
            $this->expect(is_bool($a['corrector']), 'assignments.corrector_annotation');
            if (array_key_exists('label', $a) && $a['label'] !== null) $this->text($a['label']);
            $this->expect(!isset($assigned[$a['account_id']]), 'assignments.duplicate');
            $this->expect(isset($groups[$a['group_key']]), 'assignments.missing_group');
            $assigned[$a['account_id']] = $a;
        }
        ksort($assigned, SORT_NUMERIC); $c['assignments'] = array_values($assigned);
        $this->list($c['profiles']); $profiles = [];
        foreach ($c['profiles'] as $p) {
            $this->fields($p, ['report', 'from', 'until', 'states', 'null_origin', 'closing', 'period_id',
                'center_scope', 'account_scope', 'scope_label', 'base_currency', 'amount_basis', 'inactive_history']);
            $this->expect(in_array($p['report'], ['result', 'balance'], true) && !isset($profiles[$p['report']]), 'profiles.report');
            $this->date($p['until']);
            if ($p['report'] === 'result') {
                $this->date($p['from']);
                $this->expect($p['from'] <= $p['until'] && $p['closing'] === 'exclude', 'profiles.result');
                if ($p['period_id'] !== null) $this->positive($p['period_id']);
            } else $this->expect($p['from'] === null && $p['period_id'] === null && $p['closing'] === 'include', 'profiles.balance');
            $this->list($p['states']);
            foreach ($p['states'] as $s) $this->expect(is_string($s) && in_array($s, ['borrador', 'contabilizado', 'anulado'], true), 'profiles.states');
            $this->expect($p['states'] !== [] && count(array_unique($p['states'], SORT_REGULAR)) === count($p['states']), 'profiles.states');
            sort($p['states'], SORT_STRING);
            $this->expect(in_array($p['null_origin'], ['include', 'exclude'], true), 'profiles.null_origin');
            foreach (['center_scope', 'account_scope'] as $scope) {
                if ($p[$scope] === 'all' || ($scope === 'center_scope' && $p[$scope] === 'unassigned')) continue;
                $p[$scope] = $this->ids($p[$scope]); $this->expect($p[$scope] !== [], 'profiles.empty_scope');
            }
            $this->expect($p['scope_label'] === ($p['account_scope'] === 'all' && $p['center_scope'] === 'all' ? 'global' : 'partial'), 'profiles.scope_label');
            $this->expect($p['base_currency'] === 'NIO' && $p['amount_basis'] === 'original_ledger_base'
                && $p['inactive_history'] === 'include', 'profiles.historical_basis');
            $profiles[$p['report']] = $p;
        }
        $this->expect(count($profiles) === 2, 'profiles.coverage');
        ksort($profiles, SORT_STRING); $c['profiles'] = array_values($profiles);
        return $c;
    }

    private function fields(mixed $v, array $required, array $optional = []): void
    {
        $this->expect(is_array($v) && !array_is_list($v), 'fields.object');
        $this->expect(!array_diff($required, array_keys($v)) && !array_diff(array_keys($v), array_merge($required, $optional)), 'fields.unknown_or_missing');
    }
    private function list(mixed $v): void { $this->expect(is_array($v) && array_is_list($v), 'fields.list'); }
    private function positive(mixed $v): void { $this->expect(is_int($v) && $v > 0, 'fields.positive_integer'); }
    private function order(mixed $v): void { $this->expect(is_int($v) && $v >= 0, 'fields.order'); }
    private function text(mixed $v): void { $this->expect(is_string($v) && strlen($v) <= 512 && preg_match('/\S/u', $v) === 1 && !preg_match('/[\x00-\x1f\x7f]/', $v), 'fields.text'); }
    private function actor(mixed $v): void { $this->expect(is_string($v) && preg_match('/^SYNTHETIC:[a-z0-9_-]{1,64}$/D', $v) === 1, 'fields.synthetic_actor'); }
    private function key(mixed $v): void { $this->expect(is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $v) === 1, 'fields.key'); }
    private function reference(mixed $v): void { $this->text($v); $this->expect(str_starts_with($v, 'SYNTHETIC:'), 'fields.synthetic_reference'); }
    private function ids(mixed $ids): array
    {
        $this->list($ids); foreach ($ids as $id) $this->positive($id);
        $this->expect(count(array_unique($ids, SORT_REGULAR)) === count($ids), 'fields.duplicate_id');
        sort($ids, SORT_NUMERIC); return $ids;
    }
    private function date(mixed $v): void
    {
        $this->expect(is_string($v) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $v) === 1, 'fields.date');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        $this->expect($d !== false && $d->format('Y-m-d') === $v, 'fields.date');
    }
    private function utc(mixed $v): string
    {
        $this->expect(is_string($v) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|\+00:00)$/D', $v) === 1, 'fields.utc');
        $normalized = str_replace('+00:00', 'Z', $v);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $normalized, new \DateTimeZone('UTC'));
        $this->expect($d !== false && $d->format('Y-m-d\TH:i:s\Z') === $normalized, 'fields.utc');
        return $normalized;
    }
    private function expect(bool $condition, string $error): void { if (!$condition) throw new DomainException($error); }
}
