<?php

namespace App\Domain\Organisation\Resolution;

/**
 * The answer for one typed reference. Never thrown: an import puts error() on the row, warnings() alongside, and the
 * review lists anything reviewable().
 */
final class Resolution
{
    public const VIA_CODE = 'code';

    public const VIA_NAME = 'name';

    public const VIA_MAPPING = 'mapping';

    public const VIA_PLANNED = 'planned';

    /**
     * @param  list<Candidate>  $candidates
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $master,
        public readonly string $masterLabel,
        public readonly ResolutionStatus $status,
        public readonly string $supplied,
        public readonly ?int $companyId = null,
        public readonly ?int $id = null,
        public readonly ?string $via = null,
        public readonly ?string $record = null,
        public readonly array $candidates = [],
        public readonly array $warnings = [],
        private readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->status->ok();
    }

    /** The row's error in plain English, or null when the reference is usable. */
    public function error(): ?string
    {
        return $this->ok() ? null : $this->error;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** error() or the first warning: one line for a results file. */
    public function message(): ?string
    {
        return $this->error() ?? ($this->warnings[0] ?? null);
    }
}
