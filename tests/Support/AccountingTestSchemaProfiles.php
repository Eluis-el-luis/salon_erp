<?php

namespace Tests\Support;

use DomainException;

/** Fixed test schema evidence; no discovery-based approval, SQL writers or financial authority. */
final class AccountingTestSchemaProfiles
{
    public const HISTORICAL = 'AC05_ORIGINAL_89_V1';
    public const CURRENT = 'AC05_SYNTHETIC_91_V1';
    public const MANIFEST_SHA256 = '0c139a1fc6627958f3827c9062e76bf6a3685b67eb731e379c78b872202a4dfc';

    public static function manifest(): array
    {
        $file=__DIR__.'/fixtures/ac05-schema-v1.json';
        if(hash_file('sha256',$file)!==self::MANIFEST_SHA256)throw new DomainException('schema.manifest_changed');
        $m=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if($m['contract']!=='ac05-test-schema-manifest-v1'||count($m['original_tables'])!==89
            ||array_keys($m['synthetic_tables'])!==['test_financial_presentation_draft_audits','test_financial_presentation_drafts']||count($m['migrations'])!==82)throw new DomainException('schema.manifest_contract');
        return $m;
    }

    /** Pure fixture for tests and explicit expectations; never opens SQL. */
    public static function expectedMetadata(string $profile): array
    {
        $m=self::manifest(); $tables=self::tables($profile,$m);
        return ['identity'=>$m['identity'],'grants'=>$m['grants'],'migrations'=>$m['migrations'],
            'tables'=>array_map(fn($t)=>$t['ddl'],$tables)];
    }

    public static function assertSchema(string $profile,array $actual): void
    {
        $m=self::manifest(); $expected=self::tables($profile,$m);
        $identity=['database_name'=>'salon_erp_test','account_name'=>'salon_erp_tester@localhost','server_port'=>3306,'roles'=>'NONE','version'=>'8.4.3'];
        $i=$actual['identity']??[];ksort($i);ksort($identity);
        if($i!==$identity)throw new DomainException('schema.identity');
        $grants=$actual['grants']??[];sort($grants);$approved=$m['grants'];sort($approved);
        if($grants!==$approved)throw new DomainException('schema.privileges');
        $migrations=$actual['migrations']??[];sort($migrations);
        if($migrations!==$m['migrations'])throw new DomainException('schema.migrations');
        $tables=$actual['tables']??[];$names=array_keys($tables);sort($names);$keys=array_keys($expected);sort($keys);
        if($names!==$keys)throw new DomainException('schema.tables');
        foreach($tables as $name=>$ddl){
            if(!is_string($ddl))throw new DomainException('schema.ddl_type');
            $ddl=self::normalize($ddl);$lines=explode("\n",$ddl);
            $columns=array_values(array_filter($lines,fn($line)=>preg_match('/^\s+`/',$line)));
            $constraints=array_values(array_filter($lines,fn($line)=>preg_match('/^\s+(PRIMARY|UNIQUE|KEY|CONSTRAINT)\b/',$line)));
            if(hash('sha256',implode("\n",$columns))!==$expected[$name]['columns_sha256'])throw new DomainException('schema.columns');
            if(hash('sha256',implode("\n",$constraints))!==$expected[$name]['constraints_sha256'])throw new DomainException('schema.constraints');
            if(hash('sha256',$ddl)!==$expected[$name]['sha256'])throw new DomainException('schema.ddl');
        }
    }

    public static function assertPreserved(array $before,array $after): void
    {
        if(($before['profile']??null)!==self::CURRENT||($after['profile']??null)!==self::CURRENT)throw new DomainException('isolation.profile');
        $expected=array_keys(self::expectedMetadata(self::CURRENT)['tables']);sort($expected);
        foreach([$before,$after] as $state){
            $schemas=array_keys($state['snapshot']??[]);sort($schemas);
            if($schemas!==['salon_erp','salon_erp_bootstrap_check','salon_erp_test'])throw new DomainException('isolation.baseline_schema');
            $names=array_keys($state['snapshot']['salon_erp_test']);sort($names);
            if($names!==$expected||($state['history']['drafts']??null)!==42||($state['history']['audits']??null)!==48
                ||($state['history']['chains_valid']??null)!==true||($state['history']['business_authority']??null)!==false)throw new DomainException('isolation.baseline_contract');
            foreach($state['snapshot'] as $tables)foreach($tables as $table){
                if(!is_array($table['rows']??null)||!is_string($table['ddl']??null)||!preg_match('/^[a-f0-9]{64}$/D',$table['ddl']))throw new DomainException('isolation.baseline_evidence');
                foreach($table['rows'] as $hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new DomainException('isolation.row_hash');
            }
        }
        // No normalization here: even AUTO_INCREMENT and every original row hash must match.
        if($before!==$after)throw new DomainException('isolation.changed');
    }

    public static function verifyCurrentBackup(): array
    {
        $root=dirname(__DIR__,2);$b=json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2r-backup.json'),true,512,JSON_THROW_ON_ERROR);
        if(!str_starts_with($b['file'],'E:/salon-erp-backups/salon_erp_test_AC05B3B2R_')||$b['tables']!==91||$b['migrations']!==82||$b['rows']!==1963||!$b['source_unchanged']
            ||filesize($b['file'])!==$b['bytes']||hash_file('sha256',$b['file'])!==$b['sha256']
            ||!str_starts_with(file_get_contents($b['file'].'.sha256'),$b['sha256'].'  '))throw new DomainException('backup.current_invalid');
        return $b;
    }

    /** Selection for reader runners only. Historical defaults never silently accept new tables. */
    public static function readerProfile(string $mode,?string $requested): string
    {
        $profile=$requested??self::HISTORICAL;
        if(!in_array($profile,[self::HISTORICAL,self::CURRENT],true))throw new DomainException('schema.unknown_profile');
        if($profile===self::CURRENT&&!in_array($mode,['reports-preflight','closing-preflight','extract-preflight','report-demo-preflight','report-demo-http'],true))throw new DomainException('schema.not_a_current_reader');
        return $profile;
    }

    public static function normalize(string $ddl): string { return preg_replace('/^(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= )/m','$1',$ddl); }
    private static function tables(string $profile,array $m): array
    {
        return match($profile){self::HISTORICAL=>$m['original_tables'],self::CURRENT=>$m['original_tables']+$m['synthetic_tables'],default=>throw new DomainException('schema.unknown_profile')};
    }
}
