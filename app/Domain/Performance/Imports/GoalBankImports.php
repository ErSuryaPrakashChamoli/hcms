<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-35 ("Goal bank", HROne Import → Performance; matrix 27 §27.10 #7): the ready-made goals people pick from —
 * PeopleOS's KRA library (Performance → KRA library: a KRA with its suggested KPIs, perspective, unit and weight), keyed by
 * code. A new code creates the KRA, a code on file updates it (through the Configuration Change Centre when the organisation
 * makes the library wait for approval); blank cells keep what is on file, and a KPIs cell replaces the suggested KPIs.
 */
final class GoalBankImports extends MasterImports
{
    public const TYPE = 'goal_bank';

    public static function fields(): array
    {
        return ['code' => ['Code', true], 'name' => ['Name', true], 'category' => ['Category', false], 'default_weight' => ['Default weight (%)', false],
            'perspective' => ['Perspective code', false], 'description' => ['Description', false], 'kpis' => ['Suggested KPIs', false], 'status' => ['Status', false]];
    }

    public function label(): string
    {
        return 'Goal bank upload';
    }

    protected function module(): string
    {
        return 'performance';
    }

    protected function model(): string
    {
        return Kra::class;
    }

    protected function aliases(): array
    {
        return ['kra_code' => 'code', 'goal_code' => 'code', 'kra' => 'name', 'kra_name' => 'name', 'goal' => 'name', 'goal_name' => 'name', 'weight' => 'default_weight',
            'weightage' => 'default_weight', 'kpi' => 'kpis'];
    }

    protected function attributes(array $r, array &$context, ?Model $existing, array &$errors): array
    {
        $attributes = [];
        if ($existing === null && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) ($r['code'] ?? ''))) {
            $errors[] = 'Code is up to 32 letters, digits, dashes or underscores.';
        }
        if (isset($r['name'])) {
            mb_strlen($r['name']) > 255 ? $errors[] = 'Name is at most 255 characters.' : $attributes['name'] = $r['name'];
        }
        if (isset($r['category'])) {
            mb_strlen($r['category']) > 64 ? $errors[] = 'Category is at most 64 characters.' : $attributes['category'] = $r['category'];
        }
        if (isset($r['default_weight'])) {
            is_numeric($r['default_weight']) && (float) $r['default_weight'] >= 0 && (float) $r['default_weight'] <= 100 && (float) $r['default_weight'] == (int) $r['default_weight']
                ? $attributes['default_weight'] = (int) $r['default_weight'] : $errors[] = 'Default weight is a whole number from 0 to 100.';
        }
        if (isset($r['perspective'])) {
            $context['perspectives'] ??= PerformanceLookup::query()->ofKind('perspective')->where('status', 'active')->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
            $id = $context['perspectives'][mb_strtolower($r['perspective'])] ?? null;
            $id === null ? $errors[] = "Perspective code '{$r['perspective']}' is not an active perspective (Performance → Masters)." : $attributes['perspective_id'] = $id;
        }
        if (isset($r['description'])) {
            $attributes['description'] = $r['description'];
        }
        if (isset($r['kpis'])) {
            $attributes['kpis'] = $this->kpis($r['kpis'], $context, $errors);
        }
        $status = $this->status($r['status'] ?? null, $errors);
        if ($status !== null) {
            $attributes['status'] = $status;
        }

        return $attributes;
    }

    /**
     * "Name | measure | target | unit code; …" as the library keeps KPIs. @param list<string> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function kpis(string $cell, array &$context, array &$errors): array
    {
        $context['units'] ??= PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower((string) $c) => (int) $id])->all();
        $kpis = [];
        foreach (array_filter(array_map('trim', explode(';', $cell))) as $item) {
            $parts = array_map('trim', explode('|', $item));
            if (($parts[0] ?? '') === '' || count($parts) > 4) {
                $errors[] = "Suggested KPI '{$item}' must be: name | measure | target | unit code (only the name is needed).";

                continue;
            }
            $unit = null;
            if (($parts[3] ?? '') !== '') {
                $unit = $context['units'][mb_strtolower($parts[3])] ?? null;
                if ($unit === null) {
                    $errors[] = "Unit code '{$parts[3]}' is not an active unit of measurement (Performance → Masters).";
                }
            }
            $kpis[] = ['name' => mb_substr($parts[0], 0, 255), 'measure' => ($parts[1] ?? '') !== '' ? $parts[1] : null, 'target' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
                'unit_id' => $unit, 'kpi_rating_formula_id' => null];
        }

        return $kpis;
    }

    public function format(): array
    {
        $unit = PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('id')->value('code') ?? 'PCT';

        return [
            'columns' => [
                ['Code', 'Yes', 'The ready-made goal\'s (KRA\'s) code, up to 32 letters, digits, dashes or underscores; it never changes.', 'KRA-SALES'],
                ['Name', 'Yes', 'The goal people pick (needed for a new one).', 'Grow revenue'],
                ['Category', 'No', 'A grouping of your own (up to 64 characters).', 'Business'],
                ['Default weight (%)', 'No', 'The weight it starts with on a goal sheet, 0 to 100.', '30'],
                ['Perspective code', 'No', 'A perspective (Performance → Masters → Perspective), e.g. for a balanced scorecard.', ''],
                ['Description', 'No', 'What the goal is about.', 'New and repeat business in the region'],
                ['Suggested KPIs', 'No', 'KPIs separated by ";", each "name | measure | target | unit code" (only the name is needed). Replaces the KPIs on file.', "Revenue growth | % over last year | 15 | {$unit}; New accounts | count | 12"],
                ['Status', 'No', 'active or inactive; a new one is active.', 'active'],
            ],
            'notes' => [
                'Rows are matched on Code: a code on file updates that goal, any other code creates one. A code may appear once per file.',
                'Blank cells keep what is on file; a Suggested KPIs cell replaces all of its suggested KPIs.',
            ],
        ];
    }

    protected function examples(): array
    {
        $unit = PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('id')->value('code');

        return [
            ['KRA-SALES', 'Grow revenue', 'Business', '30', '', 'New and repeat business in the region', 'Revenue growth | % over last year | 15'.($unit ? " | {$unit}" : '').'; New accounts | count | 12', 'active'],
            ['KRA-PEOPLE', 'Build the team', 'People', '20', '', 'Hiring, coaching and retention', 'Team attrition | % a year | 8; Coaching sessions held', 'active'],
        ];
    }
}
