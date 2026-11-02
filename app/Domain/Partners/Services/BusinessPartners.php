<?php

namespace App\Domain\Partners\Services;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\RoleMembers;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Identity\Services\TemporaryPasswords;
use App\Domain\Identity\Services\UserInvitations;
use App\Domain\Partners\Models\BusinessPartner;
use App\Domain\Partners\Models\Vendor;
use App\Domain\People\Support\PhoneNumbers;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * HROne parity WO-15 (matrix 23 §23.8, §23.9 #21): the one write path of business partners — the register's "Add business
 * partner" wizard, edits, the partner upload (another package) and the ⋮ actions.
 *
 * Records (create / update; `partner.manage`, the actor's own organisation): validation here, whoever calls
 * (ValidationException keyed by field). The code is typed (letters, digits and _ only, at most 32, unique in the
 * organisation without regard to case) or, left blank, taken from the number series `business_partner` (BP00001 by
 * default; Setup → Number series). Partner type from the option list `partner_types`; salutation from `salutations`;
 * gender from peopleos.people.genders; mobile with an ISD code stored in E.164 (PhoneNumbers); a vendor of the organisation
 * that is active (a link already made stays when the vendor is inactivated later). update() takes the fields given and
 * keeps the others; `user_id`, tenant_id and unknown keys are never taken from input. Making a partner inactive disables
 * their login in the same step.
 *
 * Sign-in (owner decision D-WO2, default applied): a partner's sign-in is a user of kind `partner`, created only by
 * invite(). It has no employee record (Employee refuses to link one) and no access except the roles an administrator
 * gives — at the invitation or later on Users → Edit — through RoleMembers and the delegation ceiling (RoleGrants). The
 * invitation is the normal one (TemporaryPasswords::email, as Users → New user since the owner's 2026-10-31 decision: a
 * temporary password e-mailed to the partner only; the administrator never sees it). Inviting needs `user.create` (a new
 * sign-in) or the user's `update` policy (a new invitation); disabling or enabling the login needs the user's `update`
 * policy. Disabling suspends the sign-in, withdraws an unused temporary password and pending invitation links, and ends
 * every session; enabling makes it active again (or invited, when the partner never chose a password: invite them again).
 */
final class BusinessPartners
{
    /** Every field create() / update() accept. */
    public const FIELDS = ['code', 'salutation', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'nationality', 'partner_type', 'isd_code', 'mobile', 'personal_email', 'official_email', 'vendor_id', 'status'];

    /** HROne: "Only a-z, A-Z, 0-9 and _". */
    public const CODE_PATTERN = '/^[A-Za-z0-9_]+$/';

    public const CODE_TYPE = 'business_partner';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly OptionLists $lists,
        private readonly NumberSeries $numbers,
        private readonly TemporaryPasswords $temporaryPasswords,
        private readonly UserInvitations $invitations,
        private readonly RoleMembers $members,
        private readonly RoleGrants $grants,
        private readonly SessionSecurity $sessions,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): BusinessPartner
    {
        $this->authorise($by, 'create', BusinessPartner::class);
        $reason = $this->reason($data);
        $input = array_replace(array_fill_keys(self::FIELDS, null), $this->normalise(array_intersect_key($data, array_flip(self::FIELDS))));
        $input['status'] ??= 'active';
        $values = $this->validated($input, null);
        if ($values['code'] === null) {
            $this->numbers->prepare(self::CODE_TYPE);
        }

        return DB::transaction(function () use ($values, $reason) {
            $values['code'] ??= $this->nextCode();
            $partner = new BusinessPartner;
            $partner->forceFill($values)->withAuditReason($reason)->save();

            return $partner;
        });
    }

    /** @param array<string, mixed> $data the fields to change; the others keep their values */
    public function update(BusinessPartner $partner, array $data, User $by): BusinessPartner
    {
        $this->assertOwn($partner);
        $this->authorise($by, 'update', $partner);
        $reason = $this->reason($data);
        $current = $partner->only(self::FIELDS);
        $current['date_of_birth'] = $partner->date_of_birth?->toDateString();
        $values = $this->validated(array_replace($current, $this->normalise(array_intersect_key($data, array_flip(self::FIELDS)))), $partner);
        if ($values['code'] === null) {
            throw new RuntimeException('A business partner keeps a code.');
        }

        return DB::transaction(function () use ($partner, $values, $reason, $by) {
            $wasActive = $partner->isActive();
            $partner->forceFill($values)->withAuditReason($reason)->save();
            // An inactive partner cannot sign in.
            if ($wasActive && ! $partner->isActive() && ($user = $this->userOf($partner)) !== null && $user->status !== UserStatus::Suspended) {
                $this->suspend($user, $by, 'Business partner '.$partner->code.' made inactive');
            }

            return $partner;
        });
    }

    /** What the next generated code would be (another partner may take it first). */
    public function previewCode(): string
    {
        return $this->numbers->preview(self::CODE_TYPE);
    }

    /**
     * Why the partner cannot be invited by $by now (null = can): a new sign-in, or a new invitation while still invited.
     */
    public function inviteRefusal(BusinessPartner $partner, User $by): ?string
    {
        if (($refusal = $this->managerRefusal($partner, $by)) !== null) {
            return $refusal;
        }
        if (! $partner->isActive()) {
            return 'The business partner is inactive: make them active first.';
        }
        $user = $this->userOf($partner);
        if ($user !== null) {
            return match (true) {
                $user->status === UserStatus::Active => 'Already signs in. A forgotten password is reset from Users ("Send password reset link").',
                $user->status === UserStatus::Suspended => 'The login is disabled: enable it first.',
                Gate::forUser($by)->denies('update', $user) => 'You cannot send a new invitation to this sign-in.',
                default => $this->temporaryPasswords->refusal($user),
            };
        }
        if (! $by->hasPermission('user.create')) {
            return 'You cannot create users.';
        }
        $email = $partner->signInEmail();
        if ($email === null) {
            return 'No e-mail on file: add an official or personal e-mail first.';
        }
        if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
            return 'The e-mail '.$email.' is not a valid address.';
        }
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return 'The e-mail '.$email.' is already used by another user.';
        }

        return null;
    }

    /**
     * Invites the partner to sign in: creates their sign-in (a user of kind partner, invited, a password nobody knows) and
     * links it, gives the roles chosen (none by default), then e-mails the normal invitation; a partner still invited gets
     * a new one (the earlier temporary password stops working).
     *
     * @param  list<int>  $roleIds  roles to give at once (user.assign_roles and the delegation ceiling)
     */
    public function invite(BusinessPartner $partner, User $by, array $roleIds = []): User
    {
        $this->assertOwn($partner);
        if (($refusal = $this->inviteRefusal($partner, $by)) !== null) {
            throw new RuntimeException($refusal);
        }
        $user = $this->userOf($partner);
        if ($user !== null) {
            $this->temporaryPasswords->email($user, $by, 'Business partner '.$partner->code.': new invitation');

            return $user;
        }

        $roles = $this->rolesToGive($roleIds, $by);
        $user = DB::transaction(function () use ($partner, $by, $roles) {
            $user = new User;
            $user->forceFill([
                'tenant_id' => $this->tenants->id(),
                'name' => mb_substr($partner->displayName() ?: (string) $partner->code, 0, 255),
                'email' => (string) $partner->signInEmail(),
                // SaaS.2: as Users → New user — invited, with a password nobody knows; the partner chooses their own.
                'password' => Str::random(64),
                'status' => UserStatus::Invited,
                'is_platform_admin' => false,
                'kind' => UserKind::Partner,
            ])->withAuditReason('Business partner '.$partner->code.': sign-in created')->save();
            $partner->forceFill(['user_id' => $user->getKey()])->withAuditReason('Invited to sign in')->save();
            foreach ($roles as $role) {
                $this->members->add($role, $user, null, $by, 'Business partner invitation');
            }

            return $user;
        });
        $this->temporaryPasswords->email($user, $by, 'Business partner invitation');

        return $user;
    }

    /** Why the partner's login cannot be disabled by $by now (null = can). */
    public function disableRefusal(BusinessPartner $partner, User $by): ?string
    {
        if (($refusal = $this->managerRefusal($partner, $by)) !== null) {
            return $refusal;
        }
        $user = $this->userOf($partner);

        return match (true) {
            $user === null => 'Has no sign-in.',
            $user->status === UserStatus::Suspended => 'The login is already disabled.',
            Gate::forUser($by)->denies('update', $user) => 'You cannot change this sign-in.',
            default => null,
        };
    }

    /** "Disable login": the sign-in is suspended, an unused temporary password withdrawn, every session ended. */
    public function disableLogin(BusinessPartner $partner, User $by, ?string $reason = null): void
    {
        $this->assertOwn($partner);
        if (($refusal = $this->disableRefusal($partner, $by)) !== null) {
            throw new RuntimeException($refusal);
        }
        $user = $this->userOf($partner);
        DB::transaction(fn () => $this->suspend($user, $by, 'Business partner '.$partner->code.': login disabled'.(filled($reason) ? ' ('.Str::limit((string) $reason, 200, '').')' : '')));
    }

    /** Why the partner's login cannot be enabled by $by now (null = can). */
    public function enableRefusal(BusinessPartner $partner, User $by): ?string
    {
        if (($refusal = $this->managerRefusal($partner, $by)) !== null) {
            return $refusal;
        }
        $user = $this->userOf($partner);

        return match (true) {
            $user === null => 'Has no sign-in: invite them instead.',
            $user->status !== UserStatus::Suspended => 'The login is not disabled.',
            ! $partner->isActive() => 'The business partner is inactive: make them active first.',
            Gate::forUser($by)->denies('update', $user) => 'You cannot change this sign-in.',
            default => null,
        };
    }

    /**
     * "Enable login": the sign-in becomes active again — or invited, when the partner never chose a password (their address
     * was never proved), so they need a new invitation.
     *
     * @return UserStatus the sign-in's status now
     */
    public function enableLogin(BusinessPartner $partner, User $by): UserStatus
    {
        $this->assertOwn($partner);
        if (($refusal = $this->enableRefusal($partner, $by)) !== null) {
            throw new RuntimeException($refusal);
        }
        $user = $this->userOf($partner);
        $status = $user->hasVerifiedEmail() ? UserStatus::Active : UserStatus::Invited;
        $user->forceFill(['status' => $status])->withAuditReason('Business partner '.$partner->code.': login enabled')->save();

        return $status;
    }

    /** @return array<string, string> code => label of the partner types offered now */
    public function types(): array
    {
        return $this->lists->options('partner_types');
    }

    private function managerRefusal(BusinessPartner $partner, User $by): ?string
    {
        if ((int) $partner->tenant_id !== (int) $this->tenants->id() || (! $by->isPlatformAdmin() && (int) $by->tenant_id !== (int) $partner->tenant_id)) {
            return 'This business partner belongs to another organisation.';
        }

        return Gate::forUser($by)->allows('update', $partner) ? null : 'You cannot manage business partners.';
    }

    private function suspend(User $user, User $by, string $reason): void
    {
        $this->temporaryPasswords->withdraw($user, $by, $reason);
        $this->invitations->revokePendingFor($user, $by, $reason);
        $user->forceFill(['status' => UserStatus::Suspended])->withAuditReason($reason)->save();
        $this->sessions->revokeUser($user, $reason, $by, 'login_disabled');
    }

    private function userOf(BusinessPartner $partner): ?User
    {
        $id = $partner->getAttributes()['user_id'] ?? null;

        return $id === null ? null : User::query()->where('tenant_id', $partner->tenant_id)->find($id);
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<Role>
     */
    private function rolesToGive(array $roleIds, User $by): array
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        if ($roleIds === []) {
            return [];
        }
        if (! $by->hasPermission('user.assign_roles')) {
            throw new RuntimeException('You cannot give roles.');
        }
        $roles = Role::query()->whereKey($roleIds)->get();
        if ($roles->count() !== count($roleIds)) {
            throw new RuntimeException('Choose roles of this organisation.');
        }
        $this->grants->assertCanAssign($by, $roles);

        return $roles->all();
    }

    /** The next number of the series, as typed codes must look (a series that renders other characters is refused). */
    private function nextCode(): string
    {
        $code = $this->numbers->next(self::CODE_TYPE);
        if (preg_match(self::CODE_PATTERN, $code) !== 1 || mb_strlen($code) > 32) {
            throw new RuntimeException("The business partner code series gives {$code}: codes may hold only letters, digits and _. Change the series in Setup → Number series.");
        }

        return $code;
    }

    private function authorise(User $by, string $ability, mixed $subject): void
    {
        if (! $by->isPlatformAdmin() && (int) $by->tenant_id !== (int) $this->tenants->id()) {
            throw new AuthorizationException('Business partners are managed by people of the organisation.');
        }
        if (Gate::forUser($by)->denies($ability, $subject)) {
            throw new AuthorizationException('You cannot manage business partners.');
        }
    }

    private function assertOwn(BusinessPartner $partner): void
    {
        $tenantId = $this->tenants->id() ?? throw new RuntimeException('Business partners are managed inside an organisation.');
        if ((int) $partner->tenant_id !== (int) $tenantId) {
            throw new AuthorizationException('This business partner belongs to another organisation.');
        }
    }

    private function reason(array &$data): ?string
    {
        $reason = $data['audit_reason'] ?? null;
        unset($data['audit_reason']);

        return filled($reason) ? mb_substr((string) $reason, 0, 1000) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach ($data as $key => $value) {
            // Numbers typed into text fields (a sheet's mobile or PIN, a select keyed by codes) are text.
            if (is_int($value) && $key !== 'vendor_id') {
                $value = (string) $value;
            }
            if (is_string($value)) {
                $value = trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }
        foreach (['personal_email', 'official_email'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = mb_strtolower($data[$key]);
            }
        }
        // A select keyed by dialling codes hands them over as integers.
        if (isset($data['isd_code']) && (is_string($data['isd_code']) || is_int($data['isd_code']))) {
            $data['isd_code'] = ltrim((string) $data['isd_code'], '+');
        }
        if (array_key_exists('vendor_id', $data) && $data['vendor_id'] !== null) {
            $data['vendor_id'] = is_numeric($data['vendor_id']) ? (int) $data['vendor_id'] : $data['vendor_id'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function validated(array $values, ?BusinessPartner $partner): array
    {
        $current = $partner?->getAttributes() ?? [];
        // An option hidden from its list since stays valid on the partners that already have it.
        $allowed = fn (array $options, string $field) => array_values(array_filter([...array_keys($options), $current[$field] ?? null], fn ($v) => $v !== null));

        $validator = Validator::make($values, [
            'code' => [$partner === null ? 'nullable' : 'required', 'string', 'max:32', 'regex:'.self::CODE_PATTERN],
            'salutation' => ['nullable', 'string', Rule::in($allowed($this->lists->options('salutations'), 'salutation'))],
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', 'string', Rule::in($allowed((array) config('peopleos.people.genders', []), 'gender'))],
            'nationality' => ['nullable', 'string', 'max:64'],
            'partner_type' => ['required', 'string', Rule::in($allowed($this->types(), 'partner_type'))],
            'isd_code' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,2}$/'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'personal_email' => ['nullable', 'string', 'max:255', 'email'],
            'official_email' => ['nullable', 'string', 'max:255', 'email'],
            'vendor_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(array_keys(BusinessPartner::STATUSES))],
        ], [
            'code.regex' => 'Only a-z, A-Z, 0-9 and _ are allowed in a business partner code.',
            'partner_type.in' => 'Choose a partner type from the list (Setup → Option lists → Business partner types).',
            'isd_code.regex' => 'Choose an ISD code from the list (one to three digits).',
            'date_of_birth.before' => 'The date of birth must be in the past.',
        ]);

        $validator->after(function ($validator) use ($values, $partner, $current): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($values['code'] !== null) {
                $taken = BusinessPartner::query()->whereRaw('lower(code) = ?', [mb_strtolower((string) $values['code'])])
                    ->when($partner !== null, fn ($q) => $q->whereKeyNot($partner->getKey()))->exists();
                if ($taken) {
                    $validator->errors()->add('code', 'Another business partner already has the code '.$values['code'].'.');
                }
            }
            if (($values['mobile'] ?? null) !== null && ($problem = PhoneNumbers::problem($values['isd_code'] ?? null, (string) $values['mobile'])) !== null) {
                $validator->errors()->add('mobile', $problem);
            }
            if (($values['vendor_id'] ?? null) !== null) {
                $vendor = Vendor::query()->find($values['vendor_id']);
                $unchanged = (int) ($current['vendor_id'] ?? 0) === (int) $values['vendor_id'];
                if ($vendor === null) {
                    $validator->errors()->add('vendor_id', 'Choose a vendor of this organisation.');
                } elseif (! $vendor->isActive() && ! $unchanged) {
                    $validator->errors()->add('vendor_id', 'The vendor '.$vendor->name.' is inactive.');
                }
            }
        });
        $validator->validate();

        return array_intersect_key($values, array_flip(self::FIELDS));
    }
}
