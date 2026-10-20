<?php

namespace App\Domain\Enterprise\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use RuntimeException;

/** SCIM 2.0 user provisioning (§110): create, read, replace, patch (active), deactivate. Users only; groups are not mapped. */
final class Scim
{
    public const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public const SCHEMA_LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public const SCHEMA_PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    public function __construct(private readonly TenantContext $tenants, private readonly AuditRecorder $audit) {}

    public function resource(User $user): array
    {
        $employee = Employee::query()->where('user_id', $user->id)->first();
        [$given, $family] = array_pad(explode(' ', $user->name, 2), 2, '');

        return [
            'schemas' => [self::SCHEMA_USER],
            'id' => (string) $user->id,
            'externalId' => $user->external_id,
            'userName' => $user->email,
            'name' => ['givenName' => $given, 'familyName' => $family, 'formatted' => $user->name],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'primary' => true, 'type' => 'work']],
            'active' => $user->isActive(),
            'employeeNumber' => $employee?->employee_code,
            'meta' => ['resourceType' => 'User', 'created' => $user->created_at?->toIso8601String(), 'lastModified' => $user->updated_at?->toIso8601String(), 'location' => url('/api/scim/v2/Users/'.$user->id)],
        ];
    }

    /** @return array{resources: array<int, array>, total: int} */
    public function list(?string $filter, int $startIndex = 1, int $count = 100): array
    {
        $query = User::forCurrentTenant()->orderBy('id');
        if ($filter && preg_match('/userName\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            $query->where('email', strtolower($m[1]));
        } elseif ($filter && preg_match('/externalId\s+eq\s+"([^"]+)"/i', $filter, $m)) {
            $query->where('external_id', $m[1]);
        }
        $total = (clone $query)->count();
        $users = $query->skip(max(0, $startIndex - 1))->take(min($count, 200))->get();

        return ['resources' => $users->map(fn (User $u) => $this->resource($u))->all(), 'total' => $total];
    }

    public function create(array $payload): User
    {
        $email = strtolower((string) ($payload['userName'] ?? data_get($payload, 'emails.0.value') ?? ''));
        if ($email === '') {
            throw new RuntimeException('userName is required.');
        }
        $this->assertEmailAvailable($email);
        $name = trim((string) ($payload['displayName'] ?? trim((data_get($payload, 'name.givenName', '')).' '.data_get($payload, 'name.familyName', '')))) ?: Str::before($email, '@');

        $user = User::create(['tenant_id' => $this->tenants->id(), 'name' => $name, 'email' => $email, 'password' => Str::random(40), 'status' => ($payload['active'] ?? true) ? UserStatus::Active : UserStatus::Suspended, 'external_id' => $payload['externalId'] ?? null]);
        $this->link($user, $payload);
        $this->audit->record(AuditAction::Create, 'enterprise', $user, [], 'SCIM provisioning', metadata: ['scim' => true]);

        return $user;
    }

    public function replace(User $user, array $payload): User
    {
        $email = strtolower((string) ($payload['userName'] ?? $user->email));
        $this->assertEmailAvailable($email, $user);
        $name = trim((string) ($payload['displayName'] ?? trim((data_get($payload, 'name.givenName', '')).' '.data_get($payload, 'name.familyName', '')))) ?: $user->name;
        $user->forceFill(['email' => $email, 'name' => $name, 'external_id' => $payload['externalId'] ?? $user->external_id, 'status' => ($payload['active'] ?? $user->isActive()) ? UserStatus::Active : UserStatus::Suspended])->save();
        $this->link($user, $payload);
        $this->audit->record(AuditAction::Update, 'enterprise', $user, [], 'SCIM replace', metadata: ['scim' => true]);

        return $user;
    }

    /** Supports replace/add on active, userName, displayName, name.givenName, name.familyName, externalId. */
    public function patch(User $user, array $operations): User
    {
        $changes = [];
        foreach ($operations as $op) {
            $type = strtolower((string) ($op['op'] ?? ''));
            if (! in_array($type, ['replace', 'add'], true)) {
                continue;
            }
            $path = $op['path'] ?? null;
            $value = $op['value'] ?? null;
            $pairs = $path ? [$path => $value] : (is_array($value) ? $value : []);
            foreach ($pairs as $key => $v) {
                $key = strtolower(str_replace('"', '', (string) $key));
                match (true) {
                    $key === 'active' => $changes['status'] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? UserStatus::Active : UserStatus::Suspended,
                    $key === 'username' => $changes['email'] = strtolower((string) $v),
                    $key === 'displayname' => $changes['name'] = (string) $v,
                    $key === 'externalid' => $changes['external_id'] = (string) $v,
                    $key === 'name.givenname' => $changes['name'] = trim($v.' '.Str::after($user->name, ' ')),
                    $key === 'name.familyname' => $changes['name'] = trim(Str::before($user->name, ' ').' '.$v),
                    default => null,
                };
            }
        }
        if (isset($changes['email'])) {
            $this->assertEmailAvailable($changes['email'], $user);
        }
        if ($changes !== []) {
            $user->forceFill($changes)->save();
            $this->audit->record(AuditAction::Update, 'enterprise', $user, array_map(fn ($k, $v) => ['field' => $k, 'before' => null, 'after' => $v instanceof \BackedEnum ? $v->value : $v], array_keys($changes), $changes), 'SCIM patch', metadata: ['scim' => true]);
        }

        return $user->refresh();
    }

    /**
     * Phase 14: logins are unique platform-wide. A userName used anywhere (this tenant or another) is
     * refused with 409 and the same message, so the response never says which organisation holds it.
     * Before, another tenant's address surfaced as a 500.
     */
    private function assertEmailAvailable(string $email, ?User $except = null): void
    {
        if (User::query()->where('email', $email)->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists()) {
            throw new RuntimeException('This userName is not available.', 409);
        }
    }

    public function deactivate(User $user): void
    {
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->audit->record(AuditAction::Update, 'enterprise', $user, [['field' => 'status', 'before' => 'active', 'after' => 'suspended']], 'SCIM delete', metadata: ['scim' => true]);
    }

    /** Link the login to an employee by employeeNumber or work email when one exists without a login. */
    private function link(User $user, array $payload): void
    {
        $number = $payload['employeeNumber'] ?? data_get($payload, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.employeeNumber');
        $employee = Employee::query()->whereNull('user_id')->where(fn ($q) => $q->when($number, fn ($s) => $s->where('employee_code', $number))->orWhere('work_email', $user->email))->first();
        $employee?->update(['user_id' => $user->id]);
    }
}
