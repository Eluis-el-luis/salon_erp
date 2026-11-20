<?php

namespace Tests\Unit;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AccountingTestSchemaProfiles as Profiles;

/** Structural mutations only in memory. No bootstrap, SQL, fixtures with INSERT or DDL. */
final class AccountingTestSchemaProfilesTest extends TestCase
{
    public function test_historical_and_current_are_distinct_exact_profiles(): void
    {
        $old=Profiles::expectedMetadata(Profiles::HISTORICAL);$current=Profiles::expectedMetadata(Profiles::CURRENT);
        self::assertCount(89,$old['tables']);self::assertCount(91,$current['tables']);self::assertCount(82,$old['migrations']);
        Profiles::assertSchema(Profiles::HISTORICAL,$old);Profiles::assertSchema(Profiles::CURRENT,$current);
        self::assertSame(['test_financial_presentation_draft_audits','test_financial_presentation_drafts'],array_keys(array_diff_key($current['tables'],$old['tables'])));
        $this->expectException(DomainException::class);Profiles::assertSchema(Profiles::HISTORICAL,$current);
    }

    public static function mutations(): array
    {
        return array_map(fn($v)=>[$v],['unknown_table','substituted_table','column','type','index','foreign_key','check',
            'migration','database','sql_user','port','roles','grants','synthetic_extra','missing_table','synthetic_column','engine']);
    }
    #[DataProvider('mutations')]
    public function test_unauthorized_metadata_rejected_without_mutating_sql(string $case): void
    {
        $m=Profiles::expectedMetadata(Profiles::CURRENT);$original=$m;
        switch($case){
            case 'unknown_table': $m['tables']['unexpected']=$m['tables']['users'];break;
            case 'substituted_table': $m['tables']['unknown']=$m['tables']['users'];unset($m['tables']['users']);break;
            case 'column': $m['tables']['users']=str_replace(" (\n"," (\n  `unauthorized` int DEFAULT NULL,\n",$m['tables']['users']);break;
            case 'type': $m['tables']['users']=str_replace('`id` bigint unsigned','`id` int unsigned',$m['tables']['users']);break;
            case 'index': $m['tables']['users']=preg_replace('/^  PRIMARY KEY.*\n/m','',$m['tables']['users']);break;
            case 'foreign_key': $m['tables']['test_financial_presentation_drafts']=str_replace('REFERENCES `users`','REFERENCES `roles`',$m['tables']['test_financial_presentation_drafts']);break;
            case 'check': $m['tables']['test_financial_presentation_drafts']=str_replace('simulation','business',$m['tables']['test_financial_presentation_drafts']);break;
            case 'migration': $m['migrations'][]='2099_01_01_000001_unauthorized';break;
            case 'database': $m['identity']['database_name']='salon_erp';break;
            case 'sql_user': $m['identity']['account_name']='root@localhost';break;
            case 'port': $m['identity']['server_port']=3307;break;
            case 'roles': $m['identity']['roles']='elevated_role';break;
            case 'grants': $m['grants'][]='GRANT PROCESS ON *.* TO `salon_erp_tester`@`localhost`';break;
            case 'synthetic_extra': $m['tables']['test_financial_presentation_extra']=$m['tables']['test_financial_presentation_drafts'];break;
            case 'missing_table': unset($m['tables']['detalle_asientos']);break;
            case 'synthetic_column': $m['tables']['test_financial_presentation_drafts']=str_replace('`revision` int unsigned','`revision` bigint unsigned',$m['tables']['test_financial_presentation_drafts']);break;
            case 'engine': $m['tables']['users']=str_replace('ENGINE=InnoDB','ENGINE=MyISAM',$m['tables']['users']);break;
        }
        self::assertNotSame($original,$m,'Mutation fixture must really change metadata.');
        $this->expectException(DomainException::class);Profiles::assertSchema(Profiles::CURRENT,$m);
    }

    private function state(): array
    {
        $tables=[];foreach(Profiles::expectedMetadata(Profiles::CURRENT)['tables'] as $name=>$ddl)$tables[$name]=['rows'=>[hash('sha256','SYNTHETIC-ROW-'.$name)],'ddl'=>hash('sha256',$ddl)];
        return ['profile'=>Profiles::CURRENT,'snapshot'=>['salon_erp_test'=>$tables,'salon_erp'=>['users'=>$tables['users']],'salon_erp_bootstrap_check'=>['users'=>$tables['users']]],
            'history'=>['drafts'=>42,'audits'=>48,'chains_valid'=>true,'prepared_131_hash'=>hash('sha256','SYNTHETIC-131'),'business_authority'=>false]];
    }

    public function test_preservation_accepts_equal_state_without_weakening_auto_increment(): void
    {
        $state=$this->state();Profiles::assertPreserved($state,$state);self::assertTrue(true);
        $changed=$state;$changed['snapshot']['salon_erp_test']['users']['ddl']=hash('sha256','SYNTHETIC-AUTO-INCREMENT-CHANGED');
        $this->expectException(DomainException::class);Profiles::assertPreserved($state,$changed);
    }
    public static function isolationMutations(): array { return [['profile'],['row'],['original'],['draft'],['audit'],['foreign'],['new_row'],['missing_baseline']]; }
    #[DataProvider('isolationMutations')]
    public function test_row_and_baseline_mutations_are_rejected(string $case): void
    {
        $before=$this->state();$after=$before;
        switch($case){
            case 'profile':$before['profile']=Profiles::HISTORICAL;break;
            case 'row':$after['snapshot']['salon_erp_test']['users']['rows'][0]=hash('sha256','changed');break;
            case 'original':$after['snapshot']['salon_erp_test']['asientos_contables']['ddl']=hash('sha256','altered');break;
            case 'draft':$after['snapshot']['salon_erp_test']['test_financial_presentation_drafts']['rows'][0]=hash('sha256','changed');break;
            case 'audit':$after['snapshot']['salon_erp_test']['test_financial_presentation_draft_audits']['rows'][0]=hash('sha256','changed');break;
            case 'foreign':$after['snapshot']['salon_erp']['users']['rows']=[];break;
            case 'new_row':$after['snapshot']['salon_erp_test']['detalle_asientos']['rows'][]=hash('sha256','insert');break;
            case 'missing_baseline':unset($before['snapshot']);break;
        }
        $this->expectException(DomainException::class);Profiles::assertPreserved($before,$after);
    }

    public function test_explicit_current_reader_does_not_enable_installation_or_writers(): void
    {
        self::assertSame(Profiles::HISTORICAL,Profiles::readerProfile('reports-preflight',null));
        self::assertSame(Profiles::CURRENT,Profiles::readerProfile('report-demo-http',Profiles::CURRENT));
        foreach(['install','refund-install','pilot-core','reports-bank','reports-core','extract-race','closing-core'] as $mode){
            try{Profiles::readerProfile($mode,Profiles::CURRENT);self::fail('Current profile enabled writer/installer.');}
            catch(DomainException $e){self::assertSame('schema.not_a_current_reader',$e->getMessage());}
        }
    }

    public function test_normalization_only_ignores_table_counter_and_preserves_column_text(): void
    {
        $ddl="CREATE TABLE `fixture` (\n  `label` varchar(100) DEFAULT 'hello AUTO_INCREMENT=123'\n) ENGINE=InnoDB AUTO_INCREMENT=456 DEFAULT CHARSET=utf8mb4";
        $normalized=Profiles::normalize($ddl);
        self::assertStringContainsString("'hello AUTO_INCREMENT=123'",$normalized);
        self::assertStringNotContainsString('ENGINE=InnoDB AUTO_INCREMENT=456',$normalized);
    }
}
