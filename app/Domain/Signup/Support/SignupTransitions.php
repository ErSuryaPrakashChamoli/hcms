<?php

namespace App\Domain\Signup\Support;

use App\Domain\Signup\Enums\SignupStatus;
use App\Domain\Signup\Models\SignupApplication;
use DateTimeInterface;
use LogicException;

/**
 * SaaS.10: the only writer of signup_applications.status. A move is allowed only along SignupStatus::TRANSITIONS and
 * is a conditional update from the states the caller saw, so two actors racing (an operator approving while the
 * applicant cancels, two provisioning runs) change the row once: the loser gets false and re-reads.
 */
final class SignupTransitions
{
    /**
     * @param  array<string, mixed>  $attributes  other columns to set in the same update
     * @param  list<SignupStatus>|null  $from  the states the move may start from (default: the application's current state)
     */
    public function move(SignupApplication $application, SignupStatus $to, array $attributes = [], ?array $from = null, ?string $claimToken = null): bool
    {
        $from ??= [$application->status];
        foreach ($from as $state) {
            if (! $state->canBecome($to)) {
                throw new LogicException("A signup application cannot go from {$state->value} to {$to->value}.");
            }
        }
        $values = ['status' => $to->value, 'updated_at' => now()];
        foreach ($attributes as $column => $value) {
            $values[$column] = match (true) {
                is_array($value) => json_encode(array_values($value)),
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                $value instanceof SignupStatus => $value->value,
                default => $value,
            };
        }
        $changed = SignupApplication::query()->whereKey($application->getKey())->whereIn('status', array_map(fn (SignupStatus $s) => $s->value, $from))
            ->when($claimToken !== null, fn ($q) => $q->where('claim_token', $claimToken))
            ->update($values);
        if ($changed !== 1) {
            return false;
        }
        $application->refresh();

        return true;
    }
}
