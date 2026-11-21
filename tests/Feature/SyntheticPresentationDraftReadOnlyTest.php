<?php

namespace Tests\Feature;

use App\Services\{SyntheticFinancialPresentationDraftReader as Reader,SyntheticFinancialPresentationDraftPreview as Preview,SyntheticFinancialPresentationDraftRepository};
use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\{SyntheticDraftReadFixtures as F,VersionedPresentationFixtures as Rich};

/** No Laravel traits, fixtures, INSERT, DDL or DML. Existing documents only. */
final class SyntheticPresentationDraftReadOnlyTest extends TestCase
{
    private function pdo(Reader $reader): PDO
    {
        return (new \ReflectionProperty($reader,'pdo'))->getValue($reader);
    }

    private function documents(Reader $reader): array
    {
        $pdo=$this->pdo($reader);$pdo->beginTransaction();
        try { return $pdo->query('SELECT document_uuid FROM test_financial_presentation_drafts ORDER BY id')->fetchAll(PDO::FETCH_COLUMN); }
        finally { $pdo->rollBack(); }
    }

    public function test_all_existing_chains_are_verified_and_unchanged(): void
    {
        $reader=Reader::forHarness();$uuids=$this->documents($reader);self::assertCount(42,$uuids);$audits=0;
        foreach($uuids as $uuid) {
            $a=$reader->read($uuid);$b=$reader->read($uuid);$audits+=count($a->audits);
            self::assertSame($a->document,$b->document); self::assertSame($a->audits,$b->audits);
            self::assertSame($a->validation['hash'],$a->document['content_hash']);
            self::assertSame('draft',$a->document['status']);
            self::assertSame([900001],array_column($a->validation['canonical_content']['assignments'],'account_id'));
        }
        self::assertSame(48,$audits);self::assertFalse($this->pdo($reader)->inTransaction());
    }

    public function test_owned_snapshot_is_repeatable_read_and_read_only(): void
    {
        $reader=null;$observed=[];
        $reader=Reader::forHarness(function($stage)use(&$reader,&$observed){
            $pdo=$this->pdo($reader);$observed=[$stage,$pdo->inTransaction(),$pdo->query('SELECT @@SESSION.transaction_isolation')->fetchColumn(),(int)$pdo->query('SELECT @@SESSION.transaction_read_only')->fetchColumn()];
        });
        $p=$reader->read($this->documents($reader)[0]);
        self::assertSame(['after_header',true,'REPEATABLE-READ',1],$observed);
        self::assertTrue($p->snapshot['owned']);self::assertTrue($p->snapshot['read_only']);
        self::assertFalse($this->pdo($reader)->inTransaction());
    }

    public function test_stored_content_drives_compatible_memory_presentation(): void
    {
        $reader=Reader::forHarness();$uuid=$this->documents($reader)[0];$p=$reader->read($uuid);$preview=new Preview;
        $a=$preview->present($p,'result',F::compatible());$b=$preview->present($reader->read($uuid),'result',F::compatible());
        self::assertSame($a,$b);self::assertSame($uuid,$a['document']['uuid']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$a['derived_presentation_hash']);
        self::assertSame($p->document['content_hash'],$a['adapted']['source']['content_hash']);
        self::assertSame([1,2],$a['adapted']['coverage']['detail_ids']);
        self::assertSame(0,$a['adapted']['presentation']['subtotals_cents']['result']);
        self::assertSame(-2000,$a['adapted']['presentation']['detail_contributions_cents'][2]);
        self::assertFalse($a['business_authority']);self::assertFalse($a['publishable']);self::assertFalse($a['issued_statement']);
        $balance=$preview->present($p,'balance',F::compatible());
        self::assertSame(0,$balance['adapted']['presentation']['balance_difference_cents']);
        self::assertSame(F::compatible()['details'],array_values($balance['adapted']['presentation']['details']));
        $evidence=['existing_document'=>$uuid,'revision'=>(int)$p->document['revision'],'hash'=>$p->document['content_hash'],
            'fixture_hash'=>$a['adapted']['fixture']['hash'],'derived_presentation_hash'=>$a['derived_presentation_hash'],
            'classification_hash'=>$a['adapted']['presentation']['classification_hash'],'business_authority'=>false];
        $path=dirname(__DIR__,2).'/storage/app/private/ac05b3b4-read-'.bin2hex(random_bytes(8)).'.json';
        $f=fopen($path,'xb');if(!$f)throw new \RuntimeException('Evidence collision.');
        fwrite($f,json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($f);
    }

    public function test_existing_document_rejects_incompatible_fixture_without_reassignment(): void
    {
        $r=Reader::forHarness();$p=$r->read($this->documents($r)[0]);
        $this->expectException(DomainException::class);$this->expectExceptionMessage('adapter.incomplete_coverage');
        (new Preview)->present($p,'balance',Rich::fixture());
    }

    public function test_internal_reader_does_not_acquire_repository_writer_contract(): void
    {
        self::assertFalse(defined('SALON_SYNTHETIC_DRAFT_HARNESS'));
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Synthetic draft CLI boundary rejected.');
        SyntheticFinancialPresentationDraftRepository::forHarness();
    }

    public function test_rejects_borrowed_transaction(): void
    {
        $r=Reader::forHarness();$uuid=$this->documents($r)[0];$pdo=$this->pdo($r);$pdo->beginTransaction();
        try { $r->read($uuid);self::fail('Borrowed transaction accepted.'); }
        catch(DomainException $e){self::assertSame('reader.snapshot_must_be_owned',$e->getMessage());}
        finally{$pdo->rollBack();}
    }

    public function test_refuses_read_write_session_before_reading_document(): void
    {
        $r=Reader::forHarness();$uuid=$this->documents($r)[0];$pdo=$this->pdo($r);
        $pdo->exec('SET SESSION TRANSACTION READ WRITE'); // Session setting only, no data mutation.
        try { $r->read($uuid);self::fail('Writable session accepted.'); }
        catch(DomainException $e){self::assertSame('reader.snapshot_settings',$e->getMessage());}
        finally{$pdo->exec('SET SESSION TRANSACTION READ ONLY');}
    }

    public function test_exception_after_header_rolls_back_owned_read_snapshot(): void
    {
        $r=Reader::forHarness(fn()=>throw new \RuntimeException('SYNTHETIC:read failure'));$uuid=$this->documents($r)[0];
        try{$r->read($uuid);self::fail('Injected exception ignored.');}
        catch(\RuntimeException $e){self::assertSame('SYNTHETIC:read failure',$e->getMessage());}
        self::assertFalse($this->pdo($r)->inTransaction());self::assertSame('0',getenv('SALON_TEST_DB_RESET_ALLOWED'));
    }

    public function test_missing_document_and_invalid_identity_are_controlled(): void
    {
        $r=Reader::forHarness();
        foreach(['invalid'=>'reader.uuid','UPDATE test_financial_presentation_drafts SET revision=1'=>'reader.uuid',
            'ffffffff-ffff-4fff-8fff-ffffffffffff'=>'document.not_found']as $uuid=>$error){
            try{$r->read($uuid);self::fail('Invalid identity accepted.');}catch(DomainException $e){self::assertSame($error,$e->getMessage());}
        }
        self::assertFalse($this->pdo($r)->inTransaction());
    }

    public function test_environment_rechecked_on_every_read(): void
    {
        $r=Reader::forHarness();$uuid=$this->documents($r)[0];$env=getenv('APP_ENV');putenv('APP_ENV=production');
        try{$r->read($uuid);self::fail('Foreign environment accepted.');}
        catch(DomainException $e){self::assertSame('reader.boundary',$e->getMessage());}
        finally{putenv('APP_ENV='.$env);}
    }
}
