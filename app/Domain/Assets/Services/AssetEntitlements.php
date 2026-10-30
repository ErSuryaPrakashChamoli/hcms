<?php

namespace App\Domain\Assets\Services;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetCategory;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * HROne setup parity AS-B (Asset → Asset policy): what an employee group is entitled to, read from the employee's
 * `asset` policy on a date —
 *
 * - entitlements: asset type, optional sub type and product, quantity — raised as asset requests when the employee joins
 *   (provisionJoiner, due asset.onboarding.due_days after joining);
 * - request_scope: `any` (default) or `mapped` (employees and managers may request only entitled assets);
 * - hr_assign_scope: `any` (default, as before), `warn` (an assignment outside the entitlement is recorded as such) or
 *   `mapped` (refused) — checked by Assets::assign.
 *
 * No asset policy: no entitlements, anything may be requested and assigned (as before).
 */
final class AssetEntitlements
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /**
     * @return array{policy_version_id: ?int, entitlements: list<array{category: int, subtype: ?int, model: ?int, quantity: int}>, request_scope: string, hr_assign_scope: string}
     */
    public function for(Employee $employee, CarbonInterface|string|null $date = null): array
    {
        $version = $this->policies->resolve('asset', $employee, $date ?? now());
        $id = fn (mixed $v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
        $entitlements = [];
        foreach ((array) ($version?->setting('entitlements', []) ?? []) as $row) {
            if (is_array($row) && ($category = $id($row['category'] ?? null)) !== null) {
                $entitlements[] = ['category' => $category, 'subtype' => $id($row['subtype'] ?? null), 'model' => $id($row['model'] ?? null),
                    'quantity' => max(1, min(10, (int) ($id($row['quantity'] ?? null) ?? 1)))];
            }
        }
        $choice = fn (string $key, array $allowed, string $default) => in_array($version?->setting($key), $allowed, true) ? (string) $version->setting($key) : $default;

        return [
            'policy_version_id' => $version?->id,
            'entitlements' => $entitlements,
            'request_scope' => $choice('request_scope', ['any', 'mapped'], 'any'),
            'hr_assign_scope' => $choice('hr_assign_scope', ['any', 'warn', 'mapped'], 'any'),
        ];
    }

    /**
     * Whether an entitlement covers an asset type, sub type and product. A request that leaves the sub type or product
     * open ($open) is covered by an entitlement naming one; an asset is matched exactly.
     *
     * @param  list<array{category: int, subtype: ?int, model: ?int, quantity: int}>  $entitlements
     */
    public static function covers(array $entitlements, int $categoryId, ?int $subtypeId, ?int $modelId, bool $open = false): bool
    {
        foreach ($entitlements as $e) {
            if ($e['category'] === $categoryId
                && ($e['subtype'] === null || $e['subtype'] === $subtypeId || ($open && $subtypeId === null))
                && ($e['model'] === null || $e['model'] === $modelId || ($open && $modelId === null))) {
                return true;
            }
        }

        return false;
    }

    /** Why the employee may not request this asset (request_scope = mapped and not entitled), or null. */
    public function requestRefusal(Employee $employee, int $categoryId, ?int $subtypeId, ?int $modelId): ?string
    {
        $rules = $this->for($employee);
        if ($rules['request_scope'] !== 'mapped' || self::covers($rules['entitlements'], $categoryId, $subtypeId, $modelId, true)) {
            return null;
        }
        $name = AssetCategory::query()->whereKey($categoryId)->value('name') ?? 'This asset';

        return "{$name} is not among the assets your asset policy maps to you: you can request only those.";
    }

    /**
     * Assets::assign: `any` (no check), `warn` (allowed; the caller records it) or `mapped` (refused) when the asset is
     * outside the employee's entitlement.
     *
     * @return array{mode: string, outside: bool, message: ?string}
     */
    public function assignmentCheck(Employee $employee, Asset $asset): array
    {
        $rules = $this->for($employee);
        if ($rules['hr_assign_scope'] === 'any') {
            return ['mode' => 'any', 'outside' => false, 'message' => null];
        }
        $attributes = $asset->getAttributes();
        $outside = ! self::covers($rules['entitlements'], (int) $attributes['asset_category_id'], isset($attributes['asset_subtype_id']) ? (int) $attributes['asset_subtype_id'] : null,
            isset($attributes['asset_model_id']) ? (int) $attributes['asset_model_id'] : null);

        return ['mode' => $rules['hr_assign_scope'], 'outside' => $outside,
            'message' => $outside ? "{$asset->name} [{$asset->asset_tag}] is not among the assets the employee's asset policy maps to them." : null];
    }

    /**
     * A new joiner's entitled assets, raised once each as asset requests to the asset types' owner roles (AssetRequests).
     * Returns how many were raised.
     */
    public function provisionJoiner(Employee $employee, ?User $actor = null): int
    {
        $rules = $this->for($employee, $employee->joining_date ?? now());
        if ($rules['entitlements'] === []) {
            return 0;
        }

        return app(AssetRequests::class)->raiseForJoiner($employee, $rules['entitlements'], $actor);
    }
}
