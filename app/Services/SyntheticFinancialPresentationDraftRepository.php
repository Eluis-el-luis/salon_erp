<?php

namespace App\Services;

use Closure;
use DomainException;
use PDO;
use PDOException;
use Tests\Support\SyntheticPresentationHarness;

/** Test-only documentary persistence. No business publication or financial effects. */
final class SyntheticFinancialPresentationDraftRepository
{
    private int $actor;

    private function __construct(private PDO $pdo, private ?Closure $probe)
    {
        $id = $pdo->query("SELECT id FROM users WHERE is_active=1 AND role IN ('alvaro','contador') ORDER BY id LIMIT 1")->fetchColumn();
        if ($id === false) throw new DomainException('harness.actor_unavailable');
        $this->actor = (int)$id; // Trusted harness selection, never a client-provided identity.
    }

    public static function forHarness(?Closure $probe = null): self
    {
        return new self(SyntheticPresentationHarness::connect(), $probe);
    }

    public function author(): string { $this->guard(); return 'SYNTHETIC:executor_'.$this->actor; }
    public function connectionId(): int { $this->guard(); return (int)$this->pdo->query('SELECT CONNECTION_ID()')->fetchColumn(); }

    public function create(string $uuid, array $content, string $reason): array
    {
        $this->guard(); $this->uuid($uuid); $this->reason($reason); $r = $this->validate($content);
        return $this->transaction(function () use ($uuid, $reason, $r) {
            $c = $r['canonical_content'];
            try {
                $this->query("INSERT INTO test_financial_presentation_drafts
                    (document_uuid,family_uuid,version_number,actor_id,purpose,origin,installation_scope,status,revision,canonical_content,content_hash,provenance)
                    VALUES(?,?,?,?,'simulation','synthetic_fixture','TEST-FIXTURE-ONLY','draft',1,?,?,?)",
                    [$uuid,$c['family_uuid'],$c['version_number'],$this->actor,$r['canonical_json'],$r['hash'],
                        json_encode(['contract'=>SyntheticPresentationHarness::CONTRACT,'synthetic'=>true,'actor_selection'=>'first_active_accounting_harness_actor'], JSON_THROW_ON_ERROR)]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $row = $this->query('SELECT * FROM test_financial_presentation_drafts WHERE document_uuid=? FOR UPDATE', [$uuid])->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new DomainException('identity.family_version_conflict');
                $history = $this->verified($row);
                $first = $history[0];
                if ($first['after_hash'] !== $r['hash'] || $first['reason'] !== $reason) throw new DomainException('identity.content_conflict');
                return $this->result($row, $history, true);
            }
            $id = (int)$this->pdo->lastInsertId(); $this->hit('after_header');
            $this->audit($id, $uuid, 'created', 1, 0, null, null, $r['canonical_json'], $r['hash'], $reason);
            $this->hit('after_audit');
            return $this->load($uuid, false);
        });
    }

    public function revise(string $uuid, string $requestUuid, int $expectedRevision, string $expectedHash, array $replacement, string $reason): array
    {
        $this->guard(); $this->uuid($uuid); $this->uuid($requestUuid); $this->reason($reason);
        if ($expectedRevision < 1 || !preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) throw new DomainException('revision.invalid_precondition');
        $next = $this->validate($replacement);
        return $this->transaction(function () use ($uuid,$requestUuid,$expectedRevision,$expectedHash,$replacement,$reason,$next) {
            $row = $this->query('SELECT * FROM test_financial_presentation_drafts WHERE document_uuid=? FOR UPDATE', [$uuid])->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new DomainException('document.not_found');
            $history = $this->verified($row); $this->hit('after_lock');
            $replay = $this->query('SELECT * FROM test_financial_presentation_draft_audits WHERE request_uuid=? FOR UPDATE', [$requestUuid])->fetch(PDO::FETCH_ASSOC);
            if ($replay) {
                if ((int)$replay['draft_id'] !== (int)$row['id'] || $replay['event'] !== 'revised'
                    || (int)$replay['expected_revision'] !== $expectedRevision || $replay['before_hash'] !== $expectedHash
                    || $replay['after_hash'] !== $next['hash'] || $replay['reason'] !== $reason) throw new DomainException('request.content_conflict');
                return $this->result($row, $history, true) + ['replayed_revision'=>(int)$replay['revision']];
            }
            if ($row['status'] !== 'draft' || (int)$row['revision'] !== $expectedRevision || $row['content_hash'] !== $expectedHash) throw new DomainException('revision.stale_or_frozen');
            (new FinancialPresentationContractValidator)->revise(json_decode($row['canonical_content'], true, 512, JSON_THROW_ON_ERROR), $expectedHash, $replacement, $row['status']);
            $revision = $expectedRevision + 1;
            $this->query('UPDATE test_financial_presentation_drafts SET canonical_content=?,content_hash=?,revision=?,updated_at=CURRENT_TIMESTAMP(6) WHERE id=? AND revision=?',
                [$next['canonical_json'],$next['hash'],$revision,$row['id'],$expectedRevision]);
            $this->hit('after_update');
            $this->audit((int)$row['id'], $requestUuid, 'revised', $revision, $expectedRevision, $row['canonical_content'], $row['content_hash'], $next['canonical_json'], $next['hash'], $reason);
            $this->hit('after_audit'); return $this->load($uuid, false);
        });
    }

    public function get(string $uuid): array
    {
        $this->guard(); $this->uuid($uuid);
        // Lock only our document, before reading its audit. No financial/admin resources are locked.
        return $this->transaction(fn () => $this->load($uuid, false));
    }

    private function guard(): void
    {
        SyntheticPresentationHarness::identity($this->pdo);
        if (!$this->query("SELECT id FROM users WHERE id=? AND is_active=1 AND role IN ('alvaro','contador')", [$this->actor])->fetchColumn()) throw new DomainException('harness.actor_unauthorized');
    }

    private function validate(array $content): array
    {
        $r = (new FinancialPresentationContractValidator)->validate($content);
        if ($r['hash'] === null || $r['canonical_content']['author'] !== 'SYNTHETIC:executor_'.$this->actor) throw new DomainException('content.invalid_or_actor_manipulated');
        // REQUIRES_ACCOUNTING_APPROVAL may be saved as a synthetic draft, never as approval.
        return $r;
    }

    private function load(string $uuid, bool $replayed): array
    {
        $row = $this->query('SELECT * FROM test_financial_presentation_drafts WHERE document_uuid=? FOR UPDATE', [$uuid])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('document.not_found');
        return $this->result($row, $this->verified($row), $replayed);
    }

    private function verified(array $row): array
    {
        $r = $this->validate(json_decode($row['canonical_content'], true, 512, JSON_THROW_ON_ERROR));
        $p = json_decode($row['provenance'], true, 512, JSON_THROW_ON_ERROR);
        $expectedProvenance = ['contract'=>SyntheticPresentationHarness::CONTRACT,'synthetic'=>true,'actor_selection'=>'first_active_accounting_harness_actor'];
        ksort($p); ksort($expectedProvenance);
        if ((int)$row['actor_id'] !== $this->actor || $row['purpose'] !== 'simulation' || $row['origin'] !== 'synthetic_fixture'
            || $row['installation_scope'] !== 'TEST-FIXTURE-ONLY' || !in_array($row['status'], ['draft','frozen_simulation'], true)
            || $p !== $expectedProvenance
            || $r['hash'] !== $row['content_hash'] || $r['canonical_json'] !== $row['canonical_content']
            || $r['canonical_content']['family_uuid'] !== $row['family_uuid'] || $r['canonical_content']['version_number'] !== (int)$row['version_number']) throw new DomainException('document.integrity');
        $history = $this->query('SELECT * FROM test_financial_presentation_draft_audits WHERE draft_id=? ORDER BY revision FOR UPDATE', [$row['id']])->fetchAll(PDO::FETCH_ASSOC);
        $previous = null;
        foreach ($history as $index => $a) {
            $after = $this->validate(json_decode($a['after_content'], true, 512, JSON_THROW_ON_ERROR));
            foreach (['family_uuid','version_number','purpose','origin','installation_scope','author','prepared_at'] as $field) {
                if ($after['canonical_content'][$field] !== $r['canonical_content'][$field]) throw new DomainException('audit.identity_changed');
            }
            if ((int)$a['actor_id'] !== $this->actor || (int)$a['revision'] !== $index + 1 || (int)$a['expected_revision'] !== $index
                || $a['event'] !== ($index === 0 ? 'created' : 'revised') || $after['hash'] !== $a['after_hash']
                || $after['canonical_json'] !== $a['after_content'] || $a['before_content'] !== ($previous['after_content'] ?? null)
                || $a['before_hash'] !== ($previous['after_hash'] ?? null)) throw new DomainException('audit.integrity');
            if ($index === 0 && $a['request_uuid'] !== $row['document_uuid']) throw new DomainException('audit.creation_identity');
            $previous = $a;
        }
        if (!$previous || count($history) !== (int)$row['revision'] || $previous['after_hash'] !== $row['content_hash'] || $previous['after_content'] !== $row['canonical_content']) throw new DomainException('audit.incomplete');
        return $history;
    }

    private function result(array $row, array $history, bool $replayed): array
    {
        return ['document'=>$row, 'history'=>$history, 'replayed'=>$replayed, 'business_authority'=>false, 'publishable'=>false];
    }

    private function audit(int $id, string $request, string $event, int $revision, int $expected, ?string $before, ?string $beforeHash, string $after, string $afterHash, string $reason): void
    {
        $this->query('INSERT INTO test_financial_presentation_draft_audits (draft_id,actor_id,request_uuid,event,revision,expected_revision,before_content,before_hash,after_content,after_hash,reason) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$id,$this->actor,$request,$event,$revision,$expected,$before,$beforeHash,$after,$afterHash,$reason]);
    }

    private function transaction(Closure $work): array
    {
        if ($this->pdo->inTransaction()) throw new DomainException('transaction.must_be_owned');
        $this->pdo->beginTransaction();
        try { $result = $work(); $this->hit('before_commit'); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        finally { putenv('SALON_TEST_DB_RESET_ALLOWED=0'); }
    }
    private function query(string $sql, array $parameters): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($parameters); return $s; }
    private function hit(string $stage): void { if ($this->probe) ($this->probe)($stage); }
    private function uuid(string $uuid): void { if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $uuid)) throw new DomainException('identity.uuid'); }
    private function reason(string $reason): void { if (!str_starts_with($reason, 'SYNTHETIC:') || strlen($reason) > 512 || preg_match('//u', $reason) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $reason)) throw new DomainException('audit.synthetic_reason'); }
}
