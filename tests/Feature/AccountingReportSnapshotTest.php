<?php

namespace Tests\Feature;

use App\Models\CuentaContable;
use App\Services\AccountingReportExtractor;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** Parent is a read-only consumer. Child fixture writes are ALWAYS rolled back, never committed. */
class AccountingReportSnapshotTest extends TestCase
{
    use AccountingPolicyFixtures;

    public function test_owned_read_only_snapshot_excludes_an_independent_writer_and_closes_cleanly(): void
    {
        $actor=$this->policyActor();
        $expense=CuentaContable::where('codigo','6.9')->sole()->id;$bank=CuentaContable::where('codigo','1.1.1')->sole()->id;
        $currency=DB::table('monedas')->where('es_base',true)->value('id');
        $c=['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B2-CONCURRENCY-HYPOTHESIS',
            'assignments'=>[['account_id'=>$expense,'group'=>'operating_expense'],['account_id'=>$bank,'group'=>'asset']]];
        $p=['report'=>'result','from'=>'2033-09-01','until'=>'2033-09-30','states'=>['borrador','contabilizado','anulado'],
            'null_origin'=>'include','account_scope'=>[$expense,$bank],'center_scope'=>'all'];
        $process=null;$pipes=[];$ready=null;$injected=false;$readonlyRejected=false;$started=false;
        $before=DB::table('asientos_contables')->count();$originals=DB::table('detalle_asientos')->orderBy('id')->get()->toJson();
        DB::listen(function($query)use(&$process,&$pipes,&$ready,&$injected,&$readonlyRejected,&$started,$expense,$bank,$actor,$currency){
            if($injected || !str_contains($query->sql,'`detalle_asientos` as `d`') || !str_contains($query->sql,'`center_active`'))return;
            $injected=true;
            // Engine-level read-only guard, not a pretend guarantee from a SELECT allowlist.
            try{DB::statement('UPDATE detalle_asientos SET debe=debe WHERE id=0');}
            catch(\Illuminate\Database\QueryException $e){$this->assertSame(1792,(int)($e->errorInfo[1]??0));$readonlyRejected=true;}
            $process=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac05b2-snapshot-writer.php'),
                (string)$expense,(string)$bank,(string)$actor->id,(string)$currency],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
            $this->assertIsResource($process);$started=true;
            stream_set_timeout($pipes[1],15);$line=fgets($pipes[1]);
            $ready=json_decode((string)$line,true,512,JSON_THROW_ON_ERROR);$this->assertTrue($ready['ready']);
        });
        try{
            $r=app(AccountingReportExtractor::class)->compare($c,$p);
            $this->assertTrue($started);$this->assertTrue($readonlyRejected);$this->assertTrue($r['snapshot']['read_only_transaction']);
            $this->assertFalse($r['snapshot']['enclosing_fixture_transaction']);
            $this->assertNotSame($ready['connection_id'],$r['snapshot']['connection_id']);
            $this->assertSame('REPEATABLE READ',$r['snapshot']['isolation']);$this->assertSame(0,DB::transactionLevel());
            $this->assertNotContains($ready['entry_id'],array_column($r['source_details'],'entry_id'));
            $again=app(AccountingReportExtractor::class)->compare($c,$p);
            $this->assertSame($r['source_details'],$again['source_details']);
            $this->assertSame($r['legacy_reported_global'],$again['legacy_reported_global']);
            $this->assertSame($before,DB::table('asientos_contables')->count());
            fwrite($pipes[0],"rollback\n");fclose($pipes[0]);unset($pipes[0]);
            $this->assertSame('ROLLED_BACK',trim(stream_get_contents($pipes[1])));
            $this->assertSame('',stream_get_contents($pipes[2]));
            fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
            $this->assertSame(0,proc_close($process));$process=null;
            $this->assertSame($originals,DB::table('detalle_asientos')->orderBy('id')->get()->toJson());
            $this->assertFalse(DB::table('asientos_contables')->where('id',$ready['entry_id'])->exists());
            $evidence=['reader_connection_id'=>$r['snapshot']['connection_id'],'writer_connection_id'=>$ready['connection_id'],
                'writer_entry_id'=>$ready['entry_id'],'writer_committed'=>false,'writer_rolled_back'=>true,
                'read_only_write_rejected_code'=>1792,'child_exit'=>0,'original_details_unchanged'=>true];
            $path=storage_path('app/private/ac05b2-snapshot-'.bin2hex(random_bytes(5)).'.json');
            $f=fopen($path,'xb');fwrite($f,json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fclose($f);
        }finally{
            if(is_resource($process)){
                if(isset($pipes[0])&&is_resource($pipes[0])){fwrite($pipes[0],"rollback\n");fclose($pipes[0]);}
                foreach([1,2] as $i)if(isset($pipes[$i])&&is_resource($pipes[$i]))fclose($pipes[$i]);
                proc_terminate($process);proc_close($process);
            }
            $this->assertSame(0,DB::transactionLevel());
        }
    }
}
