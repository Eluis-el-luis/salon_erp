<?php

namespace App\Services;

use DomainException;

/** No SQL and no financial writer: bridge a detached verified photograph to the B3 adapter. */
final class SyntheticFinancialPresentationDraftPreview
{
    public function present(SyntheticPresentationDraftSnapshot $photo, string $report, array $fixture): array
    {
        // A caller cannot acquire trust merely by constructing a value object.
        $v = (new SyntheticPresentationDraftIntegrity)->verify($photo->document, $photo->audits, (int)$photo->document['actor_id']);
        $result = (new FinancialPresentationSyntheticAdapter)->read($v['canonical_content'], $report, $fixture);
        if ($result['source']['content_hash'] !== $photo->document['content_hash'] || $photo->validation['hash'] !== $v['hash']) throw new DomainException('preview.hash_mismatch');
        // Engine sorts account/detail IDs; hash the exact derived output, not an issued edition.
        $resultHash = hash('sha256', json_encode(['contract'=>'synthetic-draft-preview-v1',
            'source_hash'=>$v['hash'],'fixture_hash'=>$result['fixture']['hash'],'presentation'=>$result['presentation']],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['document'=>['uuid'=>$photo->document['document_uuid'],'revision'=>(int)$photo->document['revision'],
            'status'=>$photo->document['status'],'stored_hash'=>$photo->document['content_hash'],'revalidated_hash'=>$v['hash']],
            'snapshot'=>$photo->snapshot,'adapted'=>$result,'derived_presentation_hash'=>$resultHash,
            'business_authority'=>false,'publishable'=>false,'issued_statement'=>false];
    }
}
