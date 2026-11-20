<?php

// One-time compilation from verified, accepted B2 evidence. Never introspects an arbitrary live database.
try {
    require dirname(__DIR__).'/bootstrap.php';
    if (PHP_SAPI!=='cli'||PHP_VERSION!=='8.3.30'||SALON_TEST_DATABASE_RESET_ALLOWED||realpath(getcwd())!==realpath(dirname(__DIR__,2))) throw new RuntimeException('Profile compilation boundary.');
    $root=dirname(__DIR__,2);
    $source=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2r-verified-source.json'),true,512,JSON_THROW_ON_ERROR);
    if($source['approved_baseline_sha256']!==hash_file('sha256',$root.'/storage/app/private/ac05b3b2-isolation-after.json'))throw new RuntimeException('Evidence changed.');
    $accepted=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-isolation-after.json'),true,512,JSON_THROW_ON_ERROR);
    $before=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-isolation-before.json'),true,512,JSON_THROW_ON_ERROR);
    $backup=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2r-backup.json'),true,512,JSON_THROW_ON_ERROR);
    if($backup['tables']!==91||$backup['migrations']!==82||$backup['rows']!==1963||!$backup['source_unchanged']||hash_file('sha256',$backup['file'])!==$backup['sha256'])throw new RuntimeException('Current backup required.');
    $original=[];$synthetic=[];
    foreach($source['metadata']['tables'] as $name=>$raw){
        if(hash('sha256',$raw)!==$accepted['salon_erp_test'][$name]['ddl'])throw new RuntimeException('Unverified DDL.');
        $ddl=preg_replace('/^(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= )/m','$1',$raw);
        $lines=explode("\n",$ddl);
        $columns=array_values(array_filter($lines,fn($line)=>preg_match('/^\s+`/',$line)));
        $constraints=array_values(array_filter($lines,fn($line)=>preg_match('/^\s+(PRIMARY|UNIQUE|KEY|CONSTRAINT)\b/',$line)));
        $entry=['ddl'=>$ddl,'sha256'=>hash('sha256',$ddl),'columns_sha256'=>hash('sha256',implode("\n",$columns)),
            'constraints_sha256'=>hash('sha256',implode("\n",$constraints))];
        if(isset($before['salon_erp_test'][$name]))$original[$name]=$entry;else $synthetic[$name]=$entry;
    }
    if(count($original)!==89||array_keys($synthetic)!==['test_financial_presentation_draft_audits','test_financial_presentation_drafts']||count($source['metadata']['migrations'])!==82)throw new RuntimeException('Profile coverage differs.');
    $result=['contract'=>'ac05-test-schema-manifest-v1','approved_B2_baseline_sha256'=>$source['approved_baseline_sha256'],
        'original_tables'=>$original,'synthetic_tables'=>$synthetic,'migrations'=>$source['metadata']['migrations'],'identity'=>$source['metadata']['identity'],'grants'=>$source['metadata']['grants']];
    $dir=__DIR__.'/fixtures';if(!is_dir($dir)&&!mkdir($dir,0755))throw new RuntimeException('Fixture directory unavailable.');
    $path=$dir.'/ac05-schema-v1.json';$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Profile already exists; do not overwrite.');
    fwrite($f,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");fclose($f);
    echo json_encode(['manifest_sha256'=>hash_file('sha256',$path),'original_tables'=>count($original),'synthetic_tables'=>count($synthetic),'SQL_writes'=>0]),PHP_EOL;
} finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
