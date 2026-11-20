<?php

namespace Tests\Unit;

use App\Services\SyntheticFinancialPresentationDraftRepository;
use PHPUnit\Framework\TestCase;

/** Run with vendor/autoload.php only and PDO disabled: rejection must precede SQL. */
final class SyntheticPresentationDraftBoundaryTest extends TestCase
{
    public function test_ordinary_cli_cannot_acquire_repository_without_controlled_bootstrap(): void
    {
        self::assertFalse(defined('SALON_SYNTHETIC_DRAFT_HARNESS'));
        try {
            SyntheticFinancialPresentationDraftRepository::forHarness();
            self::fail('Ordinary CLI acquired test repository.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic draft CLI boundary rejected.', $e->getMessage());
        }
    }
}
