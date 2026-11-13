<?php

function dr_backup($app): void
{
    dr_assert(dr_manifest()===dr_read('preflight')['manifest'],'reviewed configuration unchanged before backup');
    dr_assert(dr_snapshot($app)===dr_read('before'),'closed baseline still exact before backup');
    dr_assert(!is_file(dr_file('backup')),'new backup not already recorded');
    $directory='E:/salon-erp-backups';
    dr_assert(str_replace('\\','/',realpath($directory))===$directory,'backup directory exact');
    $previous=$directory.'/salon_erp_20261113_054136_1807232_2e37f496.sql';
    dr_assert(is_file($previous) && strtoupper(hash_file('sha256',$previous))==='FF0753F2EBF11A9EE50EEB878F7AB3D2738CDACF532A05FDE0DBC0C987145EB5','previous backup retained and SHA256 valid');
    $file=$directory.'/salon_erp_H13D_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(6)).'.sql';
    $partial=$file.'.partial';
    $reservation=fopen($partial,'x'); if(!$reservation) throw new RuntimeException('Cannot reserve unique backup'); fclose($reservation);
    $mysql=$app['config']->get('database.connections.mysql');
    // El destino local inspeccionado no requiere secreto en argumentos.
    dr_assert($mysql['password']==='','administrative backup matches inspected local authentication');
    $args=['E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe','--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=3306','--user=root','--skip-password',
        '--single-transaction','--quick','--skip-lock-tables','--no-tablespaces','--set-gtid-purged=OFF','--column-statistics=0','--routines','--events','--triggers','--hex-blob',
        '--default-character-set=utf8mb4','--skip-add-drop-table','--skip-add-locks','--result-file='.$partial,'salon_erp'];
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
    if(!is_resource($process)) throw new RuntimeException('Cannot launch mysqldump');
    fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); $diagnostic=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit=proc_close($process); unset($diagnostic);
    dr_assert($exit===0 && is_file($partial) && filesize($partial)>0,'mysqldump exit 0 and nonempty backup');
    $text=file_get_contents($partial);
    $tableCount=preg_match_all('/^CREATE TABLE /m',$text);
    dr_assert($tableCount===78 && str_contains($text,'Dump completed on') && !preg_match('/^(?:USE |CREATE DATABASE|DROP DATABASE)/mi',$text),'dump complete; 78 tables; no database switch');
    unset($text);
    dr_assert(!is_file($file) && rename($partial,$file),'unique backup finalized without overwrite');
    $sha=hash_file('sha256',$file); $side=fopen($file.'.sha256','x'); if(!$side) throw new RuntimeException('Cannot reserve SHA256 sidecar');
    $line=strtoupper($sha).'  '.basename($file).PHP_EOL;
    dr_assert(fwrite($side,$line)===strlen($line),'SHA256 sidecar written'); fclose($side);
    dr_assert(hash_file('sha256',$file)===$sha && str_starts_with(file_get_contents($file.'.sha256'),strtoupper($sha)),'SHA256 reread matches');
    dr_assert(dr_snapshot($app)===dr_read('before'),'all databases unchanged by backup');
    $result=['passed'=>true,'file'=>$file,'bytes'=>filesize($file),'sha256'=>$sha,'dump_exit'=>$exit,'table_definitions'=>$tableCount,'previous_retained'=>$previous];
    dr_record('backup',$result); echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
