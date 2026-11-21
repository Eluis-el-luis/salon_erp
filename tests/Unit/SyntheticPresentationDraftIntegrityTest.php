<?php

namespace Tests\Unit;

use App\Services\{SyntheticPresentationDraftIntegrity,SyntheticPresentationDraftSnapshot,SyntheticFinancialPresentationDraftPreview,SyntheticFinancialPresentationDraftReader};
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\{SyntheticDraftReadFixtures as F,VersionedPresentationFixtures as Rich};

final class SyntheticPresentationDraftIntegrityTest extends TestCase
{
    public static function corruptions(): array
    {
        return array_map(fn($c)=>[$c], ['hash','json','provenance','actor','family','version','missing','gap','order','before_hash',
            'before_content','audit_hash','audit_actor','cross_document','creation_uuid','last_header','identity_change','business','status','duplicate_request']);
    }

    #[DataProvider('corruptions')]
    public function test_corruption_rejected_without_sql(string $case): void
    {
        $p=F::photo(); $d=$p->document; $a=$p->audits;
        match($case) {
            'hash'=>$d['content_hash']=str_repeat('0',64), 'json'=>$d['canonical_content']='{}',
            'provenance'=>$d['provenance']='{}', 'actor'=>$d['actor_id']=124, 'family'=>$d['family_uuid']='bad', 'version'=>$d['version_number']=2,
            'missing'=>array_pop($a), 'gap'=>$a[1]['revision']=3, 'order'=>$a=array_reverse($a),
            'before_hash'=>$a[1]['before_hash']=str_repeat('f',64), 'before_content'=>$a[1]['before_content']='{}',
            'audit_hash'=>$a[0]['after_hash']=str_repeat('f',64), 'audit_actor'=>$a[1]['actor_id']=124,
            'cross_document'=>$a[1]['draft_id']=12, 'creation_uuid'=>$a[0]['request_uuid']=$a[1]['request_uuid'],
            'last_header'=>$d['revision']=3, 'identity_change'=>$a[0]['after_content']=str_replace('executor_123','executor_124',$a[0]['after_content']),
            'business'=>$d['purpose']='business', 'status'=>$d['status']='published', 'duplicate_request'=>$a[1]['request_uuid']=$a[0]['request_uuid'],
        };
        $this->expectException(DomainException::class);
        (new SyntheticPresentationDraftIntegrity)->verify($d,$a,123);
    }

    public function test_valid_chain_and_frozen_state_are_not_approval(): void
    {
        $p=F::photo(); $d=$p->document; $d['status']='frozen_simulation';
        $p=new SyntheticPresentationDraftSnapshot($d,$p->audits,$p->validation,$p->snapshot);
        $r=(new SyntheticFinancialPresentationDraftPreview)->present($p,'result',F::compatible());
        self::assertSame($d['content_hash'],$r['document']['revalidated_hash']);
        self::assertSame('frozen_simulation',$r['document']['status']);
        self::assertFalse($r['business_authority']); self::assertFalse($r['publishable']); self::assertFalse($r['issued_statement']);
        self::assertSame(0,$r['adapted']['presentation']['subtotals_cents']['result']);
        self::assertSame(-2000,$r['adapted']['presentation']['detail_contributions_cents'][2]);
    }

    public function test_snapshot_is_detached_and_immutable(): void
    {
        $p=F::photo(); $copy=$p->document; $copy['revision']=99;
        self::assertSame(2,$p->document['revision']);
        $this->expectException(\Error::class); $p->document['revision']=99;
    }

    public function test_rich_memory_variant_preserves_contraaccounts_parent_child_and_references(): void
    {
        $p=F::photo(true,true); $r=(new SyntheticFinancialPresentationDraftPreview)->present($p,'balance',Rich::fixture());
        $v=$r['adapted']['presentation'];
        self::assertSame(-1000,$v['accounts'][704]['contribution_cents']); self::assertSame(-500,$v['accounts'][707]['contribution_cents']);
        self::assertSame(200,$v['accounts'][708]['contribution_cents']); self::assertSame(300,$v['accounts'][709]['contribution_cents']);
        self::assertSame(5300,$v['pending_result_cents']); self::assertSame(0,$v['balance_difference_cents']);
        self::assertSame(Rich::fixture()['details'],array_values($v['details']));
        self::assertSame('synthetic_memory_only',$p->snapshot['data_origin']);
    }

    public function test_memory_closing_variant_uses_distinct_profiles(): void
    {
        $p=F::photo(false,true);$s=new SyntheticFinancialPresentationDraftPreview;
        $r=$s->present($p,'result',Rich::fixture(true));$b=$s->present($p,'balance',Rich::fixture(true));
        self::assertSame([1,2,3],$r['adapted']['coverage']['detail_ids']); self::assertCount(6,$b['adapted']['coverage']['detail_ids']);
        self::assertSame(9000,$r['adapted']['presentation']['subtotals_cents']['result']); self::assertSame(9000,$b['adapted']['presentation']['groups_cents']['equity']);
    }

    public function test_incompatible_fixture_is_not_reclassified(): void
    {
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.incomplete_coverage');
        (new SyntheticFinancialPresentationDraftPreview)->present(F::photo(),'result',Rich::fixture());
    }

    public function test_no_cli_reader_authority_from_pure_tests(): void
    {
        $this->expectException(DomainException::class);$this->expectExceptionMessage('reader.boundary');
        SyntheticFinancialPresentationDraftReader::forHarness();
    }

    public function test_snapshot_validation_cannot_override_canonical_hash(): void
    {
        $p=F::photo();$v=$p->validation;$v['hash']=str_repeat('f',64);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('preview.hash_mismatch');
        (new SyntheticFinancialPresentationDraftPreview)->present(new SyntheticPresentationDraftSnapshot($p->document,$p->audits,$v,$p->snapshot),'result',F::compatible());
    }
}
