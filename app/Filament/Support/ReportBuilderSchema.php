<?php

namespace App\Filament\Support;

use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Payroll\Services\FormulaEngine;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use RuntimeException;

/** The report builder form (§84): Dataset → Fields → Filters → Grouping → Calculated → Visualization. */
final class ReportBuilderSchema
{
    /** @return array<int, Component> */
    public static function make(): array
    {
        $registry = app(DatasetRegistry::class);
        $fieldOptions = function (Get $get, bool $numericOnly = false) use ($registry): array {
            $dataset = $get('dataset', true);
            if (! $dataset) {
                return [];
            }
            $fields = $registry->get($dataset)->catalogue(auth()->user());
            $options = collect($fields)->filter(fn ($f) => ! $numericOnly || $f['type'] === 'number')->map(fn ($f) => $f['label'])->all();
            foreach ($get('definition.calculated', true) ?? [] as $calc) {
                if (! empty($calc['key'])) {
                    $options[strtolower($calc['key'])] = ($calc['label'] ?? $calc['key']).' (calculated)';
                }
            }

            return $options;
        };

        return [
            Section::make('Dataset & fields')->columns(1)->schema([
                Select::make('dataset')->label('Dataset')->required()->live()->options(fn () => $registry->options(auth()->user()))->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                CheckboxList::make('definition.fields')->label('Fields')->columns(3)->options(fn (Get $get) => $get('dataset') ? collect($registry->get($get('dataset'))->catalogue(auth()->user()))->map(fn ($f) => $f['label'])->all() : [])->required(),
            ]),
            Section::make('Filters')->schema([
                Repeater::make('definition.filters')->hiddenLabel()->columns(3)->default([])->schema([
                    Select::make('field')->options(fn (Get $get) => $fieldOptions($get))->getOptionLabelUsing(fn ($value) => (string) $value)->required(),
                    Select::make('operator')->options(config('peopleos.analytics.operators'))->default('equals')->required(),
                    TextInput::make('value')->placeholder('value · a,b,c for "any of" / between · N for last N days'),
                ]),
            ])->collapsed(fn (string $operation) => $operation === 'create'),
            Section::make('Grouping & aggregation')->columns(2)->schema([
                Select::make('definition.group_by')->label('Group by')->placeholder('No grouping (list rows)')->options(fn (Get $get) => $fieldOptions($get))->getOptionLabelUsing(fn ($value) => (string) $value)->live(),
                Repeater::make('definition.aggregations')->label('Aggregations (per group)')->columns(3)->default([['fn' => 'count']])->schema([
                    Select::make('fn')->options(config('peopleos.analytics.aggregations'))->default('count')->required()->live(),
                    Select::make('field')->options(fn (Get $get) => $fieldOptions($get, true))->getOptionLabelUsing(fn ($value) => (string) $value)->visible(fn (Get $get) => $get('fn') !== 'count')->required(fn (Get $get) => $get('fn') !== 'count'),
                    TextInput::make('label')->placeholder('Column label'),
                ])->visible(fn (Get $get) => filled($get('definition.group_by'))),
            ]),
            Section::make('Calculated fields')->schema([
                Repeater::make('definition.calculated')->hiddenLabel()->columns(3)->default([])->schema([
                    TextInput::make('key')->required()->alphaDash()->placeholder('cost_per_day'),
                    TextInput::make('label')->placeholder('Cost per day'),
                    TextInput::make('formula')->required()->placeholder('employer_cost / paid_days')
                        ->rule(fn () => function (string $attribute, $value, Closure $fail) {
                            try {
                                app(FormulaEngine::class)->validate((string) $value);
                            } catch (RuntimeException $e) {
                                $fail($e->getMessage());
                            }
                        }),
                ])->helperText('Numeric fields by key, e.g. gross, net_pay, paid_days; functions min, max, round, if.'),
            ])->collapsed(),
            Section::make('Visualization & order')->columns(4)->schema([
                Select::make('definition.visualization.type')->label('Show as')->options(config('peopleos.analytics.visualizations'))->default('table')->required(),
                Select::make('definition.visualization.x')->label('Chart axis / label')->placeholder('Auto')->options(fn (Get $get) => $fieldOptions($get))->getOptionLabelUsing(fn ($value) => (string) $value),
                Select::make('definition.visualization.y')->label('Chart value')->placeholder('Auto (numeric columns)')->options(fn (Get $get) => $fieldOptions($get, true) + collect($get('definition.aggregations') ?? [])->filter(fn ($a) => ! empty($a['fn']))->mapWithKeys(fn ($a) => [$a['fn'] === 'count' ? 'count' : $a['fn'].'_'.($a['field'] ?? '') => $a['label'] ?? ($a['fn'] === 'count' ? 'Count' : ucfirst($a['fn']).' of '.($a['field'] ?? ''))])->all())->getOptionLabelUsing(fn ($value) => (string) $value),
                TextInput::make('definition.limit')->label('Row limit')->numeric()->minValue(0)->placeholder('All'),
                Select::make('definition.sort.field')->label('Sort by')->placeholder('Dataset order')->options(fn (Get $get) => $fieldOptions($get) + ['count' => 'Count'])->getOptionLabelUsing(fn ($value) => (string) $value),
                Select::make('definition.sort.dir')->label('Direction')->options(['asc' => 'Ascending', 'desc' => 'Descending'])->default('asc'),
            ]),
        ];
    }
}
