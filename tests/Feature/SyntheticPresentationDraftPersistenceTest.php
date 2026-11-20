<?php

namespace Tests\Feature;

use App\Services\{FinancialPresentationContractValidator,SyntheticFinancialPresentationDraftRepository as Repository};
use DomainException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\{FinancialPresentationContractFixtures as Fixture,SyntheticPresentationHarness as Harness,SyntheticPresentationDraftSchema};

/** Adds only new synthetic draft evidence. No framework TestCase, seeds or financial writers. */
final class SyntheticPresentationDraftPersistenceTest extends TestCase
{
    private Repository $repo;
    private PDO $pdo;

    protected function setUp(): void { $this->repo = Repository::forHarness(); $this->pdo = Harness::connect(); }
    public static function uuid(): string { $s=bin2hex(random_bytes(16)); return substr($s,0,8).'-'.substr($s,8,4).'-4'.substr($s,13,3).'-8'.substr($s,17,3).'-'.substr($s,20); }
    private function content(): array { $c=Fixture::content(); $c['family_uuid']=self::uuid(); $c['author']=$this->repo->author(); return $c; }
    private function counts(): array { return [(int)$this->pdo->query('SELECT COUNT(*) FROM test_financial_presentation_drafts')->fetchColumn(),(int)$this->pdo->query('SELECT COUNT(*) FROM test_financial_presentation_draft_audits')->fetchColumn()]; }

    public function test_create_recover_hash_and_server_audit_without_authority(): void
    {
        $c=$this->content(); $id=self::uuid(); $r=$this->repo->create($id,$c,'SYNTHETIC:create'); $d=$r['document'];
        self::assertSame($id,$d['document_uuid']); self::assertSame('draft',$d['status']); self::assertSame(1,(int)$d['revision']);
        self::assertSame(hash('sha256',$d['canonical_content']),$d['content_hash']);
        self::assertSame($r,$this->repo->get($id)); self::assertFalse($r['business_authority']); self::assertFalse($r['publishable']);
        self::assertCount(1,$r['history']); self::assertNull($r['history'][0]['before_hash']); self::assertNotEmpty($d['created_at']);
        self::assertSame($d['content_hash'],$r['history'][0]['after_hash']);
        self::assertSame('REQUIRES_ACCOUNTING_APPROVAL',(new FinancialPresentationContractValidator)->validate($c)['status']);
    }

    public function test_repeated_creation_is_idempotent_and_divergent_content_conflicts(): void
    {
        $c=$this->content(); $uuid=self::uuid(); $first=$this->repo->create($uuid,$c,'SYNTHETIC:create'); $counts=$this->counts();
        $r=$this->repo->create($uuid,$c,'SYNTHETIC:create'); self::assertTrue($r['replayed']); self::assertSame($first['document'],$r['document']); self::assertSame($counts,$this->counts());
        $c['label'].=' changed';
        try {$this->repo->create($uuid,$c,'SYNTHETIC:create');self::fail('Divergent identity accepted');} catch(DomainException $e){self::assertSame('identity.content_conflict',$e->getMessage());}
        self::assertSame($counts,$this->counts());
    }

    public function test_family_version_unique_even_with_other_document_uuid(): void
    {
        $c=$this->content();$this->repo->create(self::uuid(),$c,'SYNTHETIC:create');$before=$this->counts();
        try{$this->repo->create(self::uuid(),$c,'SYNTHETIC:create');self::fail('Duplicate family');}catch(DomainException $e){self::assertSame('identity.family_version_conflict',$e->getMessage());}
        self::assertSame($before,$this->counts());
    }

    public function test_two_readers_stale_update_replay_and_full_audit_chain(): void
    {
        $c=$this->content();$uuid=self::uuid();$this->repo->create($uuid,$c,'SYNTHETIC:create');
        $reader=Repository::forHarness();self::assertNotSame($this->repo->connectionId(),$reader->connectionId());
        $a=$this->repo->get($uuid);$b=$reader->get($uuid);self::assertSame($a,$b);
        $c['label'].=' revised';$request=self::uuid();$r=$this->repo->revise($uuid,$request,1,$a['document']['content_hash'],$c,'SYNTHETIC:revision');
        self::assertSame(2,(int)$r['document']['revision']);self::assertCount(2,$r['history']);
        self::assertSame($a['document']['canonical_content'],$r['history'][1]['before_content']);
        self::assertSame($r['document']['canonical_content'],$r['history'][1]['after_content']);
        $counts=$this->counts();self::assertTrue($reader->revise($uuid,$request,1,$a['document']['content_hash'],$c,'SYNTHETIC:revision')['replayed']);
        try{$reader->revise($uuid,self::uuid(),1,$b['document']['content_hash'],$c,'SYNTHETIC:stale');self::fail('Lost update');}catch(DomainException $e){self::assertSame('revision.stale_or_frozen',$e->getMessage());}
        self::assertSame($counts,$this->counts());self::assertSame($r,$this->repo->get($uuid));
        $c['label'].=' another';
        try{$reader->revise($uuid,$request,1,$a['document']['content_hash'],$c,'SYNTHETIC:revision');self::fail('Divergent replay');}catch(DomainException $e){self::assertSame('request.content_conflict',$e->getMessage());}
    }

    public static function invalidContent(): array { return [['business'],['actor'],['hash'],['assignment'],['scope']]; }
    #[DataProvider('invalidContent')]
    public function test_invalid_provenance_and_structure_do_not_write(string $case): void
    {
        $c=$this->content();$before=$this->counts();
        match($case){'business'=>$c['purpose']='business','actor'=>$c['author']='SYNTHETIC:manipulated','hash'=>$c['content_hash']=str_repeat('a',64),'assignment'=>$c['assignments'][]=$c['assignments'][0],'scope'=>$c['installation_scope']='business'};
        try{$this->repo->create(self::uuid(),$c,'SYNTHETIC:create');self::fail('Invalid write');}catch(DomainException){self::assertSame($before,$this->counts());}
    }

    public static function faults(): array { return [['after_header'],['after_update'],['after_audit'],['before_commit']]; }
    #[DataProvider('faults')]
    public function test_rollback_entire_document_and_audit_on_error(string $stage): void
    {
        $c=$this->content();$uuid=self::uuid();$current=$this->repo->create($uuid,$c,'SYNTHETIC:create');$before=$this->counts();
        $broken=Repository::forHarness(static function($point)use($stage){if($point===$stage)throw new \Error('SYNTHETIC:injected');});
        $c['label'].=' changed';
        try{
            if($stage==='after_header'){$c['family_uuid']=self::uuid();$broken->create(self::uuid(),$c,'SYNTHETIC:fault');}
            else $broken->revise($uuid,self::uuid(),1,$current['document']['content_hash'],$c,'SYNTHETIC:fault');
            self::fail('Fault did not happen');
        }catch(\Error $e){self::assertSame('SYNTHETIC:injected',$e->getMessage());}
        self::assertSame($before,$this->counts());self::assertSame($current,$this->repo->get($uuid));
    }

    public function test_invalid_revision_preserves_current_content(): void
    {
        $c=$this->content();$uuid=self::uuid();$r=$this->repo->create($uuid,$c,'SYNTHETIC:create');$before=$this->counts();$c['version_number']=2;
        try{$this->repo->revise($uuid,self::uuid(),1,$r['document']['content_hash'],$c,'SYNTHETIC:invalid');self::fail('Identity change');}catch(DomainException){self::assertSame($r,$this->repo->get($uuid));self::assertSame($before,$this->counts());}
    }

    public function test_non_draft_state_fixture_rejects_edit_without_publication(): void
    {
        $c=$this->content();$uuid=self::uuid();$r=$this->repo->create($uuid,$c,'SYNTHETIC:create');
        // New dedicated fixture only, restored in finally. No policy publication or historical data.
        $s=$this->pdo->prepare("UPDATE test_financial_presentation_drafts SET status='frozen_simulation' WHERE id=?");$s->execute([$r['document']['id']]);
        try{
            self::assertSame('frozen_simulation',$this->repo->get($uuid)['document']['status']);$c['label'].=' blocked';
            try{$this->repo->revise($uuid,self::uuid(),1,$r['document']['content_hash'],$c,'SYNTHETIC:blocked');self::fail('Frozen edited');}catch(DomainException $e){self::assertSame('revision.stale_or_frozen',$e->getMessage());}
        }finally{$s=$this->pdo->prepare("UPDATE test_financial_presentation_drafts SET status='draft' WHERE id=?");$s->execute([$r['document']['id']]);}
        self::assertSame($r,$this->repo->get($uuid));
    }

    public function test_constraints_reject_hash_business_and_orphan_without_changes(): void
    {
        $c=$this->content();$r=$this->repo->create(self::uuid(),$c,'SYNTHETIC:create');$before=$this->counts();
        foreach([['UPDATE test_financial_presentation_drafts SET content_hash=? WHERE id=?',[str_repeat('0',64),$r['document']['id']]],
            ["UPDATE test_financial_presentation_drafts SET purpose='business' WHERE id=?",[$r['document']['id']]],
            ['DELETE FROM test_financial_presentation_drafts WHERE id=?',[$r['document']['id']]]] as [$sql,$args]){
            try{$this->pdo->prepare($sql)->execute($args);self::fail('Constraint bypass');}catch(PDOException $e){self::assertContains((int)$e->errorInfo[1],[3819,1451]);}
        }
        $a=$r['history'][0];
        try{$this->pdo->prepare('INSERT INTO test_financial_presentation_draft_audits (draft_id,actor_id,request_uuid,event,revision,expected_revision,after_content,after_hash,reason) VALUES(?,?,?,\'created\',1,0,?,?,?)')
            ->execute([1844674407370955160,$a['actor_id'],self::uuid(),$a['after_content'],$a['after_hash'],'SYNTHETIC:orphan']);self::fail('Orphan accepted');}
        catch(PDOException $e){self::assertSame(1452,(int)$e->errorInfo[1]);}
        self::assertSame($before,$this->counts());
    }

    public function test_rollback_guard_preserves_documents(): void
    {
        $this->repo->create(self::uuid(),$this->content(),'SYNTHETIC:create');$counts=$this->counts();
        try{SyntheticPresentationDraftSchema::removeEmpty($this->pdo);self::fail('Evidence dropped');}
        catch(\RuntimeException $e){self::assertStringContainsString('Rollback blocked',$e->getMessage());}
        self::assertSame($counts,$this->counts());
    }
}
