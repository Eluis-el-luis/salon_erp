<?php

namespace App\Services;

use App\Support\ReportDemoBoundary;

final class ReportPresentationDemo
{
    public function compare(array $input): array
    {
        ReportDemoBoundary::actor();
        $rules=['source'=>'required|in:offline,mysql','case'=>'required|in:overview,closing,incomplete','report'=>'required|in:result,balance',
            'month'=>'required|date_format:Y-m','until'=>'required|date_format:Y-m-d','states'=>'required|in:all,posted',
            'null_origin'=>'required|in:include,exclude','period'=>'required|in:all,fixture1'];
        if(array_diff(array_keys($input),array_keys($rules)))throw \Illuminate\Validation\ValidationException::withMessages(['demo'=>'Parámetros no admitidos.']);
        $v=validator($input,$rules)->validate();
        if(($v['report']==='balance'&&$v['period']!=='all')||($v['source']==='mysql'&&$v['period']!=='all'))throw new \DomainException('Filtro de período incompatible.');
        if($v['source']==='offline'){
            if($v['month']!=='2030-10'||$v['until']!=='2030-10-31')throw new \DomainException('El fixture offline usa octubre2030, sin cambiar sus fechas.');
            $r=\Tests\Support\ReportPresentationFixtures::compare($v['case'],$v['report'],$v['states'],$v['null_origin'],$v['period']);
        }else{
            $from=$v['month'].'-01';$until=$v['report']==='result'?(new \DateTimeImmutable($from))->format('Y-m-t'):$v['until'];
            if($v['report']==='result'&&$v['until']!==$until)throw new \DomainException('Resultados requiere mes completo coherente.');
            // Deliberately incomplete: never derive sample classifications from business account names/balances.
            $classification=['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'AC05B3A-MYSQL-UNCLASSIFIED-1','assignments'=>[]];
            $p=['report'=>$v['report'],'from'=>$v['report']==='result'?$from:null,'until'=>$until,
                'states'=>$v['states']==='all'?['borrador','contabilizado','anulado']:['contabilizado'],
                'null_origin'=>$v['null_origin'],'account_scope'=>'all','center_scope'=>'all'];
            $r=app(AccountingReportExtractor::class)->compare($classification,$p);$r['data_origin']='MYSQL TEST — datos históricos, sin clasificación propuesta aprobada';
            if(!$r['snapshot']['read_only_transaction'])throw new \DomainException('Se requiere snapshot propietario READ ONLY.');
        }
        foreach($r['warnings'] as $w)if($w['kind']==='unexplained_membership_difference')throw new \DomainException('Diferencia no explicada; comparación rechazada.');
        return $r;
    }
}
