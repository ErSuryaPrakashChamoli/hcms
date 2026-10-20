<?php

namespace App\Domain\Signup\Models;

use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Signup\Enums\SignupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS.10: one self-service signup application. A platform record (it exists before its tenant): never tenant-scoped,
 * read and written only by the Signup services and the operator page, which run as the platform. Its state changes
 * only through SignupApplications / SignupReview / SignupProvisioning (conditional updates, audited); the model refuses
 * deletion (an application is evidence of what was applied for and accepted).
 *
 * Secrets are never stored: the e-mail link is kept as its SHA-256 only. Hidden from serialisation: the link hash, the
 * provisioning fencing token and the acknowledging IP address.
 */
#[Fillable(['reference', 'status', 'email', 'email_hash', 'contact_name', 'company_name', 'legal_name', 'display_name', 'country_code', 'timezone',
    'intended_employees', 'plan_code', 'plan_version_id', 'billing_market_id', 'trial_days', 'terms_version', 'privacy_version', 'authority_confirmed',
    'acknowledged_at', 'acknowledged_ip', 'token_hash', 'token_expires_at', 'token_sent_at', 'tokens_sent', 'email_verified_at', 'submitted_at',
    'review_required', 'review_reasons', 'expires_at'])]
#[Hidden(['token_hash', 'claim_token', 'acknowledged_ip'])]
class SignupApplication extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('A signup application is never deleted: it records what was applied for and accepted.');
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SignupStatus::class,
            'intended_employees' => 'integer',
            'trial_days' => 'integer',
            'authority_confirmed' => 'boolean',
            'acknowledged_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'token_sent_at' => 'datetime',
            'tokens_sent' => 'integer',
            'email_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'review_required' => 'boolean',
            'review_reasons' => 'array',
            'decided_at' => 'datetime',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'failed_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'invitation_failures' => 'integer',
            'invitation_attempted_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function provisionedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'provisioned_tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The e-mail domain (for duplicate-company checks and the operator page), or null once the address was removed. */
    public function domain(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }

        return strtolower(substr($this->email, strrpos($this->email, '@') + 1)) ?: null;
    }

    /** @return list<string> */
    public function reviewReasons(): array
    {
        return array_values((array) ($this->review_reasons ?? []));
    }

    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
