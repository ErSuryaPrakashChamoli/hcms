<?php

namespace App\Domain\Ai\Providers;

/** A language-model backend. Returns null when unavailable so the gateway falls back to deterministic answers. */
interface AiProvider
{
    public function name(): string;

    /** @return array{text: string, model: string, input_tokens: int, output_tokens: int}|null */
    public function complete(string $system, string $user, int $maxTokens = 700): ?array;
}
