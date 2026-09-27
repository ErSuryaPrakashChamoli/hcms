<?php

namespace App\Domain\Ai\Providers;

final class NullProvider implements AiProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function complete(string $system, string $user, int $maxTokens = 700): ?array
    {
        return null;
    }
}
