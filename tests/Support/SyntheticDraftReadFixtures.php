<?php

namespace Tests\Support;

use App\Services\{FinancialPresentationContractValidator,SyntheticPresentationDraftIntegrity,SyntheticPresentationDraftSnapshot};

/** Entirely fictional projections; never query the account catalogue or persist a fixture. */
final class SyntheticDraftReadFixtures
{
    public static function photo(bool $revised = true, bool $rich = false): SyntheticPresentationDraftSnapshot
    {
        $content = $rich ? VersionedPresentationFixtures::contract() : FinancialPresentationContractFixtures::content();
        $content['author'] = 'SYNTHETIC:executor_123';
        $validator = new FinancialPresentationContractValidator; $first = $validator->validate($content);
        $uuid = '00000000-0000-4000-8000-000000000004';
        $row = ['id'=>11,'document_uuid'=>$uuid,'family_uuid'=>$content['family_uuid'],'version_number'=>$content['version_number'],
            'actor_id'=>123,'purpose'=>'simulation','origin'=>'synthetic_fixture','installation_scope'=>'TEST-FIXTURE-ONLY',
            'status'=>'draft','revision'=>1,'canonical_content'=>$first['canonical_json'],'content_hash'=>$first['hash'],
            'created_at'=>'2030-10-01 00:00:00.000000','updated_at'=>'2030-10-01 00:00:00.000000',
            'provenance'=>json_encode(['contract'=>SyntheticPresentationDraftIntegrity::PROVENANCE_CONTRACT,'synthetic'=>true,'actor_selection'=>'first_active_accounting_harness_actor'], JSON_THROW_ON_ERROR)];
        $history = [['id'=>21,'draft_id'=>11,'actor_id'=>123,'request_uuid'=>$uuid,'event'=>'created','revision'=>1,'expected_revision'=>0,
            'before_content'=>null,'before_hash'=>null,'after_content'=>$first['canonical_json'],'after_hash'=>$first['hash'],'reason'=>'SYNTHETIC:create']];
        if ($revised) {
            $content['label'] = 'SYNTHETIC:memory revised, not a persisted document'; $next = $validator->validate($content);
            $history[] = ['id'=>22,'draft_id'=>11,'actor_id'=>123,'request_uuid'=>'00000000-0000-4000-8000-000000000005',
                'event'=>'revised','revision'=>2,'expected_revision'=>1,'before_content'=>$first['canonical_json'],'before_hash'=>$first['hash'],
                'after_content'=>$next['canonical_json'],'after_hash'=>$next['hash'],'reason'=>'SYNTHETIC:revision'];
            $row['revision'] = 2; $row['canonical_content'] = $next['canonical_json']; $row['content_hash'] = $next['hash'];
        }
        return new SyntheticPresentationDraftSnapshot($row,$history,$validator->validate($content),['data_origin'=>'synthetic_memory_only']);
    }

    public static function compatible(): array
    {
        // Existing B2 contract explicitly assigns this fictional ID to income.
        // A credit and its full manual reversal are balanced, net zero; no invented bank mapping.
        $rows = [];
        foreach ([['0.00','20.00','manual',null],['20.00','0.00','reversion',1]] as $i=>[$d,$h,$origin,$ref]) {
            $rows[] = ['detail_id'=>$i+1,'entry_id'=>$i+1,'account_id'=>900001,'period_id'=>1,'date'=>'2030-10-15',
                'state'=>'contabilizado','origin'=>$origin,'debit'=>$d,'credit'=>$h,'reference_id'=>$ref,
                'current_account'=>['code'=>'SYNTHETIC-ALTERNATIVE','name'=>'SYNTHETIC income','active'=>false,'parent_id'=>null,'postable'=>true]];
        }
        return ['id'=>'SYNTHETIC:AC05B3B4-COMPATIBLE-V1','base_currency'=>'NIO','amount_basis'=>'original_ledger_base','details'=>$rows];
    }
}
