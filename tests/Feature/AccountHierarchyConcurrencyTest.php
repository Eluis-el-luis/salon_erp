<?php

namespace Tests\Feature;

use App\Models\CuentaContable;
use App\Services\AccountIntegrityService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Solo cuentas de fixture sin uso; dos conexiones reales, sin reset ni documentos. */
class AccountHierarchyConcurrencyTest extends TestCase
{
    public function test_cross_reparenting_serializes_and_persists_no_cycle(): void
    {
        $service=app(AccountIntegrityService::class);$ids=[];$workers=[];$markers=[];
        $base=['tipo'=>'gasto','naturaleza'=>'deudora','permite_movimiento'=>false,
            'cuenta_padre_id'=>CuentaContable::where('codigo','6')->value('id')];
        try {
            $a=$service->save($base+['codigo'=>'6.AC02'.bin2hex(random_bytes(3)),'nombre'=>'AC02 race A']);$ids[]=$a->id;
            $b=$service->save($base+['codigo'=>'6.AC02'.bin2hex(random_bytes(3)),'nombre'=>'AC02 race B']);$ids[]=$b->id;
            foreach ([[$a,$b,true],[$b,$a,false]] as [$node,$parent,$hold]) {
                $data=['codigo'=>$node->codigo,'nombre'=>$node->nombre,'tipo'=>'gasto','naturaleza'=>'deudora',
                    'permite_movimiento'=>false,'cuenta_padre_id'=>$parent->id];
                $marker=storage_path('app/private/ac02-race-'.bin2hex(random_bytes(8)).'.result');$markers[]=$marker;
                $p=proc_open([PHP_BINARY,'-d','zend.exception_ignore_args=1',base_path('tests/Support/ac02-hierarchy-worker.php'),
                    json_encode(['id'=>$node->id,'data'=>$data,'hold'=>$hold,'result'=>$marker])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,base_path());
                if (!is_resource($p)) throw new \RuntimeException('No se inició worker');
                stream_set_timeout($pipes[1],20);$workers[]=[$p,$pipes];
                $this->assertSame("READY\n",str_replace("\r",'',fgets($pipes[1])));
            }
            fwrite($workers[0][1][0],"go\n");fflush($workers[0][1][0]);
            $this->assertSame("LOCKED\n",str_replace("\r",'',fgets($workers[0][1][1])));
            fwrite($workers[1][1][0],"go\n");fflush($workers[1][1][0]);
            $this->assertSame("ATTEMPT\n",str_replace("\r",'',fgets($workers[1][1][1])));
            // stream_select sobre pipes de proc_open no es una barrera fiable en Windows.
            usleep(200000);clearstatcache(true,$markers[1]);
            $this->assertFileDoesNotExist($markers[1],'El segundo worker no ha terminado mientras el primero mantiene el mutex.');
            fwrite($workers[0][1][0],"release\n");fflush($workers[0][1][0]);
            $results=[];$exits=[];
            foreach ($workers as &$worker) {
                [$p,$pipes]=$worker;fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);
                $err=stream_get_contents($pipes[2]);fclose($pipes[2]);$exits[]=proc_close($p);$worker=null;
                $this->assertSame('',$err);
            }
            unset($worker);
            $this->assertSame(['RESULT:accepted','RESULT:rejected'],$results);$this->assertSame([0,2],$exits);
            $connections=array_map(fn ($f)=>json_decode(file_get_contents($f),true)['connection_id'],$markers);
            $this->assertNotSame($connections[0],$connections[1]);
            $this->assertSame($b->id,$a->refresh()->cuenta_padre_id);
            $this->assertSame($base['cuenta_padre_id'],$b->refresh()->cuenta_padre_id);
            $this->assertSame($b->nivel+1,$a->nivel);
            $this->assertSame(0,DB::transactionLevel());
        } finally {
            foreach ($workers as $worker) if ($worker!==null) {foreach($worker[1] as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($worker[0]);proc_close($worker[0]);}
            // Solo fixtures de catálogo sin uso, mediante la misma eliminación protegida.
            for ($pass=0;$pass<count($ids);$pass++) foreach ($ids as $id) {
                if (CuentaContable::find($id) && !CuentaContable::where('cuenta_padre_id',$id)->exists()) $service->remove($id);
            }
            foreach ($markers as $marker) if (is_file($marker)) unlink($marker);
        }
    }
}
