<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;

/** An assistant answers only from domain services called with the asking user's permissions (§93). */
interface Assistant
{
    public function key(): string;

    /** @return array<int, string> example questions shown in the UI */
    public function examples(): array;

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer;
}
