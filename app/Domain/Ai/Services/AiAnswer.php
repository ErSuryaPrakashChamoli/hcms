<?php

namespace App\Domain\Ai\Services;

/**
 * What an assistant returns: prose plus the facts it was grounded on and suggested (never executed) actions.
 *
 * @param  array<int, array{label: string, detail?: string}>  $sources
 * @param  array<int, array{label: string, url: string}>  $actions
 */
final class AiAnswer
{
    public function __construct(
        public string $answer,
        public array $sources = [],
        public array $actions = [],
        public ?string $intent = null,
        public bool $isInference = false,
        /** @var array<string, mixed> compact facts handed to the language model, if enabled */
        public array $facts = [],
    ) {}

    public static function text(string $answer, ?string $intent = null, array $sources = [], array $actions = []): self
    {
        return new self($answer, $sources, $actions, $intent);
    }
}
