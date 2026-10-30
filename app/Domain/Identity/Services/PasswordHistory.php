<?php

namespace App\Domain\Identity\Services;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * HROne setup parity (Security → Password policy, "restrict recently used passwords"): the hashes of a tenant user's
 * recent passwords, kept only while the tenant restricts reuse (security.password_history_count > 0) and only as many
 * as it restricts. Rows are written when a password is saved (User) and read when one is chosen (PasswordPolicy), always
 * for one user of one tenant. They are never audited or exported (the password change itself is audited); the table is
 * `password_histories` (DataInventory: identity).
 */
final class PasswordHistory
{
    public const MAX = 24;

    public function __construct(private readonly TenantContext $tenants, private readonly SecurityPolicy $policy) {}

    /** Called when a tenant user's password was saved: keeps its hash while the tenant restricts reuse. */
    public function record(User $user): void
    {
        $hash = $user->getAttributes()['password'] ?? null;
        if ($user->tenant_id === null || ! is_string($hash) || $hash === '') {
            return;
        }
        $this->inTenant($user, function () use ($user, $hash): void {
            $keep = $this->policy->passwordHistoryCount();
            if ($keep === 0) {
                return;
            }
            DB::table('password_histories')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->getKey(), 'password' => $hash, 'created_at' => now()]);
            $kept = DB::table('password_histories')->where('tenant_id', $user->tenant_id)->where('user_id', $user->getKey())
                ->orderByDesc('id')->limit($keep)->pluck('id')->all();
            DB::table('password_histories')->where('tenant_id', $user->tenant_id)->where('user_id', $user->getKey())->whereNotIn('id', $kept)->delete();
        });
    }

    /**
     * Whether $password is the current password or one of the recent ones the tenant's policy refuses. Run inside the
     * user's tenant (PasswordPolicy binds it).
     */
    public function wasRecentlyUsed(User $user, #[SensitiveParameter] string $password): bool
    {
        $count = $this->policy->passwordHistoryCount();
        if ($user->tenant_id === null || $count === 0) {
            return false;
        }
        $current = $user->getAttributes()['password'] ?? null;
        $hashes = DB::table('password_histories')->where('tenant_id', $user->tenant_id)->where('user_id', $user->getKey())
            ->orderByDesc('id')->limit($count)->pluck('password')->all();
        // The newest row is the current password when it was saved under this policy; otherwise the current password
        // counts as one of the N on top of the older rows.
        if (is_string($current) && $current !== '' && ($hashes[0] ?? null) !== $current) {
            $hashes = [$current, ...array_slice($hashes, 0, $count - 1)];
        }
        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    private function inTenant(User $user, Closure $work): void
    {
        if ($this->tenants->id() === $user->tenant_id) {
            $work();

            return;
        }
        $tenant = Tenant::query()->find($user->tenant_id);
        if ($tenant !== null) {
            $this->tenants->runAs($tenant, $work);
        }
    }
}
