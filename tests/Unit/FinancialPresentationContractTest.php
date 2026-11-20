<?php

namespace Tests\Unit;

use App\Services\FinancialPresentationContractValidator;
use App\Support\FinancialPresentationCanonicalizer;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FinancialPresentationContractFixtures as Fixture;

/** PHPUnit directly, no Laravel TestCase/bootstrap, PDO, model, seeder or database trait. */
final class FinancialPresentationContractTest extends TestCase
{
    private function validate(array $c): array { return (new FinancialPresentationContractValidator)->validate($c, [900001]); }

    public function test_minimum_synthetic_contract_is_valid_without_authority(): void
    {
        $r = $this->validate(Fixture::content());
        self::assertSame('VALID', $r['status']); self::assertSame([], $r['errors']);
        self::assertFalse($r['business_authority']); self::assertFalse($r['publishable']); self::assertFalse($r['issued_statement']);
        self::assertSame(hash('sha256', $r['canonical_json']), $r['hash']);
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($v) => [$v], ['contract', 'extra', 'business', 'origin', 'installation', 'duplicate_assignment',
            'missing_group', 'cycle', 'parent', 'basis', 'multiplier', 'formula', 'id_string', 'id_float', 'id_zero',
            'id_array', 'date', 'inverted', 'float', 'utf8', 'hash_field', 'reference', 'uuid', 'version', 'order',
            'balance_range', 'balance_closing', 'result_closing', 'result_range', 'states', 'missing_null', 'scope',
            'inactive', 'currency', 'duplicate_group', 'duplicate_profile', 'duplicate_state', 'timezone', 'leap_second',
            'duplicate_scope', 'unknown_decision', 'missing_field', 'null_required', 'object', 'reviewer',
            'nested_decision', 'nested_state', 'self_cycle', 'equal_validity', 'balance_period', 'empty_scope',
            'missing_root', 'numeric_key', 'credential_field', 'empty_states']);
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_contracts_fail_closed(string $case): void
    {
        $c = Fixture::content();
        switch ($case) {
            case 'contract': $c['contract'] = 'financial-presentation-v2'; break;
            case 'extra': $c['approved'] = true; break;
            case 'business': $c['purpose'] = 'business'; break;
            case 'origin': $c['origin'] = 'administrative'; break;
            case 'installation': $c['installation_scope'] = 'production'; break;
            case 'duplicate_assignment': $c['assignments'][] = $c['assignments'][0]; break;
            case 'missing_group': $c['assignments'][0]['group_key'] = 'unknown'; break;
            case 'cycle':
                $c['groups'][] = ['key' => 'a', 'parent' => 'b', 'basis' => 'credit_minus_debit', 'label' => 'a', 'order' => 0];
                $c['groups'][] = ['key' => 'b', 'parent' => 'a', 'basis' => 'credit_minus_debit', 'label' => 'b', 'order' => 0]; break;
            case 'parent': $c['groups'][] = ['key' => 'child', 'parent' => 'absent', 'basis' => 'credit_minus_debit', 'label' => 'c', 'order' => 0]; break;
            case 'basis': $c['groups'][3]['basis'] = 'debit_minus_credit'; break;
            case 'multiplier': $c['assignments'][0]['multiplier'] = -1; break;
            case 'formula': $c['groups'][0]['formula'] = 'SELECT * FROM accounts'; break;
            case 'id_string': $c['assignments'][0]['account_id'] = '900001'; break;
            case 'id_float': $c['assignments'][0]['account_id'] = 900001.0; break;
            case 'id_zero': $c['assignments'][0]['account_id'] = 0; break;
            case 'id_array': $c['assignments'][0]['account_id'] = []; break;
            case 'date': $c['profiles'][0]['until'] = '2030-02-30'; break;
            case 'inverted': $c['valid_until'] = '2029-01-01T00:00:00Z'; break;
            case 'float': $c['version_number'] = 1.0; break;
            case 'utf8': $c['label'] = "\xff"; break;
            case 'hash_field': $c['content_hash'] = str_repeat('a', 64); break;
            case 'reference': $c['reference'] = 'BANK-REAL-APPROVAL'; break;
            case 'uuid': $c['family_uuid'] = '1'; break;
            case 'version': $c['version_number'] = '1'; break;
            case 'order': $c['groups'][0]['order'] = -1; break;
            case 'balance_range': $c['profiles'][1]['from'] = '2030-01-01'; break;
            case 'balance_closing': $c['profiles'][1]['closing'] = 'exclude'; break;
            case 'result_closing': $c['profiles'][0]['closing'] = 'include'; break;
            case 'result_range': $c['profiles'][0]['from'] = '2030-11-01'; break;
            case 'states': $c['profiles'][0]['states'] = ['unknown']; break;
            case 'missing_null': unset($c['profiles'][0]['null_origin']); break;
            case 'scope': $c['profiles'][0]['account_scope'] = [900001]; break;
            case 'inactive': $c['profiles'][0]['inactive_history'] = 'exclude'; break;
            case 'currency': $c['profiles'][0]['amount_basis'] = 'bank_native'; break;
            case 'duplicate_group': $c['groups'][] = $c['groups'][0]; break;
            case 'duplicate_profile': $c['profiles'][] = $c['profiles'][0]; break;
            case 'duplicate_state': $c['profiles'][0]['states'][] = 'contabilizado'; break;
            case 'timezone': $c['prepared_at'] = '2026-11-20T12:00:00-06:00'; break;
            case 'leap_second': $c['prepared_at'] = '2026-11-20T12:00:60Z'; break;
            case 'duplicate_scope': $c['profiles'][0]['center_scope'] = [1, 1]; break;
            case 'unknown_decision': $c['pending_decisions'] = ['infer_from_code']; break;
            case 'missing_field': unset($c['valid_until']); break;
            case 'null_required': $c['label'] = null; break;
            case 'object': $c['label'] = new \stdClass; break;
            case 'reviewer': $c['reviewer'] = 42; break;
            case 'nested_decision': $c['pending_decisions'] = [[]]; break;
            case 'nested_state': $c['profiles'][0]['states'] = [[]]; break;
            case 'self_cycle': $c['groups'][] = ['key' => 'self', 'parent' => 'self', 'basis' => 'credit_minus_debit', 'label' => 's', 'order' => 0]; break;
            case 'equal_validity': $c['valid_until'] = $c['valid_from']; break;
            case 'balance_period': $c['profiles'][1]['period_id'] = 123; break;
            case 'empty_scope': $c['profiles'][0]['account_scope'] = []; break;
            case 'missing_root': array_pop($c['groups']); break;
            case 'numeric_key': $c[42] = 'ambiguous'; break;
            case 'credential_field': $c['password'] = 'SYNTHETIC:never-accepted'; break;
            case 'empty_states': $c['profiles'][0]['states'] = []; break;
        }
        $r = $this->validate($c);
        self::assertSame('INVALID', $r['status'], $case); self::assertNotEmpty($r['errors']);
        self::assertNull($r['hash']); self::assertFalse($r['business_authority']);
    }

    public function test_equivalent_order_and_utc_produce_identical_hash_and_diagnostics(): void
    {
        $a = Fixture::content(); $a['assignments'][] = ['account_id' => 900002, 'group_key' => 'asset', 'order' => 0, 'corrector' => false];
        $a['profiles'][0]['states'] = ['borrador', 'contabilizado'];
        $b = array_reverse($a, true); $b['groups'] = array_reverse($b['groups']); $b['assignments'] = array_reverse($b['assignments']);
        $b['profiles'][0]['states'] = array_reverse($b['profiles'][0]['states']); $b['profiles'] = array_reverse($b['profiles']);
        $b['prepared_at'] = '2026-11-20T12:00:00+00:00';
        self::assertSame($this->validate($a), $this->validate($b));
    }

    public function test_significant_changes_and_null_versus_absence_are_distinct(): void
    {
        $c = Fixture::content(); $original = $this->validate($c)['hash'];
        $c['assignments'][0]['group_key'] = 'asset'; self::assertNotSame($original, $this->validate($c)['hash']);
        $c = Fixture::content(); $c['reference'] = null; self::assertNotSame($original, $this->validate($c)['hash']);
        $c['reference'] = 'SYNTHETIC:0.10'; $a = $this->validate($c);
        self::assertSame('SYNTHETIC:0.10', $a['canonical_content']['reference']);
        $c['reference'] = 'SYNTHETIC:0.1'; self::assertNotSame($a['hash'], $this->validate($c)['hash']);
        $c['reference'] = 'SYNTHETIC:0.10 '; self::assertNotSame($a['hash'], $this->validate($c)['hash']);
    }

    public function test_subgroup_inherits_sign_and_corrector_does_not_invert_it(): void
    {
        $c = Fixture::content(); $c['groups'][] = ['key' => 'contra_income', 'parent' => 'income', 'label' => 'SYNTHETIC corrector', 'basis' => 'credit_minus_debit', 'order' => 0];
        $c['assignments'][0]['group_key'] = 'contra_income';
        $r = $this->validate($c); self::assertSame('VALID', $r['status']);
        self::assertTrue($r['canonical_content']['assignments'][0]['corrector']);
        $c['groups'][7]['basis'] = 'debit_minus_credit'; self::assertSame('INVALID', $this->validate($c)['status']);
    }

    public function test_profiles_preserve_explicit_states_partial_scope_and_history(): void
    {
        $c = Fixture::content(); $c['profiles'][0]['states'] = ['anulado', 'borrador'];
        $c['profiles'][0]['period_id'] = 123;
        $c['profiles'][0]['account_scope'] = [900002, 900001]; $c['profiles'][0]['scope_label'] = 'partial';
        $r = $this->validate($c); self::assertSame('VALID', $r['status']);
        self::assertSame([900001, 900002], $r['canonical_content']['profiles'][1]['account_scope']);
        self::assertSame('include', $r['canonical_content']['profiles'][0]['closing']);
        self::assertSame('exclude', $r['canonical_content']['profiles'][1]['closing']);
    }

    public function test_missing_coverage_and_pending_decisions_are_not_implicit_approval(): void
    {
        $v = new FinancialPresentationContractValidator; $c = Fixture::content();
        $r = $v->validate($c, [900002, 900001]); self::assertSame('REQUIRES_ACCOUNTING_APPROVAL', $r['status']);
        self::assertSame([['coverage.missing_accounts' => [900002]]], $r['diagnostics']);
        self::assertNotNull($r['hash']); self::assertFalse($r['publishable']);
        $c['pending_decisions'] = ['taxonomy', 'universe'];
        $r = $v->validate($c); self::assertSame('REQUIRES_ACCOUNTING_APPROVAL', $r['status']);
        self::assertContains('coverage.not_verified', $r['diagnostics']); self::assertContains('approval.pending.taxonomy', $r['diagnostics']);
    }

    public function test_validity_is_utc_half_open_without_selecting_business_policy(): void
    {
        $v = new FinancialPresentationContractValidator; $c = Fixture::content(); $c['valid_until'] = '2031-01-01T00:00:00Z';
        self::assertFalse($v->withinValidity($c, '2029-12-31T23:59:59Z'));
        self::assertTrue($v->withinValidity($c, '2030-01-01T00:00:00Z'));
        self::assertFalse($v->withinValidity($c, '2031-01-01T00:00:00Z'));
        $c['valid_until'] = null; self::assertTrue($v->withinValidity($c, '2099-01-01T00:00:00Z'));
    }

    public function test_revision_changes_hash_invalidates_previous_review_and_preserves_input(): void
    {
        $v = new FinancialPresentationContractValidator; $c = Fixture::content(); $copy = $c; $hash = $this->validate($c)['hash'];
        $new = $c; $new['assignments'][0]['order'] = 20;
        $r = $v->revise($c, $hash, $new, 'draft');
        self::assertNotSame($hash, $r['hash']); self::assertFalse($r['previous_review_valid']); self::assertSame($copy, $c);
        $this->expectException(DomainException::class); $v->revise($new, $hash, $c, 'draft');
    }

    public function test_conceptually_frozen_content_cannot_be_edited(): void
    {
        $c = Fixture::content(); $this->expectException(DomainException::class);
        (new FinancialPresentationContractValidator)->revise($c, $this->validate($c)['hash'], $c, 'published_simulation');
    }

    public function test_withdrawal_is_separate_from_historical_content_and_hash(): void
    {
        $c = Fixture::content(); $before = $this->validate($c);
        $event = (new FinancialPresentationContractValidator)->withdrawal($c, $before['hash'], 'SYNTHETIC:withdrawal-only', '2030-10-01T00:00:00Z');
        self::assertSame($before['hash'], $event['subject_hash']); self::assertFalse($event['business_authority']);
        self::assertSame($before, $this->validate($c)); self::assertArrayNotHasKey('approval_id', $event);
    }

    public function test_exact_decimal_strings_are_preserved_by_canonicalizer_without_float(): void
    {
        $encoder = new FinancialPresentationCanonicalizer;
        self::assertSame('{"a":"9999999999999.99","b":"0.10"}', $encoder->encode(['b' => '0.10', 'a' => '9999999999999.99']));
        $this->expectException(DomainException::class); $encoder->encode(['b' => 0.1]);
    }

    public function test_identity_cannot_be_changed_by_optimistic_draft_edit(): void
    {
        $c = Fixture::content(); $next = $c; $next['version_number'] = 2;
        $this->expectException(DomainException::class);
        (new FinancialPresentationContractValidator)->revise($c, $this->validate($c)['hash'], $next, 'draft');
    }

    public function test_unchanged_revision_does_not_invalidate_a_hash_based_review(): void
    {
        $c = Fixture::content(); $hash = $this->validate($c)['hash'];
        $r = (new FinancialPresentationContractValidator)->revise($c, $hash, $c, 'draft');
        self::assertTrue($r['previous_review_valid']); self::assertSame($hash, $r['hash']);
    }

    public function test_stale_withdrawal_cannot_attach_to_another_content_hash(): void
    {
        $this->expectException(DomainException::class);
        (new FinancialPresentationContractValidator)->withdrawal(Fixture::content(), str_repeat('0', 64), 'SYNTHETIC:old', '2030-10-01T00:00:00Z');
    }

    public function test_unicode_and_significant_reference_text_are_not_silently_normalized(): void
    {
        $a = Fixture::content(); $b = $a; $a['label'] = "SYNTHETIC:é"; $b['label'] = "SYNTHETIC:e\u{0301}";
        self::assertNotSame($this->validate($a)['hash'], $this->validate($b)['hash']);
        $b = $a; $b['label'] .= ' '; self::assertNotSame($this->validate($a)['hash'], $this->validate($b)['hash']);
        self::assertSame($this->validate($a), $this->validate($a));
    }

    public function test_optional_reviewer_null_is_different_from_absence_without_real_identity(): void
    {
        $a = Fixture::content(); $b = $a; $b['reviewer'] = null;
        self::assertNotSame($this->validate($a)['hash'], $this->validate($b)['hash']);
        $b['reviewer'] = 'SYNTHETIC:reviewer'; self::assertSame('VALID', $this->validate($b)['status']);
    }
}
