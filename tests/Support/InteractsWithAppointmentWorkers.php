<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Dos procesos controlados y conexiones MySQL reales; nunca paraleliza una suite. */
trait InteractsWithAppointmentWorkers
{
    protected array $children=[];
    protected array $files=[];

    protected function start(array $payload,int $actor,bool $hold,?int $appointmentId=null): int
    {
        $out=tempnam(storage_path('app/private'),'agenda-out-');$err=tempnam(storage_path('app/private'),'agenda-err-');
        $this->files[]=$out;$this->files[]=$err;
        $process=proc_open([PHP_BINARY,'-d','display_errors=0','-d','zend.exception_ignore_args=1',base_path('tests/Support/h16-concurrency-worker.php')],
            [0=>['pipe','r'],1=>['file',$out,'a'],2=>['file',$err,'a']],$pipes,base_path());
        $this->assertIsResource($process);
        $input=['payload'=>$payload,'actor'=>$actor,'hold'=>$hold,'operation'=>$appointmentId===null?'reserve':'edit','appointment_id'=>$appointmentId];
        fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR).PHP_EOL);fflush($pipes[0]);
        $this->children[]=compact('process','pipes','out','err');return array_key_last($this->children);
    }
    protected function event(int $child,string $event,int $seconds=20): array
    {
        $until=microtime(true)+$seconds;
        do {
            foreach(file($this->children[$child]['out'],FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
                $row=json_decode($line,true);
                if(($row['event']??'')==='failure')$this->fail('Worker metadata: '.json_encode($row));
                if(($row['event']??'')===$event)return $row;
            }
            usleep(20000);
        }while(microtime(true)<$until);
        $this->fail('Timed out waiting for child event '.$event);
    }
    protected function waitForUserLock(int $connection): void
    {
        $waiting=false;$until=microtime(true)+10;
        do {
            $process=DB::selectOne('SELECT COMMAND AS cmd,INFO AS sql_text FROM information_schema.PROCESSLIST WHERE ID=?',[$connection]);
            $waiting=$process && in_array($process->cmd,['Execute','Query'],true) && str_contains(strtolower($process->sql_text??''),'for update');
            if(!$waiting)usleep(20000);
        }while(!$waiting && microtime(true)<$until);
        $this->assertTrue($waiting,'Second real MySQL request must wait while A holds the user mutex');
    }
    protected function release(int $child): void
    {
        fwrite($this->children[$child]['pipes'][0],"RELEASE\n");fflush($this->children[$child]['pipes'][0]);
    }
    protected function finish(): void
    {
        foreach($this->children as &$child) {
            $status=proc_get_status($child['process']);fclose($child['pipes'][0]);
            if($status['running']) {
                $until=microtime(true)+5;
                do{usleep(20000);$status=proc_get_status($child['process']);}while($status['running']&&microtime(true)<$until);
            }
            $this->assertFalse($status['running'],'Child must exit and close its PDO');
            $code=proc_close($child['process']);$child['closed']=true;
            $this->assertSame(0,$status['exitcode']>=0?$status['exitcode']:$code);
            $this->assertSame('',trim(file_get_contents($child['err'])));
        }
        unset($child);
    }
    protected function assertWorkersDisconnected(array $ids): void
    {
        foreach($ids as $id) {
            $until=microtime(true)+2;
            do{$remaining=DB::selectOne('SELECT ID FROM information_schema.PROCESSLIST WHERE ID=?',[$id]);if($remaining)usleep(20000);}while($remaining&&microtime(true)<$until);
            $this->assertNull($remaining);
        }
    }
    protected function stopAppointmentWorkers(): void
    {
        foreach($this->children as $child)if(empty($child['closed'])&&is_resource($child['process'])) {
            if(is_resource($child['pipes'][0])){@fwrite($child['pipes'][0],"RELEASE\n");@fclose($child['pipes'][0]);}
            if(proc_get_status($child['process'])['running'])proc_terminate($child['process']);
            proc_close($child['process']);
        }
        foreach($this->files as $file)if(is_file($file))unlink($file);
    }
}
