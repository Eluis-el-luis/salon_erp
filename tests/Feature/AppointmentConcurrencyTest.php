<?php

namespace Tests\Feature;

use App\Models\{Cita,Cliente,Rol,Servicio,Usuario};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sin transacción de fixtures padre: los hijos necesitan datos confirmados. */
class AppointmentConcurrencyTest extends TestCase
{
    private array $children=[];
    private array $files=[];

    private function start(array $payload,int $actor,bool $hold): int
    {
        $out=tempnam(storage_path('app/private'),'h16-out-'); $err=tempnam(storage_path('app/private'),'h16-err-');
        $this->files[]=$out; $this->files[]=$err;
        $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',base_path('tests/Support/h16-concurrency-worker.php')],
            [0=>['pipe','r'],1=>['file',$out,'a'],2=>['file',$err,'a']],$pipes,base_path());
        $this->assertIsResource($process);
        fwrite($pipes[0],json_encode(compact('payload','actor','hold'),JSON_THROW_ON_ERROR).PHP_EOL); fflush($pipes[0]);
        $this->children[]=compact('process','pipes','out','err');
        return array_key_last($this->children);
    }
    private function event(int $child,string $event,int $seconds=20): array
    {
        $until=microtime(true)+$seconds;
        do {
            foreach(file($this->children[$child]['out'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
                $row=json_decode($line,true);
                if(($row['event']??'')==='failure') $this->fail('Worker metadata: '.json_encode($row));
                if(($row['event']??'')===$event) return $row;
            }
            usleep(20000);
        } while(microtime(true)<$until);
        $this->fail('Timed out waiting for child event '.$event);
    }
    private function finish(): void
    {
        foreach($this->children as &$child) {
            $status=proc_get_status($child['process']);
            fclose($child['pipes'][0]);
            if($status['running']) {
                $until=microtime(true)+5;
                do {usleep(20000);$status=proc_get_status($child['process']);} while($status['running'] && microtime(true)<$until);
            }
            $this->assertFalse($status['running'],'Child must exit and close its PDO');
            $code=proc_close($child['process']);$child['closed']=true;
            $this->assertSame(0,$status['exitcode']>=0?$status['exitcode']:$code);
            $this->assertSame('',trim(file_get_contents($child['err'])));
        }
        unset($child);
    }

    public function test_real_mysql_requests_serialize_and_recheck_a_previous_snapshot(): void
    {
        $this->runRace(true);
    }
    public function test_different_stylists_do_not_share_the_user_mutex(): void
    {
        $this->runRace(false);
    }
    private function runRace(bool $same): void
    {
        $this->assertSame(0,DB::transactionLevel());
        $tag=bin2hex(random_bytes(6));
        $role=Rol::create(['name'=>'H16 concurrency '.$tag]);
        $users=[]; $client=null; $service=null;
        try {
            foreach(['a','b'] as $suffix) $users[]=Usuario::create(['name'=>'H16 race','email'=>$tag.$suffix.'@example.test','password'=>'fixture-password','role'=>'estilista','role_id'=>$role->id,'is_active'=>true]);
            $client=Cliente::create(['name'=>'H16 race '.$tag]);
            $service=Servicio::create(['name'=>'H16 race '.$tag,'price'=>100,'duration'=>45,'is_active'=>true]);
            $payload=['client_id'=>$client->id,'service_id'=>$service->id,'stylists'=>[$users[0]->id],'appointment_date'=>'2026-12-01T10:00'];
            $a=$this->start($payload,$users[0]->id,true);$readyA=$this->event($a,'ready');$this->event($a,'locked');
            if(!$same) $payload['stylists']=[$users[1]->id];
            $b=$this->start($payload,$users[1]->id,false);$readyB=$this->event($b,'ready');$this->event($b,'attempt');
            $this->assertNotSame($readyA['connection'],$readyB['connection']);
            if($same) {
                $waiting=false;$until=microtime(true)+10;
                do {
                    // MySQL permite ver los procesos de la propia cuenta sin PROCESS global.
                    $process=DB::selectOne('SELECT COMMAND AS cmd,INFO AS sql_text FROM information_schema.PROCESSLIST WHERE ID=?',[$readyB['connection']]);
                    $waiting=$process && in_array($process->cmd,['Execute','Query'],true) && str_contains(strtolower($process->sql_text??''),'for update');
                    if(!$waiting) usleep(20000);
                } while(!$waiting && microtime(true)<$until);
                $this->assertTrue($waiting,'Second real MySQL request must be executing the conflicting lock while A holds it');
            } else {
                // B debe poder confirmar mientras A retiene el mutex de OTRO estilista.
                $this->assertSame(201,$this->event($b,'result')['status']);
            }
            fwrite($this->children[$a]['pipes'][0],"RELEASE\n");fflush($this->children[$a]['pipes'][0]);
            $ra=$this->event($a,'result');$rb=$this->event($b,'result');
            $this->assertSame(201,$ra['status']);$this->assertSame($same?422:201,$rb['status']);
            if($same) $this->assertTrue($rb['conflict']);
            $rows=Cita::where('client_id',$client->id)->get();
            $this->assertSame($same?1:2,$rows->count());
            $actual=$rows->modelKeys(); $expected=$same?[$ra['id']]:[$ra['id'],$rb['id']];
            sort($actual);sort($expected);$this->assertSame($expected,$actual);
            $this->assertSame($same?1:2,DB::table('appointment_user')->whereIn('appointment_id',$rows->modelKeys())->count());
            $this->finish();
            foreach([$readyA['connection'],$readyB['connection']] as $id) {
                $until=microtime(true)+2;
                do { $remaining=DB::selectOne('SELECT ID FROM information_schema.PROCESSLIST WHERE ID=?',[$id]);if($remaining) usleep(20000); } while($remaining && microtime(true)<$until);
                $this->assertNull($remaining);
            }
        } finally {
            foreach($this->children as $child) if(empty($child['closed']) && is_resource($child['process'])) {
                if(is_resource($child['pipes'][0])) { @fwrite($child['pipes'][0],"RELEASE\n"); @fclose($child['pipes'][0]); }
                if(proc_get_status($child['process'])['running']) proc_terminate($child['process']);
                proc_close($child['process']);
            }
            if($client) {
                Cita::where('client_id',$client->id)->delete();
                // Fixture propio sin documentos financieros; delete() conservaría SoftDeletes.
                $client->forceDelete();
                $this->assertDatabaseMissing('clients',['id'=>$client->id]);
            }
            if($service) $service->delete();
            foreach($users as $user) $user->delete();
            $role->delete();
            foreach($this->files as $file) if(is_file($file)) unlink($file);
        }
    }
}
