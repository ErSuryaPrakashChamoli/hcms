<?php

namespace App\Domain\ComplianceReports\Builders;

use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;

/**
 * Annual report of the Internal Committee (sexual harassment at the workplace) as counts only: complaints received,
 * disposed of, pending at the year end and pending beyond ninety days. No complainant, respondent or case detail ever
 * appears.
 *
 * PoSH cases are confidential to the Internal Committee (Grievances::canAccess): the counts are produced only for a
 * member of the category's handler roles, or — where no handler role is configured yet — for a grievance manager, the
 * same people who may open the cases. Anyone else gets the report without figures. For those allowed, every case of
 * the category is counted, whatever their organisation scope, because the committee reports for the whole employer.
 */
final class PoshAnnualReport extends Builder
{
    public function build(ReportContext $context): array
    {
        $period = $context->period;
        $categories = GrievanceCategory::query()->whereIn('code', $context->definition->options['category_codes'] ?? ['POSH'])->get();
        $allowed = $categories->filter(fn (GrievanceCategory $c) => $this->mayCount($context->user, $c));
        $columns = ['item' => 'Particular', 'count' => 'Number'];
        $notes = ['The Internal Committee submits the report to the employer and the District Officer.'];

        if ($categories->isEmpty()) {
            return [$this->sheet($context, $columns, $this->rows(null, $period), ['notes' => ['No grievance category with code POSH exists, so no cases are counted.', ...$notes]])];
        }
        if ($allowed->count() < $categories->count()) {
            return [$this->sheet($context, $columns, $this->rows(null, $period), [
                'notes' => ['PoSH cases are confidential to the Internal Committee: the counts are shown only to members of the PoSH category\'s handler roles.', ...$notes],
                'warnings' => ['Counts withheld: the person generating the report is not a handler of the PoSH category.'],
            ])];
        }

        $to = $period->end->endOfDay();
        $cases = AccessScope::withoutScoping(fn () => Grievance::query()->whereIn('grievance_category_id', $allowed->modelKeys())->where('created_at', '<=', $to)->get(['id', 'status', 'created_at', 'resolved_at', 'closed_at']));

        return [$this->sheet($context, $columns, $this->rows($cases, $period), ['notes' => ['Counted from the PoSH grievance category (all cases).', ...$notes]])];
    }

    private function mayCount(?User $user, GrievanceCategory $category): bool
    {
        if ($user === null) {
            return false;
        }
        $handlerRoles = $category->handler_role_ids ?? [];

        return $handlerRoles !== []
            ? $user->roles()->whereIn('roles.id', $handlerRoles)->exists()
            : $user->hasPermission('grievance.manage');
    }

    /** @return list<array{item: string, count: int|string}> */
    private function rows($cases, $period): array
    {
        $count = fn (callable $filter) => $cases === null ? '' : $cases->filter($filter)->count();
        $from = $period->start->startOfDay();
        $to = $period->end->endOfDay();
        $done = fn (Grievance $g) => $g->resolved_at ?? $g->closed_at;
        $pending = fn (Grievance $g) => ($d = $done($g)) === null || $d->gt($to);

        return [
            ['item' => 'Complaints pending at the start of the year', 'count' => $count(fn ($g) => $g->created_at->lt($from) && (($d = $done($g)) === null || $d->gte($from)))],
            ['item' => 'Complaints received during the year', 'count' => $count(fn ($g) => $g->created_at->gte($from))],
            ['item' => 'Complaints disposed of during the year', 'count' => $count(fn ($g) => ($d = $done($g)) !== null && $d->between($from, $to))],
            ['item' => 'Complaints pending at the end of the year', 'count' => $count($pending)],
            ['item' => '— of which pending for more than 90 days', 'count' => $count(fn ($g) => $pending($g) && $g->created_at->diffInDays($to) > 90)],
            ['item' => 'Workshops or awareness programmes held', 'count' => ''],
            ['item' => 'Nature of action taken by the employer', 'count' => ''],
        ];
    }
}
