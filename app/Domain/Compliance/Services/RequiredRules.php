<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\CompanyStatutoryProfile;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Organisation\Models\Establishment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 6 §9: the rule versions this tenant actually needs, derived from its active establishments,
 * their statutory profiles (or the legacy company profile) and their states — so verification work
 * is scoped to real configuration instead of every jurisdiction in the packs.
 */
final class RequiredRules
{
    public function __construct(private readonly ComplianceRules $rules) {}

    /** @return Collection<int, array{statute: string, code: string, state: ?string, establishments: list<string>, rule: ?ComplianceRule, status: string, notice: ?string}> */
    public function on(CarbonInterface|string|null $date = null): Collection
    {
        $day = Carbon::parse($date ?? now())->toDateString();
        $needs = [];

        foreach (Establishment::query()->where('status', 'active')->effectiveOn($day)->orderBy('name')->get() as $establishment) {
            $profiles = EstablishmentStatutoryProfile::query()->where('establishment_id', $establishment->id)->effectiveOn($day)->get()->keyBy('statute');
            $legacy = $profiles->isEmpty() ? CompanyStatutoryProfile::query()->where('company_id', $establishment->company_id)->first() : null;
            $applies = fn (string $statute) => $profiles->isNotEmpty()
                ? (bool) $profiles->get($statute)?->applicable
                : (bool) ($legacy?->getAttribute(['EPF' => 'pf_applicable', 'ESI' => 'esi_applicable', 'PT' => 'pt_applicable', 'LWF' => 'lwf_applicable', 'TDS' => 'tds_applicable'][$statute]) ?? false);

            foreach (['EPF', 'ESI', 'PT', 'LWF', 'TDS'] as $statute) {
                if (! $applies($statute)) {
                    continue;
                }
                $state = in_array($statute, ['PT', 'LWF'], true) ? ($establishment->state ?? ($statute === 'PT' ? $legacy?->pt_state : ($legacy?->lwf_state ?: $legacy?->pt_state))) : null;
                $key = $statute.'|'.$state;
                $needs[$key] ??= ['statute' => $statute, 'code' => $statute, 'state' => $state, 'establishments' => []];
                $needs[$key]['establishments'][] = $establishment->name;
            }
        }

        return collect($needs)->map(function (array $need) use ($day) {
            $rule = ($need['state'] === null && in_array($need['statute'], ['PT', 'LWF'], true)) ? null : $this->rules->resolve($need['code'], $day, $need['state']);
            $notice = $rule ? $this->rules->pendingNotice($rule, $day) : null;

            return $need + [
                'rule' => $rule,
                'status' => match (true) {
                    $rule === null => 'missing',
                    $notice !== null => 'notice_open',
                    default => $rule->verification_status,
                },
                'notice' => $notice?->title,
            ];
        })->values();
    }
}
