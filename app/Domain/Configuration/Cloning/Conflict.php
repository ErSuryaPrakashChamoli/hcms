<?php

namespace App\Domain\Configuration\Cloning;

/** Something the person must fix (blocker) or acknowledge (warning) before a clone is written (FR-18). */
final class Conflict
{
    public const BLOCKER = 'blocker';

    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $code,
        public readonly string $message,
        public readonly ?int $companyId = null,
    ) {}

    public static function blocker(string $code, string $message, ?int $companyId = null): self
    {
        return new self(self::BLOCKER, $code, $message, $companyId);
    }

    public static function warning(string $code, string $message, ?int $companyId = null): self
    {
        return new self(self::WARNING, $code, $message, $companyId);
    }

    public function isBlocker(): bool
    {
        return $this->severity === self::BLOCKER;
    }

    /** @return array{severity: string, code: string, message: string, company_id: ?int} */
    public function toArray(): array
    {
        return ['severity' => $this->severity, 'code' => $this->code, 'message' => $this->message, 'company_id' => $this->companyId];
    }
}
