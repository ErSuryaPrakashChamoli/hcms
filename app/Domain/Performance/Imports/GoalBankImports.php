<?php

namespace App\Domain\Performance\Imports;

use App\Domain\Employment\Imports\MasterImports;
use App\Domain\Performance\Models\Kra;
use App\Domain\Performance\Models\PerformanceLookup;
use App\Domain\Performance\Models\PerformanceUnit;
use App\Support\Imports\ReferenceGuide;
use Illuminate\Database\Eloquent\Model;

/**
 * Data import DI-35 ("Goal bank", HROne Import → Performance; matrix 27 §27.10 #7): the ready-made goals people pick from —
 * PeopleOS's KRA library (Performance → KRA library: a KRA with its suggested KPIs, perspective, unit and weight), keyed by
 * code. A new code creates the KRA, a code on file updates it (through the Configuration Change Centre when the organisation
 * makes the library wait for approval); blank cells keep what is on file, and a KPIs cell replaces the suggested KPIs.
 * Names first: the code may be left blank (the KRA of that name is changed, or one is added with a code made from the
 * name); the perspective and the KPIs' units are given by name or code.
 */
final class GoalBankImports extends MasterImports
{
    public const TYPE = 'goal_bank';

    public static function fields(): array
    {
        return ['code' => ['Code', false], 'name' => ['Name', true], 'category' => ['Category', false], 'default_weight' => ['Default weight (%)', false],
            'perspective' => ['Perspective', false], 'description' => ['Description', false], 'kpis' => ['Suggested KPIs', false], 'status' => ['Status', false]];
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
            'weightage' => 'default_weight', 'kpi' => 'kpis', 'perspective_code' => 'perspective'];
    }

    protected function references(): array
    {
        return ['perspective' => 'perspective'];
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
            $id === null ? $errors[] = "Perspective '{$r['perspective']}' is not an active perspective (Performance → Masters)." : $attributes['perspective_id'] = $id;
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
     * "Name | measure | target | unit; …" as the library keeps KPIs (the unit by its name or code). @param list<string> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function kpis(string $cell, array &$context, array &$errors): array
    {
        if (! isset($context['units'])) {
            // Names first: a unit by its code or its name (codes first; a name two units share is left out — use the code).
            $units = PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->get(['id', 'code', 'name']);
            $context['units'] = $units->mapWithKeys(fn (PerformanceUnit $u) => [mb_strtolower((string) $u->code) => (int) $u->id])->all()
                + $units->groupBy(fn (PerformanceUnit $u) => mb_strtolower(trim((string) $u->name)))->filter(fn ($same) => $same->count() === 1)
                    ->map(fn ($same) => (int) $same->first()->id)->all();
        }
        $kpis = [];
        foreach (array_filter(array_map('trim', explode(';', $cell))) as $item) {
            $parts = array_map('trim', explode('|', $item));
            if (($parts[0] ?? '') === '' || count($parts) > 4) {
                $errors[] = "Suggested KPI '{$item}' must be: name | measure | target | unit (only the name is needed).";

                continue;
            }
            $unit = null;
            if (($parts[3] ?? '') !== '') {
                $unit = $context['units'][mb_strtolower($parts[3])] ?? null;
                if ($unit === null) {
                    $errors[] = "Unit '{$parts[3]}' is not an active unit of measurement (Performance → Masters).";
                }
            }
            $kpis[] = ['name' => mb_substr($parts[0], 0, 255), 'measure' => ($parts[1] ?? '') !== '' ? $parts[1] : null, 'target' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
                'unit_id' => $unit, 'kpi_rating_formula_id' => null];
        }

        return $kpis;
    }

    public function format(): array
    {
        $unit = PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('id')->value('name') ?? 'Percent';

        return [
            'columns' => [
                ['Code', 'No', ReferenceGuide::ownCode('ready-made goal'), ''],
                ['Name', 'Yes', 'The goal people pick (needed for a new one).', 'Grow revenue'],
                ['Category', 'No', 'A grouping of your own (up to 64 characters).', 'Business'],
                ['Default weight (%)', 'No', 'The weight it starts with on a goal sheet, 0 to 100.', '30'],
                ['Perspective', 'No', ReferenceGuide::name('perspective', 'Performance → Masters → Perspective', 'E.g. for a balanced scorecard.'), ''],
                ['Description', 'No', 'What the goal is about.', 'New and repeat business in the region'],
                ['Suggested KPIs', 'No', 'KPIs separated by ";", each "name | measure | target | unit" (only the name is needed; the unit by its name or code, Performance → Masters). Replaces the KPIs on file.', "Revenue growth | % over last year | 15 | {$unit}; New accounts | count | 12"],
                ['Status', 'No', 'active or inactive; a new one is active.', 'active'],
            ],
            'notes' => [
                ReferenceGuide::OWN_CODE_NOTE,
                ReferenceGuide::NOTE,
                'A code may appear once per file; the code itself never changes.',
                'Blank cells keep what is on file; a Suggested KPIs cell replaces all of its suggested KPIs.',
            ],
        ];
    }

    protected function examples(): array
    {
        $unit = PerformanceUnit::query()->where('status', 'active')->where('is_admin', true)->orderBy('id')->value('name');

        return [
            ['', 'Grow revenue', 'Business', '30', '', 'New and repeat business in the region', 'Revenue growth | % over last year | 15'.($unit ? " | {$unit}" : '').'; New accounts | count | 12', 'active'],
            ['', 'Build the team', 'People', '20', '', 'Hiring, coaching and retention', 'Team attrition | % a year | 8; Coaching sessions held', 'active'],
        ];
    }
}
