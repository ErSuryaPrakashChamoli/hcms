<?php

namespace App\Domain\Partners\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\People\Support\PhoneNumbers;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity WO-15 (matrix 23 §23.8): a person outside the workforce who may be given a sign-in — a customer, a
 * background-verification agent, a vendor's staff, a system consultant. A business partner is never an employee and has
 * no employee record. Written only through App\Domain\Partners\Services\BusinessPartners: the code (number series
 * `business_partner` or typed: letters, digits and _), the partner's identity and contact, the vendor they belong to,
 * and — set only by an invitation — the sign-in (`user_id`, a user of kind partner). The mobile is stored in E.164 when an
 * ISD code is given (PhoneNumbers). Never deleted: inactivated.
 */
#[Fillable(['tenant_id', 'code', 'salutation', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'nationality', 'partner_type', 'isd_code', 'mobile', 'personal_email', 'official_email', 'vendor_id', 'status'])]
class BusinessPartner extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    /** The sign-in state shown on the register (from the linked user). */
    public const SIGN_IN = ['none' => 'Not invited', 'invited' => 'Invited', 'active' => 'Signs in', 'disabled' => 'Login disabled'];

    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (BusinessPartner $partner): void {
            PhoneNumbers::normaliseOn($partner, 'mobile', 'isd_code');
        });
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function auditModule(): string
    {
        return 'partners';
    }

    public function auditLabel(): string
    {
        return 'Business partner '.($this->getAttributes()['code'] ?? '').' '.$this->displayName();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return ($this->getAttributes()['status'] ?? 'active') === 'active';
    }

    /**
     * "Dr Asha K Rao": salutation (its label) and the names given.
     *
     * @param  array<string, string>|null  $salutations  code => label, when the caller read the list once for many rows
     */
    public function displayName(?array $salutations = null): string
    {
        $attributes = $this->getAttributes();
        $code = $attributes['salutation'] ?? null;
        $salutation = filled($code) ? ($salutations !== null ? ($salutations[$code] ?? $code) : app(OptionLists::class)->label('salutations', $code)) : null;

        return trim(implode(' ', array_filter([$salutation, $attributes['first_name'] ?? null, $attributes['middle_name'] ?? null, $attributes['last_name'] ?? null], 'filled')));
    }

    /** The address a sign-in is created for: the official e-mail, else the personal one (lower-cased). */
    public function signInEmail(): ?string
    {
        $attributes = $this->getAttributes();
        $email = filled($attributes['official_email'] ?? null) ? $attributes['official_email'] : ($attributes['personal_email'] ?? null);

        return filled($email) ? mb_strtolower(trim((string) $email)) : null;
    }

    public function typeLabel(): ?string
    {
        return app(OptionLists::class)->label('partner_types', $this->getAttributes()['partner_type'] ?? null);
    }

    public function mobileDisplay(): ?string
    {
        $attributes = $this->getAttributes();

        return PhoneNumbers::display($attributes['isd_code'] ?? null, $attributes['mobile'] ?? null);
    }

    /** none | invited | active | disabled, from the linked sign-in (loaded or read by id). */
    public function signInState(): string
    {
        if (($this->getAttributes()['user_id'] ?? null) === null) {
            return 'none';
        }
        $user = $this->relationLoaded('user') ? $this->user : User::query()->find($this->getAttributes()['user_id']);

        return match ($user?->status) {
            null => 'none',
            UserStatus::Active => 'active',
            UserStatus::Invited => 'invited',
            default => 'disabled',
        };
    }
}
