<?php

namespace App\Domain\ComplianceReports\Support;

/**
 * The form, legal basis and sourcing of an obligation for one establishment and period (ReportDefinition::resolveFor).
 * A legal source counts as verified only when the official primary text was read for it and no legal review is open.
 */
final class ResolvedForm
{
    /** @param  list<array{0: string, 1: string}>  $sources */
    public function __construct(
        public readonly string $form,
        public readonly string $legalBasis,
        public readonly string $confidence,
        public readonly array $sources = [],
        public readonly ?string $verifiedOn = null,
        public readonly ?string $legalReview = null,
        public readonly ?string $note = null,
        public readonly ?string $configurationRequired = null,
        public readonly ?string $effectiveFrom = null,
        public readonly ?string $effectiveTo = null,
        public readonly bool $statutory = true,
    ) {}

    /**
     * LEGAL_SOURCE_VERIFIED (official primary text read, nothing open), UNVERIFIED (no official source: unverified or
     * secondary sources only) or LEGAL_REVIEW_REQUIRED (official source partly read, conflicting sources, or an open
     * legal question). A secondary source never counts as verified. An internal control (a PeopleOS check, not a
     * statutory report) is NOT_STATUTORY: there is no legal source to verify, and it never reads as legally verified.
     */
    public function legalStatus(): string
    {
        return match (true) {
            ! $this->statutory => 'NOT_STATUTORY',
            $this->confidence === 'OFFICIAL_PRIMARY_SOURCE_VERIFIED' && $this->legalReview === null => 'LEGAL_SOURCE_VERIFIED',
            in_array($this->confidence, ['UNVERIFIED', 'SECONDARY_SOURCE_ONLY'], true) => 'UNVERIFIED',
            default => 'LEGAL_REVIEW_REQUIRED',
        };
    }

    /** The window the form applies in, e.g. "from 2026-05-08", or null when it is not dated. */
    public function effectiveLabel(): ?string
    {
        $label = trim(($this->effectiveFrom ? 'from '.$this->effectiveFrom : '').($this->effectiveTo ? ' to '.$this->effectiveTo : ''));

        return $label === '' ? null : $label;
    }

    public function confidenceLabel(): string
    {
        if (! $this->statutory) {
            return 'Internal control — not a statutory report';
        }

        return ReportDefinition::CONFIDENCE[$this->confidence] ?? $this->confidence;
    }
}
