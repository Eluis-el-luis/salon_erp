<?php

namespace Tests\Feature;

use App\Services\AccountIntegrityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingPolicyFixtures;
use Tests\TestCase;

/** Conserva dos documentos sintéticos y su auditoría para revisión; no son configuración operativa. */
class AccountingPolicyConcurrencyTest extends TestCase
{
    use AccountingPolicyFixtures;
    public function test_two_real_publications_cannot_overlap_and_keep_durable_evidence(): void
    {
        $actor=$this->policyActor();$workers=[];$markers=[];
        $data=['valid_from'=>'2099-01-01T00:00:00Z','valid_until'=>'2099-02-01T00:00:00Z'];
        $this->assertFalse(DB::table('accounting_policy_versions')->where('valid_from','2099-01-01 00:00:00')->exists(),'No reutilizar una corrida anterior.');
        $a=$this->readyPolicy($data);$b=$this->readyPolicy($data);
        try {
            foreach([[$a,true],[$b,false]] as [$v,$hold]) {
                $marker=storage_path('app/private/ac03a-publish-'.bin2hex(random_bytes(8)).'.result');$markers[]=$marker;
                $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac03a-publish-worker.php'),
                    json_encode(['actor'=>$actor->id,'id'=>$v->id,'hash'=>$v->content_hash,'hold'=>$hold,'result'=>$marker])],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                if(!is_resource($p))throw new \RuntimeException('No se inició worker');stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];
                $this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));
            }
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);$this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);$this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            usleep(200000);clearstatcache(true,$markers[1]);$this->assertFileDoesNotExist($markers[1]);
            fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);$results=[];$codes=[];
            foreach($workers as &$worker){[$p,$pipes]=$worker;fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
                $error=stream_get_contents($pipes[2]);fclose($pipes[2]);$codes[]=proc_close($p);$worker=null;$this->assertSame('',$error);}
            unset($worker);$this->assertSame(['RESULT:accepted','RESULT:rejected'],$results);$this->assertSame([0,2],$codes);
            $connections=array_map(fn($f)=>json_decode(file_get_contents($f),true)['connection_id'],$markers);$this->assertNotSame($connections[0],$connections[1]);
            $this->assertSame('published',DB::table('accounting_policy_versions')->find($a->id)->status);
            $this->assertSame('draft',DB::table('accounting_policy_versions')->find($b->id)->status);
            $this->assertSame(1,DB::table('accounting_policy_audits')->whereIn('policy_version_id',[$a->id,$b->id])->where('event','published')->count());
            $this->assertSame(12,DB::table('accounting_account_bindings')->whereIn('policy_version_id',[$a->id,$b->id])->count());
            $this->assertSame(5,DB::table('accounting_policy_audits')->whereIn('policy_version_id',[$a->id,$b->id])->count());
            $this->assertSame(0,DB::transactionLevel());
        } finally {
            foreach($workers as $worker)if($worker!==null){foreach($worker[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($worker[0]);proc_close($worker[0]);}
            foreach($markers as $marker)if(is_file($marker))unlink($marker);
            // No borrar versiones/asociaciones/auditoría para limpiar el ensayo.
        }
    }
}
