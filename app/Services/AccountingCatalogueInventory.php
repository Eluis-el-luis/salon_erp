<?php

namespace App\Services;

use DomainException;

/** Pure inventory of observed metadata and ledger amounts, not a financial classification. */
final class AccountingCatalogueInventory
{
    public function describe(array $accounts, array $details, array $dependencies = []): array
    {
        $rows=[]; $ids=[];
        foreach($accounts as $a){
            $id=(int)$a['id'];if($id<1||isset($rows[$id]))throw new DomainException('inventory.duplicate_account');
            $result=(bool)$a['permite_movimiento']&&preg_match('/^[4-7]\./D',$a['codigo'])===1?match($a['codigo'][0]){'4'=>'ingresos','5'=>'costos','6'=>'gastos_operativos','7'=>'otros_egresos'}:null;
            $balance=!(bool)$a['permite_movimiento']?null:match(substr($a['codigo'],0,1)){'1'=>'activo','2'=>'pasivo','3'=>'patrimonio','4'=>'resultado:ingresos','5'=>'resultado:costos','6'=>'resultado:gastos',default=>'resultado:otros'};
            $rows[$id]=['account'=>$a,'dependencies'=>$dependencies[$id]??[], 'children'=>[],
                'legacy'=>['result'=>$result,'dashboard'=>$result,'balance'=>$balance],
                'activity'=>['count'=>0,'debit_cents'=>0,'credit_cents'=>0,'first'=>null,'last'=>null,'states'=>[],
                    'origins'=>[],'entry_types'=>[],'periods'=>[],'months'=>[],'currencies'=>[],'centers'=>[],
                    'null_origin'=>0,'closing'=>0,'reverse_marker'=>0,'with_reference'=>0,'date_outside_period'=>0,'negative_lines'=>0],
                'legacy_universes'=>['result'=>['count'=>0,'debit_cents'=>0,'credit_cents'=>0],
                    'balance'=>['count'=>0,'debit_cents'=>0,'credit_cents'=>0]],
                'economic_review'=>['group'=>'PENDIENTE','subgroup'=>'PENDIENTE','corrector'=>'PENDIENTE DE CONFIRMACIÓN',
                    'current_noncurrent'=>'PENDIENTE: aplicabilidad por confirmar','status'=>'NO REVISADA'], 'observations'=>[]];
        }
        foreach($rows as $id=>&$r){
            $seen=[$id=>true];$parent=$r['account']['cuenta_padre_id'];$depth=1;
            while($parent!==null){
                if(!isset($rows[$parent]))throw new DomainException('inventory.missing_parent');
                if(isset($seen[$parent]))throw new DomainException('inventory.hierarchy_cycle');
                $seen[$parent]=true;$depth++;$parent=$rows[$parent]['account']['cuenta_padre_id'];
            }
            $r['computed_level']=$depth;
            if($depth!==(int)$r['account']['nivel'])$r['observations'][]='Nivel almacenado difiere de profundidad observada';
        }unset($r);
        foreach($rows as $id=>$r){$parent=$r['account']['cuenta_padre_id'];if($parent!==null)$rows[$parent]['children'][]=$id;}
        foreach($details as $d){
            $id=(int)$d['account_id'];$line=(int)$d['detail_id'];
            if(!isset($rows[$id])||isset($ids[$line]))throw new DomainException('inventory.orphan_or_duplicate_detail');$ids[$line]=true;
            $r=&$rows[$id];$a=&$r['activity'];$debit=$this->cents($d['debit']);$credit=$this->cents($d['credit']);
            $this->accumulate($a,$debit,$credit);
            $a['first']=$a['first']===null?$d['date']:min($a['first'],$d['date']);$a['last']=max($a['last']??$d['date'],$d['date']);
            foreach(['states'=>$d['state'],'origins'=>$d['origin']===null?'[SQL NULL]':$d['origin'],'entry_types'=>$d['entry_type'],
                'centers'=>$d['center_id']===null?'[SQL NULL]':(string)$d['center_id']]as $field=>$key)$a[$field][$key]=($a[$field][$key]??0)+1;
            foreach(['periods'=>(string)$d['period_id'],'months'=>substr($d['date'],0,7),'currencies'=>(string)$d['currency_id']]as $field=>$key){
                if(!isset($a[$field][$key]))$a[$field][$key]=['count'=>0,'debit_cents'=>0,'credit_cents'=>0];
                $this->accumulate($a[$field][$key],$debit,$credit);
            }
            $a['null_origin']+=(int)($d['origin']===null);$a['closing']+=(int)($d['origin']==='cierre_fiscal');
            $a['reverse_marker']+=(int)(bool)$d['reverse_marker'];$a['with_reference']+=(int)(bool)$d['has_reference'];
            $a['date_outside_period']+=(int)($d['period_start']===null||$d['date']<$d['period_start']||$d['date']>$d['period_end']);
            $a['negative_lines']+=(int)($debit<0||$credit<0);
            if($r['legacy']['result']!==null&&$d['origin']!==null&&$d['origin']!=='cierre_fiscal')$this->accumulate($r['legacy_universes']['result'],$debit,$credit);
            if($r['legacy']['balance']!==null)$this->accumulate($r['legacy_universes']['balance'],$debit,$credit);
            unset($a,$r);
        }
        foreach($rows as &$r){
            $a=&$r['activity'];$a['debit_minus_credit_cents']=$this->add($a['debit_cents'],-$a['credit_cents']);
            $a['nature_signed_cents']=match($r['account']['naturaleza']){'deudora'=>$a['debit_minus_credit_cents'],'acreedora'=>-$a['debit_minus_credit_cents'],default=>throw new DomainException('inventory.unknown_nature')};
            foreach($a['currencies'] as &$currency)$currency['nature_signed_cents']=$r['account']['naturaleza']==='deudora'
                ?$this->add($currency['debit_cents'],-$currency['credit_cents']):$this->add($currency['credit_cents'],-$currency['debit_cents']);unset($currency);
            if($a['count']&&!$r['account']['activa'])$r['observations'][]='Inactiva con historia';
            if($a['count']&&!$r['account']['permite_movimiento'])$r['observations'][]='No imputable actualmente con movimientos propios omitidos por reportes';
            if($a['count']&&$a['debit_minus_credit_cents']===0)$r['observations'][]='Actividad con neto cero';
            if($a['count']&&$a['nature_signed_cents']<0)$r['observations'][]='Saldo contrario a naturaleza actual: no confirma correctora';
            if($a['null_origin'])$r['observations'][]='Origen NULL excluido de resultados/dashboard heredados';
            if($a['date_outside_period'])$r['observations'][]='Fecha fuera del período asociado';
            if($a['negative_lines'])$r['observations'][]='Detalle histórico con importe negativo; no corregido';
            if(!preg_match('/^[1-7]\./D',$r['account']['codigo']))$r['observations'][]='Código fuera de convención con punto: revisar cobertura heredada';
            $usual=['activo'=>'deudora','pasivo'=>'acreedora','patrimonio'=>'acreedora','ingreso'=>'acreedora','gasto'=>'deudora','costo'=>'deudora'];
            if(($usual[$r['account']['tipo']]??null)!==$r['account']['naturaleza'])$r['observations'][]='Naturaleza opuesta a convención del tipo: posible correctora, no defecto confirmado';
            $legacyType=['1'=>'activo','2'=>'pasivo','3'=>'patrimonio','4'=>'ingreso','5'=>'costo','6'=>'gasto','7'=>'gasto'][substr($r['account']['codigo'],0,1)]??null;
            if($legacyType!==$r['account']['tipo'])$r['observations'][]='Tipo actual y agrupación por código divergen';
            $r['question']='Confirmar grupo/subgrupo, correctora y base de signo; aplicabilidad corriente/no corriente y cobertura documental. '.implode('; ',$r['observations']);
            foreach(['states','origins','entry_types','periods','months','currencies','centers']as $field)ksort($a[$field]);
            unset($a);
        }unset($r);
        ksort($rows); // Detail identities remain only in private extraction, not the public matrix.
        return ['contract'=>'accounting-catalogue-inventory-v1','source'=>'observed_test_only','accounts'=>$rows,
            'coverage'=>['accounts'=>count($rows),'details'=>count($details),'unique_details'=>count($ids),'all_states'=>true,
                'all_dates'=>true,'parents_are_not_rollups'=>true],
            'business_authority'=>false,'publishable'=>false,'issued_statement'=>false];
    }

    private function accumulate(array &$value,int $d,int $h): void { $value['count']++;$value['debit_cents']=$this->add($value['debit_cents'],$d);$value['credit_cents']=$this->add($value['credit_cents'],$h); }
    private function add(int $a,int $b): int { $r=$a+$b;if(!is_int($r))throw new DomainException('inventory.amount_overflow');return $r; }
    private function cents(mixed $v): int { if(!is_string($v)||!preg_match('/^-?(?:0|[1-9][0-9]{0,12})\.[0-9]{2}$/D',$v))throw new DomainException('inventory.decimal');return (int)str_replace('.','',$v); }
}
