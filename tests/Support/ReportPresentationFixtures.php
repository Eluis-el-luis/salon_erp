<?php

namespace Tests\Support;

/** Immutable code fixtures. IDs and labels below do NOT identify MySQL accounts/documents. */
final class ReportPresentationFixtures
{
    public const MAX_ROWS=120;
    public static function compare(string $case,string $report,string $states='all',string $null='include',string $period='all'): array
    {
        if(!in_array($case,['overview','closing','incomplete'],true)||!in_array($report,['result','balance'],true)
            ||!in_array($states,['all','posted'],true)||!in_array($null,['include','exclude'],true)||!in_array($period,['all','fixture1'],true)
            ||($report==='balance'&&$period!=='all'))throw new \DomainException('Incompatible offline selection.');
        $accounts=[
            101=>['ACT-DEMO','Activo alternativo <b>etiqueta literal</b>','asset',true,null],
            102=>['INC-DEMO','Ingreso fuera de prefijo','income',true,null],
            103=>['EXP-DEMO','Gasto alternativo','operating_expense',true,null],
            104=>['CST-DEMO','Costo alternativo','cost',true,null],
            105=>['4.95','Correctora de ingreso','income',true,null],
            106=>['1.95','Contraactivo','asset',true,null],
            107=>['6.95','Gasto inactivo con historia','operating_expense',false,null],
            108=>['5.95','Padre con movimiento propio','cost',true,null],
            109=>['5.95.1','Hijo con movimiento propio','cost',true,108],
            110=>['3.95','Patrimonio del fixture, no apertura autorizada','equity',true,null],
        ];$rows=[];$detail=1;$entry=1;
        $add=function(array $lines,?string $origin='manual',string $state='contabilizado',int $periodId=1,?int $ref=null)use(&$rows,&$detail,&$entry,&$accounts){
            foreach($lines as [$id,$d,$h]){
                [$code,$name,$group,$active,$parent]=$accounts[$id];
                $rows[]=['detail_id'=>$detail++,'entry_id'=>$entry,'account_id'=>$id,'period_id'=>$periodId,'date'=>'2030-10-15',
                    'origin'=>$origin,'state'=>$state,'debit'=>$d,'credit'=>$h,'currency_id'=>1,'center_id'=>null,
                    'entry_number'=>'OFFLINE-'.$entry,'reference_id'=>$ref,'description'=>'Representación offline, no documento SQL',
                    'current_account'=>['code'=>$code,'name'=>$name,'active'=>$active,'parent_id'=>$parent,'postable'=>true]];
            }$entry++;
        };
        if($case==='closing'){
            $accounts=[101=>['1.96','Activo del cierre','asset',true,null],102=>['4.96','Ingreso normal','income',true,null],
                105=>['4.97','Correctora deudora','income',true,null],110=>['3.96','Traslado patrimonial del fixture','equity',true,null]];
            $add([[101,'90.00','0.00'],[105,'10.00','0.00'],[102,'0.00','100.00']]);
            $add([[102,'100.00','0.00'],[105,'0.00','10.00'],[110,'0.00','90.00']],'cierre_fiscal');
        }else{
            $add([[101,'1000.00','0.00'],[110,'0.00','1000.00']]);
            $add([[101,'100.00','0.00'],[102,'0.00','100.00']]);
            $add([[101,'0.00','30.00'],[103,'10.00','0.00'],[104,'20.00','0.00']]);
            $add([[105,'10.00','0.00'],[101,'0.00','10.00']]);
            $add([[106,'0.00','5.00'],[110,'5.00','0.00']]);
            $add([[107,'3.00','0.00'],[101,'0.00','3.00']]);
            $add([[108,'2.00','0.00'],[109,'4.00','0.00'],[101,'0.00','6.00']]);
            $add([[107,'5.00','0.00'],[101,'0.00','5.00']]);
            $add([[107,'0.00','5.00'],[101,'5.00','0.00']],'reversion','contabilizado',1,8);
            $add([[107,'50.00','0.00'],[101,'0.00','50.00']],'bancos');
            $add([[107,'0.00','50.00'],[101,'50.00','0.00']],'bank_charge_refunds','contabilizado',1,10);
            $add([[102,'0.00','7.00'],[101,'7.00','0.00']],null);
            $add([[102,'0.00','8.00'],[101,'8.00','0.00']],'manual','borrador');
            $add([[102,'0.00','9.00'],[101,'9.00','0.00']],'manual','anulado',2);
        }
        self::assertVolume(count($rows));$assignments=$legacyMap=[];
        foreach($accounts as $id=>$a){if($case!=='incomplete'||$id!==103)$assignments[]=['account_id'=>$id,'group'=>$a[2]];}
        $legacyRows=array_values(array_filter($rows,fn($r)=>$report==='balance'||(preg_match('/^[4-7]\./',$r['current_account']['code'])&&$r['origin']!==null&&$r['origin']!=='cierre_fiscal')));
        foreach($legacyRows as $r)$legacyMap[$r['account_id']]=match(substr($r['current_account']['code'],0,1)){
            '1'=>'asset','2'=>'liability','3'=>'equity','4'=>'income','5'=>'cost','6'=>'operating_expense',default=>'other_expense'};
        $p=['report'=>$report,'from'=>$report==='result'?'2030-10-01':null,'until'=>'2030-10-31',
            'states'=>$states==='all'?['borrador','contabilizado','anulado']:['contabilizado'],'null_origin'=>$null,
            'account_scope'=>array_keys($accounts),'center_scope'=>'all'];if($period==='fixture1')$p['period_id']=1;
        $classification=['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B3A-OFFLINE-'.$case.'-1','assignments'=>$assignments];
        $r=app(\App\Services\AccountingPresentationComparison::class)->read($classification,$p,$rows,$legacyRows,$legacyMap,true);
        $r['source_details']=$rows;$r['legacy_reported_global']=[];$r['snapshot']=null;$r['data_origin']='OFFLINE — fixtures de código, NO extraídos de MySQL';
        return $r;
    }
    public static function assertVolume(int $rows): void
    {
        if($rows>self::MAX_ROWS)throw new \DomainException('Demostración offline excedida; no se generó un reporte completo.');
    }
}
