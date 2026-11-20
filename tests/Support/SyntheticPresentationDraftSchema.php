<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/** NOT a Laravel migration. Only the guarded test installer may invoke this class. */
final class SyntheticPresentationDraftSchema
{
    public static function statements(): array
    {
        return [<<<'SQL'
CREATE TABLE test_financial_presentation_drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 family_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version_number INT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 purpose VARCHAR(16) NOT NULL,
 origin VARCHAR(32) NOT NULL,
 installation_scope VARCHAR(32) NOT NULL,
 status VARCHAR(32) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 canonical_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provenance JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY test_fp_document_uuid (document_uuid),
 UNIQUE KEY test_fp_family_version (family_uuid,version_number),
 UNIQUE KEY test_fp_actor_identity (id,actor_id),
 CONSTRAINT test_fp_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT test_fp_content CHECK (JSON_VALID(canonical_content) AND content_hash=SHA2(canonical_content,256)),
 CONSTRAINT test_fp_contract CHECK (
  version_number>0 AND revision>0 AND purpose='simulation' AND origin='synthetic_fixture'
  AND installation_scope='TEST-FIXTURE-ONLY' AND status IN ('draft','frozen_simulation')
  AND REGEXP_LIKE(document_uuid,'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$','c')
  AND REGEXP_LIKE(family_uuid,'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$','c')
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.contract'))='financial-presentation-v1',0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.purpose'))='simulation',0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.origin'))='synthetic_fixture',0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.installation_scope'))='TEST-FIXTURE-ONLY',0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.family_uuid'))=family_uuid,0)
  AND COALESCE(JSON_EXTRACT(canonical_content,'$.version_number')=version_number,0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(canonical_content,'$.author'))=CONCAT('SYNTHETIC:executor_',actor_id),0)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(provenance,'$.contract'))='AC05B3B2_SYNTHETIC_DRAFTS_CLI_V1',0)
  AND COALESCE(JSON_EXTRACT(provenance,'$.synthetic')=true,0)
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL,
<<<'SQL'
CREATE TABLE test_financial_presentation_draft_audits (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 draft_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event VARCHAR(16) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 expected_revision INT UNSIGNED NOT NULL,
 before_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
 before_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 after_content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 after_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reason VARCHAR(512) NOT NULL,
 recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY test_fp_request_uuid (request_uuid),
 UNIQUE KEY test_fp_revision (draft_id,revision),
 CONSTRAINT test_fp_audit_owner FOREIGN KEY (draft_id,actor_id) REFERENCES test_financial_presentation_drafts(id,actor_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT test_fp_audit_content CHECK (JSON_VALID(after_content) AND after_hash=SHA2(after_content,256)
  AND ((before_content IS NULL AND before_hash IS NULL) OR (before_content IS NOT NULL AND before_hash IS NOT NULL AND JSON_VALID(before_content) AND before_hash=SHA2(before_content,256)))),
 CONSTRAINT test_fp_audit_contract CHECK (
  REGEXP_LIKE(request_uuid,'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$','c')
  AND CHAR_LENGTH(TRIM(reason))>0
  AND ((event='created' AND revision=1 AND expected_revision=0 AND before_content IS NULL AND before_hash IS NULL)
   OR (event='revised' AND revision>1 AND expected_revision=revision-1 AND before_content IS NOT NULL AND before_hash IS NOT NULL))
 )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL];
    }

    public static function install(PDO $pdo): void
    {
        SyntheticPresentationHarness::preflight($pdo, 89);
        if ($pdo->inTransaction()) throw new RuntimeException('DDL must be explicit outside transactions.');
        $root = dirname(__DIR__, 2);
        $b = json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-backup.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!$b['source_unchanged'] || $b['tables'] !== 89 || $b['migrations'] !== 82
            || !str_starts_with($b['file'], 'E:/salon-erp-backups/salon_erp_test_AC05B3B2_')
            || filesize($b['file']) !== $b['bytes'] || hash_file('sha256', $b['file']) !== $b['sha256']
            || !str_starts_with(file_get_contents($b['file'].'.sha256'), $b['sha256'].'  ')) throw new RuntimeException('New backup invalid.');
        $baseline = json_decode(file_get_contents($root.'/storage/app/private/ac05b3b2-isolation-before.json'), true, 512, JSON_THROW_ON_ERROR);
        if (count($baseline['salon_erp_test']) !== 89) throw new RuntimeException('Baseline invalid.');
        foreach (self::statements() as $sql) $pdo->exec($sql);
    }

    /** Explicit empty-only rollback; never deletes evidence to make rollback possible. */
    public static function removeEmpty(PDO $pdo): void
    {
        SyntheticPresentationHarness::preflight($pdo, 91);
        if ($pdo->inTransaction()) throw new RuntimeException('DDL in transaction forbidden.');
        foreach (['test_financial_presentation_draft_audits','test_financial_presentation_drafts'] as $t) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() !== 0) throw new RuntimeException('Rollback blocked by synthetic documentary evidence.');
        }
        $pdo->exec('DROP TABLE test_financial_presentation_draft_audits');
        $pdo->exec('DROP TABLE test_financial_presentation_drafts');
    }
}
