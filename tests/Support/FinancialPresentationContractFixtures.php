<?php

namespace Tests\Support;

/** Fictional IDs only: no catalogue lookup, authenticated actor or economic approval. */
final class FinancialPresentationContractFixtures
{
    public static function content(): array
    {
        $groups = [];
        foreach (['asset', 'liability', 'equity', 'income', 'cost', 'operating_expense', 'other_expense'] as $i => $key) {
            $groups[] = ['key' => $key, 'parent' => null, 'label' => 'SYNTHETIC '.$key, 'order' => $i * 10,
                'basis' => in_array($key, ['liability', 'equity', 'income'], true) ? 'credit_minus_debit' : 'debit_minus_credit'];
        }
        $profile = ['from' => '2030-10-01', 'until' => '2030-10-31', 'states' => ['contabilizado'],
            'null_origin' => 'include', 'period_id' => null, 'center_scope' => 'all', 'account_scope' => 'all',
            'scope_label' => 'global', 'base_currency' => 'NIO', 'amount_basis' => 'original_ledger_base', 'inactive_history' => 'include'];
        return ['contract' => 'financial-presentation-v1', 'canonicalizer' => 'financial-presentation-canonical-v1',
            'purpose' => 'simulation', 'origin' => 'synthetic_fixture', 'installation_scope' => 'TEST-FIXTURE-ONLY',
            'family_uuid' => '00000000-0000-4000-8000-000000000001', 'version_number' => 1,
            'label' => 'SYNTHETIC hypothesis, not economic approval', 'author' => 'SYNTHETIC:author',
            'prepared_at' => '2026-11-20T12:00:00Z', 'valid_from' => '2030-01-01T00:00:00Z', 'valid_until' => null,
            'groups' => $groups, 'assignments' => [['account_id' => 900001, 'group_key' => 'income', 'order' => 10, 'corrector' => true]],
            'profiles' => [array_merge($profile, ['report' => 'result', 'closing' => 'exclude']),
                array_merge($profile, ['report' => 'balance', 'from' => null, 'closing' => 'include'])],
            'pending_decisions' => []];
    }
}
