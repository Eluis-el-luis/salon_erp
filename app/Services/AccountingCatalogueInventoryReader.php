<?php

namespace App\Services;

use DomainException;
use PDO;
use Tests\Support\{AccountingSchemaInspection,AccountingTestSchemaProfiles};

/** CLI-only, one fixed restricted connection. No Eloquent hooks, posting services or row locks. */
final class AccountingCatalogueInventoryReader
{
    public function read(): array
    {
        SyntheticFinancialPresentationDraftReader::boundary(); // Existing read-only CLI bootstrap; no writer authority.
        $pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=salon_erp_test;charset=utf8mb4','salon_erp_tester',$_ENV['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $pdo->exec("SET SESSION time_zone='+00:00'");$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET SESSION TRANSACTION READ ONLY');
        AccountingTestSchemaProfiles::assertSchema(AccountingTestSchemaProfiles::CURRENT,AccountingSchemaInspection::metadata($pdo));
        foreach(['salon_erp','salon_erp_bootstrap_check']as $schema){
            try{$pdo->query("SELECT 1 FROM `$schema`.users LIMIT 0");throw new DomainException('inventory.foreign_database_accessible');}
            catch(\PDOException $e){if(($e->errorInfo[1]??0)!==1142)throw $e;}
        }
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try{
            if(!$pdo->query("SELECT id FROM users WHERE is_active=1 AND role IN ('alvaro','contador') ORDER BY id LIMIT 1")->fetchColumn())throw new DomainException('inventory.actor_unavailable');
            $accounts=$pdo->query('SELECT * FROM cuentas_contables ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $details=$pdo->query("SELECT d.id detail_id,d.asiento_id entry_id,d.cuenta_id account_id,d.debe debit,d.haber credit,
                d.moneda_id currency_id,d.centro_costo_id center_id,a.fecha date,a.periodo_id period_id,a.estado state,
                a.modulo_origen origin,a.tipo_asiento entry_type,(a.referencia_id IS NOT NULL) has_reference,
                (a.numero_asiento LIKE 'REV-%') reverse_marker,p.fecha_inicio period_start,p.fecha_fin period_end,p.estado period_state
                FROM detalle_asientos d JOIN asientos_contables a ON a.id=d.asiento_id
                LEFT JOIN periodos_contables p ON p.id=a.periodo_id ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
            $count=(int)$pdo->query('SELECT COUNT(*) FROM detalle_asientos')->fetchColumn();
            if(count($details)!==$count)throw new DomainException('inventory.detail_coverage');
            // Count dependencies independently: no one-to-many join can multiply ledger lines.
            $deps=[];
            $references=$pdo->query("SELECT DISTINCT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
                AND REFERENCED_TABLE_NAME='cuentas_contables' AND REFERENCED_COLUMN_NAME='id' ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
            foreach(['cuentas_bancarias'=>'cuenta_contable_id','tipos_gasto'=>'cuenta_contable_id','salary_structure_details'=>'cuenta_contable_id',
                'inventory_openings'=>'counterpart_account_id','accounting_account_bindings'=>'account_id']as $table=>$column){
                if(!array_filter($references,fn($r)=>$r['TABLE_NAME']===$table&&$r['COLUMN_NAME']===$column))$references[]=['TABLE_NAME'=>$table,'COLUMN_NAME'=>$column];
            }
            foreach($references as $ref){
                $table=$ref['TABLE_NAME'];$col=$ref['COLUMN_NAME'];
                if(!preg_match('/^[a-z_0-9]+$/D',$table)||!preg_match('/^[a-z_0-9]+$/D',$col))throw new DomainException('inventory.identifier');
                foreach($pdo->query("SELECT `$col` account_id,COUNT(*) n FROM `$table` WHERE `$col` IS NOT NULL GROUP BY `$col`")->fetchAll(PDO::FETCH_ASSOC)as $d)$deps[(int)$d['account_id']][$table.'.'.$col]=(int)$d['n'];
            }
            $result=(new AccountingCatalogueInventory)->describe($accounts,$details,$deps);
            $result['snapshot']=['profile'=>AccountingTestSchemaProfiles::CURRENT,'isolation'=>'REPEATABLE READ','read_only'=>true,'owned'=>true,
                'captured_at_utc'=>$pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(),'connection_id'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()];
            $result['controls']=['detail_count_sql'=>$count,'account_count_sql'=>(int)$pdo->query('SELECT COUNT(*) FROM cuentas_contables')->fetchColumn(),
                'entry_count_sql'=>(int)$pdo->query('SELECT COUNT(*) FROM asientos_contables')->fetchColumn(),
                'entry_without_details_count'=>(int)$pdo->query('SELECT COUNT(*) FROM asientos_contables a WHERE NOT EXISTS(SELECT 1 FROM detalle_asientos d WHERE d.asiento_id=a.id)')->fetchColumn(),
                'debit_sql'=>$pdo->query('SELECT COALESCE(SUM(debe),0.00) FROM detalle_asientos')->fetchColumn(),
                'credit_sql'=>$pdo->query('SELECT COALESCE(SUM(haber),0.00) FROM detalle_asientos')->fetchColumn()];
            $result['periods']=$pdo->query('SELECT id,fecha_inicio,fecha_fin,estado FROM periodos_contables ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $result['currencies']=$pdo->query('SELECT id,codigo,es_base FROM monedas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $result['private_detail_evidence']=$details; // Runner must keep this outside versioned documentation.
            $pdo->commit();return $result;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{putenv('SALON_TEST_DB_RESET_ALLOWED=0');}
    }
}
