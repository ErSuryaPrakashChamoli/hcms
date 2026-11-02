<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Partners\Models\BusinessPartner;
use Illuminate\Database\Eloquent\Builder;

/**
 * HROne parity WO-15 (matrix 21 Workforce #3 "Business partners Report", matrix 23 §23.8 #10): one row per business
 * partner — code, name, type, vendor, contact, sign-in state and roles, status. For whoever opens the register
 * (partner.view or partner.manage); the date of birth only with partner.manage (a sensitive field), never in a feed
 * without a reader. Partners are not employees: there is no organisation scope on these rows.
 */
class PartnersDataset extends Dataset
{
    /** @var array<string, array<string, string>> option list labels, read once per dataset instance */
    private array $labels = [];

    public function key(): string
    {
        return 'partners';
    }

    public function label(): string
    {
        return 'Business partners (type, vendor, contact, sign-in)';
    }

    public function category(): string
    {
        return 'Administration';
    }

    public function permissions(): array
    {
        return ['partner.view', 'partner.manage'];
    }

    public function sensitivePermission(): ?string
    {
        return 'partner.manage';
    }

    public function fields(): array
    {
        $label = fn (string $list, ?string $code) => $code === null ? null : (($this->labels[$list] ??= app(OptionLists::class)->labels($list))[$code] ?? $code);

        return [
            'code' => ['label' => 'Business partner code', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->code],
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->displayName($this->labels['salutations'] ??= app(OptionLists::class)->labels('salutations'))],
            'partner_type' => ['label' => 'Type', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $label('partner_types', $p->partner_type)],
            'partner_type_code' => ['label' => 'Type (code)', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->partner_type],
            'vendor' => ['label' => 'Vendor', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->vendor?->name],
            'vendor_code' => ['label' => 'Vendor code', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->vendor?->code],
            'official_email' => ['label' => 'Official e-mail', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->official_email],
            'personal_email' => ['label' => 'Personal e-mail', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->personal_email],
            'mobile' => ['label' => 'Mobile', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->mobileDisplay()],
            'gender' => ['label' => 'Gender', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->gender === null ? null : (config('peopleos.people.genders')[$p->gender] ?? $p->gender)],
            'nationality' => ['label' => 'Nationality', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->nationality],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'sensitive' => true, 'value' => fn (BusinessPartner $p) => $p->date_of_birth],
            'sign_in' => ['label' => 'Sign-in', 'type' => 'string', 'value' => fn (BusinessPartner $p) => BusinessPartner::SIGN_IN[$p->signInState()]],
            'sign_in_code' => ['label' => 'Sign-in (code)', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->signInState()],
            'roles' => ['label' => 'Roles', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->user?->roles->pluck('name')->unique()->sort()->implode(', ')],
            'last_login' => ['label' => 'Last sign-in', 'type' => 'date', 'value' => fn (BusinessPartner $p) => $p->user?->last_login_at],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn (BusinessPartner $p) => BusinessPartner::STATUSES[$p->status] ?? $p->status],
            'status_code' => ['label' => 'Status (code)', 'type' => 'string', 'value' => fn (BusinessPartner $p) => $p->status],
            'added_on' => ['label' => 'Added on', 'type' => 'date', 'value' => fn (BusinessPartner $p) => $p->created_at],
            'count' => ['label' => 'Business partners (1 per row)', 'type' => 'number', 'value' => fn () => 1],
        ];
    }

    public function query(): Builder
    {
        return BusinessPartner::query()->with(['vendor', 'user.roles'])->orderBy('code');
    }
}
