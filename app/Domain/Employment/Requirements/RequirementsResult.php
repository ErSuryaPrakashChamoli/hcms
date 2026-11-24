<?php

namespace App\Domain\Employment\Requirements;

/** What the employment data requirements say about one position: the policy that applied (if any) and its findings. */
final class RequirementsResult
{
    /** @param  list<RequirementProblem>  $problems */
    public function __construct(
        public readonly array $problems = [],
        public readonly ?string $policy = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** @return list<RequirementProblem> */
    public function blocking(): array
    {
        return array_values(array_filter($this->problems, fn (RequirementProblem $p) => $p->blocks()));
    }

    /** @return list<RequirementProblem> */
    public function warnings(): array
    {
        return array_values(array_filter($this->problems, fn (RequirementProblem $p) => ! $p->blocks()));
    }

    /** @return list<string> */
    public function warningMessages(): array
    {
        return array_map(fn (RequirementProblem $p) => $p->message, $this->warnings());
    }

    /** @return list<string> */
    public function blockingMessages(): array
    {
        return array_map(fn (RequirementProblem $p) => $p->message, $this->blocking());
    }
}
