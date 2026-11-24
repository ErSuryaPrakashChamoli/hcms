<?php

namespace App\Domain\Employment\Requirements;

/** One finding of the employment data requirements: the rule, the field it concerns, Warn or Block, and the message. */
final class RequirementProblem
{
    public function __construct(
        public readonly string $rule,
        public readonly ?string $field,
        public readonly string $mode,
        public readonly string $message,
    ) {}

    public function blocks(): bool
    {
        return $this->mode === EmploymentRequirements::BLOCK;
    }

    /** @return array{rule: string, field: ?string, mode: string, message: string} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'field' => $this->field, 'mode' => $this->mode, 'message' => $this->message];
    }
}
