<?php

namespace App\Services;

use DomainException;

/** Pure comparison; all economic signs and aggregation remain in SyntheticAccountingPresentation. */
final class AccountingPresentationComparison
{
    public function read(array $classification, array $p, array $proposedRows, array $legacyRows, array $legacyMap, bool $compare): array
    {
        $engine=app(SyntheticAccountingPresentation::class);
        $assigned=array_column($classification['assignments'],'group','account_id');
        $warnings=[];
        $unmapped=[];
        // Only details selected by the explicit universe are relevant to missing coverage.
        foreach ($proposedRows as $r) {
            if ($r['date']>$p['until'] || ($p['report']==='result' && ($r['date']<$p['from'] || $r['origin']==='cierre_fiscal'))
                || !in_array($r['state'],$p['states'],true) || ($r['origin']===null && $p['null_origin']==='exclude')
                || (isset($p['period_id']) && $p['period_id']!==$r['period_id'])) continue;
            if (!isset($assigned[$r['account_id']]) && ($r['debit']!=='0.00' || $r['credit']!=='0.00')) $unmapped[$r['account_id']]=true;
        }
        $synthetic=null;
        if ($unmapped) $warnings[]=['kind'=>'unclassified_accounts','account_ids'=>array_keys($unmapped)];
        else $synthetic=$engine->read($classification,$proposedRows,$p);
        $legacy=null;
        if ($compare) {
            $assignments=[];foreach($legacyMap as $id=>$group)$assignments[]=['account_id'=>$id,'group'=>$group];
            $legacyP=$p;unset($legacyP['period_id']);$legacyP['states']=['borrador','contabilizado','anulado'];$legacyP['null_origin']='include';
            $legacy=$engine->read(['contract'=>'synthetic-presentation-v1','purpose'=>'simulation','version'=>'legacy-read-adapter-v1','assignments'=>$assignments],$legacyRows,$legacyP);
            if (array_diff(['borrador','contabilizado','anulado'],$p['states'])) $warnings[]=['kind'=>'legacy_includes_all_entry_states'];
            if ($p['report']==='result' && $p['null_origin']==='include') $warnings[]=['kind'=>'legacy_result_excludes_null_origin'];
            if (isset($p['period_id'])) $warnings[]=['kind'=>'legacy_filters_date_not_period_id'];
            if ($p['center_scope']!=='all') $warnings[]=['kind'=>'legacy_does_not_filter_centers'];
            if ($p['account_scope']!=='all') $warnings[]=['kind'=>'comparison_is_explicit_account_subset','global_report_totals_are_separate'=>true];
        }
        $groups=$accounts=$details=[];
        if ($compare && $synthetic) {
            foreach($synthetic['groups_cents'] as $group=>$amount) $groups[$group]=$this->delta($amount,$legacy['groups_cents'][$group]);
            $ids=array_unique(array_merge(array_keys($legacy['accounts']),array_keys($synthetic['accounts'])));sort($ids);
            foreach($ids as $id) {
                $old=$legacy['accounts'][$id]??null;$new=$synthetic['accounts'][$id]??null;
                $accounts[$id]=['legacy'=>$old,'synthetic'=>$new,
                    'debit_delta_cents'=>$this->delta($new['debit_cents']??0,$old['debit_cents']??0),
                    'credit_delta_cents'=>$this->delta($new['credit_cents']??0,$old['credit_cents']??0),
                    'contribution_delta_cents'=>$this->delta($new['contribution_cents']??0,$old['contribution_cents']??0)];
            }
            $ids=array_unique(array_merge(array_keys($legacy['details']),array_keys($synthetic['details'])));sort($ids);
            foreach($ids as $id) {
                $old=$legacy['details'][$id]??null;$new=$synthetic['details'][$id]??null;
                $account=($new??$old)['account_id'];
                $oldGroup=$legacy['accounts'][$account]['group']??null;$newGroup=$synthetic['accounts'][$account]['group']??null;
                $row=$new??$old;$reasons=[];
                if ($old===null) {
                    if (!$row['current_account']['postable']) $reasons[]='legacy_excludes_nonpostable_account';
                    if ($p['report']==='result' && !preg_match('/^[4-7]\./',$row['current_account']['code'])) $reasons[]='legacy_code_not_covered';
                    if ($p['report']==='result' && $row['origin']===null) $reasons[]='legacy_result_excludes_null_origin';
                } elseif ($new===null) {
                    if (!in_array($row['state'],$p['states'],true)) $reasons[]='explicit_state_filter';
                    if (isset($p['period_id']) && $row['period_id']!==$p['period_id']) $reasons[]='explicit_period_filter';
                    if ($row['origin']===null && $p['null_origin']==='exclude') $reasons[]='explicit_null_origin_filter';
                    if ($p['center_scope']!=='all' && ($p['center_scope']==='unassigned' ? $row['center_id']!==null : !in_array($row['center_id'],$p['center_scope'],true))) $reasons[]='explicit_center_filter';
                } elseif ($oldGroup!==$newGroup) $reasons[]='explicit_classification_differs_from_legacy_code';
                if (($old===null || $new===null) && !$reasons) $warnings[]=['kind'=>'unexplained_membership_difference','detail_id'=>$id];
                $cents=static fn($v)=>(str_starts_with($v,'-')?-1:1)*(int)str_replace(['-','.'],'',$v);
                $details[$id]=['legacy'=>$old,'synthetic'=>$new,'legacy_group'=>$old?$oldGroup:null,'synthetic_group'=>$new?$newGroup:null,
                    'legacy_contribution_cents'=>$legacy['detail_contributions_cents'][$id]??null,
                    'synthetic_contribution_cents'=>$synthetic['detail_contributions_cents'][$id]??null,
                    'debit_delta_cents'=>$this->delta($new?$cents($new['debit']):0,$old?$cents($old['debit']):0),
                    'credit_delta_cents'=>$this->delta($new?$cents($new['credit']):0,$old?$cents($old['credit']):0),
                    'reasons'=>$reasons,
                    'difference'=>$old===null?'synthetic_only':($new===null?'legacy_only':($oldGroup!==$newGroup?'classification_changed':'equal'))];
                if ($old!==null && $new!==null && $old!==$new) throw new DomainException('Original detail changed inside comparison.');
            }
        }
        return ['parameters'=>$p,'synthetic_status'=>$unmapped?'rejected_missing_classification':'read',
            'synthetic'=>$synthetic,'legacy'=>$legacy,'warnings'=>$warnings,
            'differences'=>['groups_cents'=>$groups,'accounts'=>$accounts,'details'=>$details],
            'classification_version'=>$classification['version'],
            'classification_hash'=>$engine->read($classification,[],$p)['classification_hash']];
    }

    private function delta(int $new,int $old): int
    {
        $difference=$new-$old;
        if(!is_int($difference))throw new DomainException('Comparison exceeds integer cents.');
        return $difference;
    }
}
