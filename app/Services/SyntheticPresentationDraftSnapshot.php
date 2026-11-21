<?php

namespace App\Services;

/** Detached value photograph; changing a nested array property is also prohibited. */
final readonly class SyntheticPresentationDraftSnapshot
{
    public function __construct(public array $document, public array $audits, public array $validation, public array $snapshot) {}
}
