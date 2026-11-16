<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** Conserva una nueva publicación sintética; nunca se borra evidencia para limpiar. */
class AccountingResolverConcurrencyTest extends TestCase
{
    use AccountingPolicyFixtures;
    public function test_selected_version_is_stable_while_independent_publication_waits(): void
    {
        $actor=$this->policyActor();$workers=[];$markers=[];
        $old=DB::table('accounting_policy_versions')->where('status','published')->where('valid_from','2099-01-01 00:00:00')->first();
        $this->assertNotNull($old);
        $this->assertFalse(DB::table('accounting_policy_versions')->where('valid_from','2088-01-01 00:00:00')->exists(),'No repetir ciegamente una corrida previa.');
        $new=$this->readyPolicy(['valid_from'=>'2088-01-01T00:00:00Z','valid_until'=>'2088-02-01T00:00:00Z','label'=>'AC03B CONCURRENCY HYPOTHETICAL ONLY']);
        try {
            $reader=storage_path('app/private/ac03b-resolution-'.bin2hex(random_bytes(8)).'.result');
            $publisher=storage_path('app/private/ac03a-publish-'.bin2hex(random_bytes(8)).'.result');$markers=[$reader,$publisher];
            foreach([['ac03b-resolution-worker.php',['actor'=>$actor->id,'id'=>$old->id,'result'=>$reader]],
                ['ac03a-publish-worker.php',['actor'=>$actor->id,'id'=>$new->id,'hash'=>$new->content_hash,'hold'=>false,'result'=>$publisher]]] as [$script,$plan]) {
                $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/'.$script),json_encode($plan)],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                if(!is_resource($p))throw new \RuntimeException('No se inició worker');stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];
                $this->assertSame(count($workers)===1?"PINNED\n":"READY\n",str_replace("\r",'',fgets($pipes[1])));
            }
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$publisher);$this->assertFileDoesNotExist($publisher);
            fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$results=[];$codes=[];
            foreach($workers as &$worker) {
                [$p,$pipes]=$worker;fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
                $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($p);$worker=null;$this->assertSame('',$error);
            }
            unset($worker);$this->assertSame(['RESULT:stable','RESULT:accepted'],$results);$this->assertSame([0,0],$codes);
            $connections=array_map(fn($f)=>json_decode(file_get_contents($f),true)['connection_id'],$markers);$this->assertNotSame($connections[0],$connections[1]);
            $this->assertEquals($old,DB::table('accounting_policy_versions')->find($old->id));
            $this->assertSame('published',DB::table('accounting_policy_versions')->find($new->id)->status);
            $this->assertSame(1,DB::table('accounting_policy_audits')->where('policy_version_id',$new->id)->where('event','published')->count());
            $this->assertSame(6,DB::table('accounting_account_bindings')->where('policy_version_id',$new->id)->count());
            $this->assertSame(0,DB::transactionLevel());
        } finally {
            foreach($workers as $w)if($w!==null){foreach($w[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($w[0]);proc_close($w[0]);}
            foreach($markers as $m)if(is_file($m))unlink($m);
        }
    }
}
