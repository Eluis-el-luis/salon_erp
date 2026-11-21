<?php

namespace Tests\Unit;

use App\Services\{FinancialPresentationContractValidator,FinancialPresentationSyntheticAdapter};
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\VersionedPresentationFixtures as F;

/** No Laravel/bootstrap/SQL. Expected cents are literal sums of independent fixture documents. */
final class FinancialPresentationSyntheticAdapterTest extends TestCase
{
    private function read(string $report='result',?array $c=null,?array $f=null): array
    {
        return (new FinancialPresentationSyntheticAdapter)->read($c??F::contract(),$report,$f??F::fixture());
    }

    public function test_root_totals_results_and_balance_are_exact_and_coherent(): void
    {
        $r=$this->read();$b=$this->read('balance');$p=$r['presentation'];
        self::assertSame(['asset'=>106300,'liability'=>1000,'equity'=>100000,'income'=>11000,'cost'=>2000,'operating_expense'=>3300,'other_expense'=>400],$p['groups_cents']);
        self::assertSame(['gross'=>9000,'operating'=>5700,'result'=>5300],$p['subtotals_cents']);
        self::assertSame(132300,$p['debit_cents']);self::assertSame(132300,$p['credit_cents']);
        self::assertSame(5300,$b['presentation']['pending_result_cents']);self::assertSame(0,$b['presentation']['balance_difference_cents']);
        self::assertCount(29,$p['details']);self::assertSame('complete',$r['coverage']['status']);
        self::assertCount(11,$r['coverage']['account_ids']);
    }

    public function test_correctors_inactive_history_and_parent_child_contribute_once(): void
    {
        $f=F::fixture();$original=$f;
        foreach($f['details'] as &$row)$row['bindings']=[101,102,103];unset($row);
        $r=$this->read('balance',null,$f);$p=$r['presentation'];
        self::assertSame(-1000,$p['accounts'][704]['contribution_cents']);self::assertSame(-500,$p['accounts'][707]['contribution_cents']);
        self::assertSame(2800,$p['accounts'][706]['contribution_cents']);self::assertSame(200,$p['accounts'][708]['contribution_cents']);self::assertSame(300,$p['accounts'][709]['contribution_cents']);
        self::assertFalse($p['details'][9]['current_account']['active']);
        self::assertSame($f['details'],array_values($p['details']));
        self::assertSame(708,$p['details'][14]['current_account']['parent_id']);
        self::assertSame('ALTERNATIVE-705',$p['details'][7]['current_account']['code']);
        self::assertSame($original['details'][6]['debit'],$p['details'][7]['debit']);
    }

    public function test_subgroup_is_mapped_to_root_with_complete_provenance_not_extra_totals(): void
    {
        $r=$this->read();$trace=$r['assignment_provenance'][704];
        self::assertSame(['income','contra_income'],$trace['group_path']);self::assertSame('income',$trace['root_group']);
        self::assertSame('credit_minus_debit',$trace['basis']);self::assertTrue($trace['assignment']['corrector']);
        self::assertSame('contra_income',$trace['assignment']['group_key']);self::assertCount(11,$r['engine_configuration']['assignments']);
        self::assertArrayNotHasKey('contra_income',$r['presentation']['groups_cents']);self::assertFalse($r['capabilities']['subgroup_totals']);
        self::assertSame(-1000,$r['presentation']['detail_contributions_cents'][5]);
    }

    public function test_manual_reverse_charge_and_refund_keep_original_signs_and_links(): void
    {
        $r=$this->read();$p=$r['presentation'];
        $find=static fn($origin,$account)=>array_values(array_filter($p['details'],fn($d)=>$d['origin']===$origin&&$d['account_id']===$account))[0];
        $charge=$find('bancos',706);$refund=$find('bank_charge_refunds',706);$reverse=$find('reversion',706);
        self::assertSame(5000,$p['detail_contributions_cents'][$charge['detail_id']]);self::assertSame(-5000,$p['detail_contributions_cents'][$refund['detail_id']]);
        self::assertSame(10,$refund['reference_id']);self::assertSame(8,$reverse['reference_id']);
        self::assertSame(-600,$p['detail_contributions_cents'][$reverse['detail_id']]);self::assertSame('50.00',$refund['credit']);
    }

    public function test_corrected_closing_stays_out_of_results_and_in_accumulated_balance(): void
    {
        $fixture=F::fixture(true);$r=$this->read('result',null,$fixture);$b=$this->read('balance',null,$fixture);
        self::assertSame(9000,$r['presentation']['subtotals_cents']['result']);self::assertSame([1,2,3],$r['coverage']['detail_ids']);
        self::assertSame([1,2,3,4,5,6],$b['coverage']['detail_ids']);self::assertSame(9000,$b['presentation']['groups_cents']['equity']);
        self::assertSame(0,$b['presentation']['pending_result_cents']);self::assertSame(0,$b['presentation']['balance_difference_cents']);
        self::assertSame('0.00',$b['presentation']['details'][5]['debit']);self::assertSame('10.00',$b['presentation']['details'][5]['credit']);
    }

    public static function invalidContracts(): array { return [['version'],['purpose'],['origin'],['group'],['subgroup_basis'],['duplicate'],['frozen'],['hash_override'],['formula']]; }
    #[DataProvider('invalidContracts')]
    public function test_invalid_contract_rejected_before_presentation(string $case): void
    {
        $c=F::contract();
        match($case){'version'=>$c['contract']='financial-presentation-v99','purpose'=>$c['purpose']='business','origin'=>$c['origin']='administrative',
            'group'=>$c['assignments'][0]['group_key']='unknown','subgroup_basis'=>$c['groups'][7]['basis']='debit_minus_credit',
            'duplicate'=>$c['assignments'][]=$c['assignments'][0],'frozen'=>$c['status']='frozen_simulation',
            'hash_override'=>$c['content_hash']=str_repeat('0',64),'formula'=>$c['assignments'][0]['formula']='abs(balance)'};
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.invalid_contract:');$this->read('result',$c);
    }

    public function test_unclassified_selected_movement_is_rejected_even_in_counterpart(): void
    {
        $c=F::contract();array_shift($c['assignments']); // bank counterpart is part of selected original details too.
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.incomplete_coverage');$this->read('result',$c);
    }

    public function test_engine_selection_defines_coverage_without_adapter_filter_reimplementation(): void
    {
        $f=F::fixture();$row=$f['details'][0];$row['detail_id']=100;$row['account_id']=999;$row['date']='2029-01-01';$f['details'][]=$row;
        $r=$this->read('result',null,$f);self::assertNotContains(999,$r['coverage']['account_ids']);self::assertCount(29,$r['coverage']['detail_ids']);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.incomplete_coverage');$this->read('balance',null,$f);
    }

    public function test_zero_only_unclassified_detail_follows_explicit_engine_coverage_rule(): void
    {
        $f=F::fixture();$row=$f['details'][0];$row['detail_id']=100;$row['account_id']=999;$row['debit']='0.00';$f['details'][]=$row;
        $r=$this->read('result',null,$f);self::assertSame('complete',$r['coverage']['status']);self::assertNotContains(100,$r['coverage']['detail_ids']);
        self::assertSame('engine_selected_nonzero_details_require_assignment',$r['coverage']['rule']);
    }

    public static function unsupportedScopes(): array { return [['account'],['center'],['unassigned']]; }
    #[DataProvider('unsupportedScopes')]
    public function test_structurally_valid_but_unexpressible_profiles_fail_explicitly(string $scope): void
    {
        $c=F::contract();$p=&$c['profiles'][0];$p['scope_label']='partial';
        if($scope==='account')$p['account_scope']=[701];else $p['center_scope']=$scope==='center'?[9]:'unassigned';
        self::assertNotNull((new FinancialPresentationContractValidator)->validate($c)['hash']);
        $this->expectException(DomainException::class);$this->expectExceptionMessage($scope==='account'?'adapter.unsupported_account_scope':'adapter.unsupported_center_scope');$this->read('result',$c);
    }

    public function test_explicit_states_null_dates_and_period_are_delegated_without_new_defaults(): void
    {
        $c=F::contract();$f=F::fixture();$f['details']=[];$id=1;
        foreach([['10.00',null,'contabilizado',1,'2030-10-15'],['20.00','manual','borrador',1,'2030-10-15'],
            ['30.00','manual','anulado',1,'2030-10-15'],['40.00','manual','contabilizado',2,'2030-10-15'],['50.00','manual','contabilizado',1,'2030-09-30']] as [$amount,$origin,$state,$period,$date]){
            foreach([[701,$amount,'0.00'],[703,'0.00',$amount]] as [$account,$debit,$credit])$f['details'][]=['detail_id'=>$id++,'entry_id'=>$id,'account_id'=>$account,
                'period_id'=>$period,'date'=>$date,'origin'=>$origin,'state'=>$state,'debit'=>$debit,'credit'=>$credit];
        }
        self::assertSame(5000,$this->read('result',$c,$f)['presentation']['subtotals_cents']['result']);
        self::assertSame(10000,$this->read('balance',$c,$f)['presentation']['pending_result_cents']);
        $c['profiles'][0]['null_origin']='exclude';$c['profiles'][0]['period_id']=1;
        self::assertSame(0,$this->read('result',$c,$f)['presentation']['subtotals_cents']['result']);
        $c['profiles'][0]['states']=['borrador','contabilizado','anulado'];
        $r=$this->read('result',$c,$f);self::assertSame(5000,$r['presentation']['subtotals_cents']['result']);
        self::assertSame([3,4,5,6],$r['coverage']['detail_ids']);self::assertSame(1,$r['universe']['period_id']);
    }

    public static function invalidFixtures(): array { return [['currency'],['basis'],['authority'],['identity'],['row_type'],['float'],['precision'],['duplicate_detail']]; }
    #[DataProvider('invalidFixtures')]
    public function test_incompatible_or_manipulated_fixture_rejected(string $case): void
    {
        $f=F::fixture();
        match($case){'currency'=>$f['base_currency']='USD','basis'=>$f['amount_basis']='native','authority'=>$f['authorization_id']=123,
            'identity'=>$f['id']='REAL:BANK','row_type'=>$f['details'][]='invalid','float'=>$f['details'][0]['debit']=1000.0,
            'precision'=>$f['details'][0]['debit']='1000.001','duplicate_detail'=>$f['details'][]=$f['details'][0]};
        $this->expectException(DomainException::class);$this->read('result',null,$f);
    }

    public function test_versioned_identity_hash_and_fixture_evidence_are_preserved_deterministically(): void
    {
        $c=F::contract();$f=F::fixture();$original=serialize([$c,$f]);$v=(new FinancialPresentationContractValidator)->validate($c);
        $r=$this->read('result',$c,$f);
        self::assertSame($v['hash'],$r['source']['content_hash']);self::assertSame($v['canonical_json'],$r['source']['canonical_json']);
        self::assertSame($c['family_uuid'],$r['source']['family_uuid']);self::assertSame(1,$r['source']['version_number']);
        self::assertSame('financial-presentation-v1',$r['source']['contract']);self::assertSame($f['id'],$r['fixture']['id']);
        self::assertNotSame($r['source']['content_hash'],$r['presentation']['classification_hash']);
        self::assertSame($r,$this->read('result',$c,$f));self::assertSame($original,serialize([$c,$f]));
        self::assertFalse($r['business_authority']);self::assertFalse($r['publishable']);self::assertFalse($r['issued_statement']);
    }

    public function test_pending_economic_decisions_remain_pending_despite_complete_fixture_coverage(): void
    {
        $c=F::contract();$c['pending_decisions']=['taxonomy'];$r=$this->read('result',$c);
        self::assertSame('complete',$r['coverage']['status']);self::assertSame('REQUIRES_ACCOUNTING_APPROVAL',$r['validation_status']);
        self::assertContains('approval.pending.taxonomy',$r['diagnostics']);self::assertFalse($r['publishable']);
    }

    public function test_explicit_cost_to_expense_hypothesis_changes_margin_and_hash_not_originals(): void
    {
        $c=F::contract();$f=F::fixture();$before=$this->read('result',$c,$f);
        foreach($c['assignments'] as &$a)if($a['account_id']===705)$a['group_key']='operating_expense';unset($a);
        $after=$this->read('result',$c,$f);
        self::assertNotSame($before['source']['content_hash'],$after['source']['content_hash']);
        self::assertSame(11000,$after['presentation']['subtotals_cents']['gross']);
        self::assertSame(5300,$after['presentation']['subtotals_cents']['result']);
        self::assertSame(5300,$after['presentation']['groups_cents']['operating_expense']);
        self::assertSame($before['presentation']['details'],$after['presentation']['details']);
    }

    public function test_accidental_key_and_list_order_does_not_change_output_or_hash(): void
    {
        $c=F::contract();$f=F::fixture();$before=$this->read('result',$c,$f);
        $c=array_reverse($c,true);$c['groups']=array_reverse($c['groups']);$c['assignments']=array_reverse($c['assignments']);$c['profiles']=array_reverse($c['profiles']);
        self::assertSame($before,$this->read('result',$c,array_reverse($f,true)));
    }

    public function test_unknown_profile_is_rejected_without_selecting_another_one(): void
    {
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.unknown_profile');
        $this->read('cashflow');
    }
}
