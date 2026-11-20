<?php

namespace Tests\Feature;

use App\Services\SyntheticFinancialPresentationDraftRepository as Repository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\{FinancialPresentationContractFixtures as Fixture,SyntheticPresentationHarness as Harness};

/** Real child processes and PDO connections; only new synthetic evidence is committed. */
final class SyntheticPresentationDraftConcurrencyTest extends TestCase
{
    public static function races(): array { return [['revise'],['create_same'],['create_different'],['family_collision'],['revision_replay'],['rollback_then_create']]; }

    private function start(array $payload): array
    {
        $path=dirname(__DIR__,2).'/storage/app/private/ac05b3b2-race-'.bin2hex(random_bytes(8)).'.json';
        $f=fopen($path,'xb');fwrite($f,json_encode($payload,JSON_THROW_ON_ERROR));fclose($f);
        $p=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',dirname(__DIR__).'/Support/ac05b3b2-worker.php',$path],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
        self::assertIsResource($p);stream_set_timeout($pipes[1],20);
        return ['process'=>$p,'pipes'=>$pipes,'ready'=>json_decode(trim((string)fgets($pipes[1])),true,512,JSON_THROW_ON_ERROR),'fixture'=>$path];
    }
    private function line(array $worker): string { $line=fgets($worker['pipes'][1]);self::assertNotFalse($line);return trim($line); }
    private function command(array $worker,string $text): void { self::assertNotFalse(fwrite($worker['pipes'][0],$text."\n"));fflush($worker['pipes'][0]); }
    private function finish(array &$worker): array
    {
        $result=json_decode($this->line($worker),true,512,JSON_THROW_ON_ERROR);
        fclose($worker['pipes'][0]);fclose($worker['pipes'][1]);self::assertSame('',stream_get_contents($worker['pipes'][2]));fclose($worker['pipes'][2]);
        self::assertSame(0,proc_close($worker['process']));$worker['process']=null;$worker['pipes']=[];return $result;
    }

    #[DataProvider('races')]
    public function test_persisted_race_with_independent_connections_and_owned_locks(string $case): void
    {
        $repo=Repository::forHarness();$observer=Harness::connect();
        $uuid=SyntheticPresentationDraftPersistenceTest::uuid();$c=Fixture::content();$c['family_uuid']=SyntheticPresentationDraftPersistenceTest::uuid();$c['author']=$repo->author();
        $initial=null;
        if(in_array($case,['revise','revision_replay'],true))$initial=$repo->create($uuid,$c,'SYNTHETIC:race_initial');
        $before=[(int)$observer->query('SELECT COUNT(*) FROM test_financial_presentation_drafts')->fetchColumn(),(int)$observer->query('SELECT COUNT(*) FROM test_financial_presentation_draft_audits')->fetchColumn()];
        $a=['action'=>$initial?'revise':'create','uuid'=>$uuid,'content'=>$c,'hold'=>true,'hold_stage'=>$initial?'after_lock':'after_header','fault'=>$case==='rollback_then_create'?'after_audit':null];
        if($initial){$a['content']['label'].=' race A';$a+=['request_uuid'=>SyntheticPresentationDraftPersistenceTest::uuid(),'expected_revision'=>1,'expected_hash'=>$initial['document']['content_hash']];}
        $b=$a;$b['hold']=false;$b['fault']=null;
        if(in_array($case,['revise','create_different'],true)){$b['content']['label'].=' race B';if($initial)$b['request_uuid']=SyntheticPresentationDraftPersistenceTest::uuid();}
        if($case==='family_collision')$b['uuid']=SyntheticPresentationDraftPersistenceTest::uuid();
        $first=$second=null;$evidence=[];
        try{
            $first=$this->start($a);$second=$this->start($b);
            self::assertTrue($first['ready']['ready']);self::assertTrue($second['ready']['ready']);
            self::assertNotSame($first['ready']['connection_id'],$second['ready']['connection_id']);
            self::assertNotSame($repo->connectionId(),$first['ready']['connection_id']);
            $this->command($first,'GO');self::assertSame('ENTERING',$this->line($first));self::assertSame('LOCKED',$this->line($first));
            $this->command($second,'GO');self::assertSame('ENTERING',$this->line($second));
            // SHOW PROCESSLIST exposes only this SQL user's own sessions; no PROCESS privilege is added.
            $observed=false;$deadline=microtime(true)+8;$observations=[];
            while(microtime(true)<$deadline){
                foreach($observer->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $p){
                    $observations[]=$p;
                    if((int)$p['Id']===$second['ready']['connection_id'] && is_string($p['Info']) && str_contains($p['Info'],'test_financial_presentation_drafts') && in_array($p['Command'],['Query','Execute'],true)){$observed=true;break;}
                }
                if($observed)break;usleep(20000);
            }
            if(!$observed){$f=fopen(dirname(__DIR__,2).'/storage/app/private/ac05b3b2-race-diagnostic-'.bin2hex(random_bytes(8)).'.json','xb');fwrite($f,json_encode(['case'=>$case,'second'=>$second['ready'],'observations'=>$observations],JSON_THROW_ON_ERROR));fclose($f);}
            self::assertTrue($observed,'Second connection must reach the conflicting SQL while first owns the row/unique key.');
            // Windows proc_open pipes do not reliably support nonblocking reads.
            // The live conflicting SQL plus the first owner's barrier proves overlap.
            self::assertTrue(proc_get_status($second['process'])['running']);
            $this->command($first,'RELEASE');$ra=$this->finish($first);$rb=$this->finish($second);
            $persisted=$repo->get($uuid);$d=$persisted['document'];
            self::assertSame(hash('sha256',$d['canonical_content']),$d['content_hash']);
            self::assertCount($initial?2:1,$persisted['history']);self::assertFalse($persisted['business_authority']);
            if($case==='rollback_then_create'){self::assertSame('rolled_back',$ra['outcome']);self::assertSame('ok',$rb['outcome']);}
            else self::assertSame('ok',$ra['outcome']);
            if(in_array($case,['revise','create_different','family_collision'],true)){
                self::assertSame('conflict',$rb['outcome']);self::assertSame($ra['hash'],$d['content_hash']);
                self::assertSame(match($case){'revise'=>'revision.stale_or_frozen','family_collision'=>'identity.family_version_conflict',default=>'identity.content_conflict'},$rb['reason']);
            }else{self::assertSame('ok',$rb['outcome']);self::assertSame($rb['hash'],$d['content_hash']);self::assertSame($case!=='rollback_then_create',$rb['replayed']);}
            $after=[(int)$observer->query('SELECT COUNT(*) FROM test_financial_presentation_drafts')->fetchColumn(),(int)$observer->query('SELECT COUNT(*) FROM test_financial_presentation_draft_audits')->fetchColumn()];
            self::assertSame([$before[0]+($initial?0:1),$before[1]+1],$after);
            self::assertSame(0,(int)$observer->query('SELECT COUNT(*) FROM test_financial_presentation_draft_audits a LEFT JOIN test_financial_presentation_drafts d ON d.id=a.draft_id WHERE d.id IS NULL')->fetchColumn());
            $evidence=['case'=>$case,'first_connection'=>$first['ready']['connection_id'],'second_connection'=>$second['ready']['connection_id'],
                'conflicting_query_observed'=>$observed,'first'=>$ra,'second'=>$rb,'persisted_document_id'=>(int)$d['id'],
                'document_uuid'=>$uuid,'persisted_hash'=>$d['content_hash'],'revision'=>(int)$d['revision'],'audits'=>count($persisted['history']),'processes_closed'=>true];
            $f=fopen(dirname(__DIR__,2).'/storage/app/private/ac05b3b2-race-result-'.bin2hex(random_bytes(8)).'.json','xb');fwrite($f,json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($f);
        }finally{
            foreach([$first,$second] as $w)if($w&&is_resource($w['process'])){
                if(isset($w['pipes'][0])&&is_resource($w['pipes'][0])){fwrite($w['pipes'][0],"RELEASE\n");fclose($w['pipes'][0]);}
                foreach([1,2] as $i)if(isset($w['pipes'][$i])&&is_resource($w['pipes'][$i]))fclose($w['pipes'][$i]);
                proc_terminate($w['process']);proc_close($w['process']);
            }
            putenv('SALON_TEST_DB_RESET_ALLOWED=0');
        }
    }
}
