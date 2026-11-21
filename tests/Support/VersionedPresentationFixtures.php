<?php

namespace Tests\Support;

/** New fictional contracts and original-detail projections, never SQL documents. */
final class VersionedPresentationFixtures
{
    public static function contract(): array
    {
        $c = FinancialPresentationContractFixtures::content();
        $c['family_uuid'] = '00000000-0000-4000-8000-000000000003';
        $c['label'] = 'SYNTHETIC AC05B3B3, no economic approval';
        $c['groups'][] = ['key'=>'contra_income','parent'=>'income','basis'=>'credit_minus_debit','order'=>10,'label'=>'SYNTHETIC contra income'];
        $map = [701=>'asset',702=>'equity',703=>'income',704=>'contra_income',705=>'cost',706=>'operating_expense',
            707=>'asset',708=>'operating_expense',709=>'operating_expense',710=>'other_expense',711=>'liability'];
        $c['assignments'] = [];
        foreach ($map as $id=>$group) $c['assignments'][] = ['account_id'=>$id,'group_key'=>$group,'order'=>$id,'corrector'=>in_array($id,[704,707],true)];
        return $c;
    }

    public static function fixture(bool $closing = false): array
    {
        $rows = []; $detail = 1; $entry = 1;
        $add = function (array $lines, string $origin = 'manual', ?int $reference = null) use (&$rows,&$detail,&$entry) {
            foreach ($lines as [$id,$d,$h]) $rows[] = ['detail_id'=>$detail++,'entry_id'=>$entry,'account_id'=>$id,
                'period_id'=>1,'date'=>'2030-10-15','state'=>'contabilizado','origin'=>$origin,'debit'=>$d,'credit'=>$h,
                'currency_id'=>1,'center_id'=>null,'reference_id'=>$reference,'entry_number'=>'SYNTHETIC-'.$entry,
                'current_account'=>['code'=>'ALTERNATIVE-'.$id,'name'=>'SYNTHETIC account '.$id,'active'=>$id!==706,
                    'parent_id'=>$id===709?708:null,'postable'=>true]];
            $entry++;
        };
        if ($closing) {
            $add([[701,'90.00','0.00'],[704,'10.00','0.00'],[703,'0.00','100.00']]);
            $add([[703,'100.00','0.00'],[704,'0.00','10.00'],[702,'0.00','90.00']],'cierre_fiscal');
        } else {
            $add([[701,'1000.00','0.00'],[702,'0.00','1000.00']]);
            $add([[701,'120.00','0.00'],[703,'0.00','120.00']]);
            $add([[704,'10.00','0.00'],[701,'0.00','10.00']]);
            $add([[705,'20.00','0.00'],[701,'0.00','20.00']]);
            $add([[706,'30.00','0.00'],[701,'0.00','30.00']]);
            $add([[706,'5.00','0.00'],[707,'0.00','5.00']]);
            $add([[708,'2.00','0.00'],[709,'3.00','0.00'],[701,'0.00','5.00']]);
            $add([[706,'6.00','0.00'],[701,'0.00','6.00']]);
            $add([[701,'6.00','0.00'],[706,'0.00','6.00']],'reversion',8);
            $add([[706,'50.00','0.00'],[701,'0.00','50.00']],'bancos');
            $add([[701,'50.00','0.00'],[706,'0.00','50.00']],'bank_charge_refunds',10);
            $add([[710,'4.00','0.00'],[701,'0.00','4.00']]);
            $add([[701,'7.00','0.00'],[706,'0.00','7.00']]);
            $add([[701,'10.00','0.00'],[711,'0.00','10.00']]);
        }
        return ['id'=>$closing?'SYNTHETIC:AC05B3B3-CLOSING-V1':'SYNTHETIC:AC05B3B3-OVERVIEW-V1',
            'base_currency'=>'NIO','amount_basis'=>'original_ledger_base','details'=>$rows];
    }
}
