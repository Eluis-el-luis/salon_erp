<?php

namespace App\Services;

use App\Support\FinancialPresentationCanonicalizer;
use DomainException;

/** Pure synthetic bridge. Monetary arithmetic and document selection belong to the existing engine. */
final class FinancialPresentationSyntheticAdapter
{
    public function read(array $content, string $report, array $fixture): array
    {
        $validator = new FinancialPresentationContractValidator;
        $validation = $validator->validate($content);
        if ($validation['hash'] === null) throw new DomainException('adapter.invalid_contract:'.implode(',', $validation['errors']));
        $c = $validation['canonical_content'];
        if ($c['purpose'] !== 'simulation' || $c['origin'] !== 'synthetic_fixture' || $c['installation_scope'] !== 'TEST-FIXTURE-ONLY') {
            throw new DomainException('adapter.synthetic_only');
        }
        if (!in_array($report, ['result', 'balance'], true)) throw new DomainException('adapter.unknown_profile');
        if (array_diff(array_keys($fixture), ['id','base_currency','amount_basis','details'])
            || array_diff(['id','base_currency','amount_basis','details'], array_keys($fixture))
            || !is_string($fixture['id']) || preg_match('/^SYNTHETIC:[A-Za-z0-9_.-]{1,100}$/D', $fixture['id']) !== 1
            || !is_array($fixture['details']) || !array_is_list($fixture['details'])) throw new DomainException('adapter.invalid_fixture');
        foreach ($fixture['details'] as $row) if (!is_array($row)) throw new DomainException('adapter.invalid_fixture_detail');

        $profile = array_values(array_filter($c['profiles'], fn ($p) => $p['report'] === $report))[0];
        if ($profile['account_scope'] !== 'all') throw new DomainException('adapter.unsupported_account_scope');
        if ($profile['center_scope'] !== 'all') throw new DomainException('adapter.unsupported_center_scope');
        if ($fixture['base_currency'] !== $profile['base_currency'] || $fixture['amount_basis'] !== $profile['amount_basis']) {
            throw new DomainException('adapter.incompatible_fixture_basis');
        }
        // B1 fixes NIO/original_ledger_base, historical inactive inclusion and the two closing rules.
        // No inference from currency_id, account code, name, activity, hierarchy or posting bindings.
        $fixtureHash = hash('sha256', (new FinancialPresentationCanonicalizer)->encode($fixture));
        $groups = array_column($c['groups'], null, 'key');
        $assignments = $trace = [];
        foreach ($c['assignments'] as $a) {
            $node = $groups[$a['group_key']]; $path = [$node['key']];
            while ($node['parent'] !== null) {
                $node = $groups[$node['parent']]; array_unshift($path, $node['key']);
            }
            // Validator already proved acyclic ancestry and identical sign basis at every level.
            $assignments[] = ['account_id'=>$a['account_id'], 'group'=>$node['key']];
            $trace[$a['account_id']] = ['assignment'=>$a, 'root_group'=>$node['key'], 'group_path'=>$path, 'basis'=>$node['basis']];
        }
        $classification = ['contract'=>'synthetic-presentation-v1', 'purpose'=>'simulation',
            'version'=>$c['family_uuid'].':v'.$c['version_number'].':'.$validation['hash'], 'assignments'=>$assignments];
        $parameters = ['report'=>$report, 'from'=>$profile['from'], 'until'=>$profile['until'],
            'states'=>$profile['states'], 'null_origin'=>$profile['null_origin']];
        if ($profile['period_id'] !== null) $parameters['period_id'] = $profile['period_id'];
        try {
            $presentation = (new SyntheticAccountingPresentation)->read($classification, $fixture['details'], $parameters);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'Relevant original detail has no classification.') {
                throw new DomainException('adapter.incomplete_coverage', 0, $e);
            }
            throw $e;
        }
        // The engine checks missing coverage after its own selection. Do not duplicate that predicate.
        $validation = $validator->validate($content, array_keys($presentation['accounts']));
        return ['source'=>['contract'=>$c['contract'], 'family_uuid'=>$c['family_uuid'], 'version_number'=>$c['version_number'],
                'content_hash'=>$validation['hash'], 'canonical_json'=>$validation['canonical_json'], 'content'=>$c],
            'fixture'=>['id'=>$fixture['id'], 'hash'=>$fixtureHash, 'base_currency'=>$fixture['base_currency'],
                'amount_basis'=>$fixture['amount_basis'], 'data_origin'=>'synthetic_memory_only'],
            'profile'=>$profile, 'universe'=>$parameters,
            'coverage'=>['status'=>'complete', 'account_ids'=>array_keys($presentation['accounts']),
                'detail_ids'=>array_keys($presentation['details']), 'rule'=>'engine_selected_nonzero_details_require_assignment'],
            'validation_status'=>$validation['status'], 'diagnostics'=>$validation['diagnostics'],
            'assignment_provenance'=>$trace, 'engine_configuration'=>$classification, 'presentation'=>$presentation,
            'capabilities'=>['economic_groups'=>'roots_only', 'subgroup_totals'=>false, 'scope'=>'declared_fixture_not_SQL_global'],
            'business_authority'=>false, 'publishable'=>false, 'issued_statement'=>false];
    }
}
