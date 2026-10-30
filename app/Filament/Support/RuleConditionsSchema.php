<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/** IF … THEN builder for policy assignment rules (§26, §43). */
final class RuleConditionsSchema
{
    public static function repeater(): Repeater
    {
        $fields = config('peopleos.rules.fields');

        return Repeater::make('conditions')
            ->label('Conditions')
            ->schema([
                Select::make('field')
                    ->options(collect($fields)->map(fn ($f) => $f['label'])->all())
                    ->required()
                    ->live(),
                Select::make('operator')
                    ->options(config('peopleos.rules.operators'))
                    ->required()
                    ->default('equals')
                    ->live(),
                Select::make('value')
                    ->label('Value')
                    ->options(fn (Get $get) => self::optionsFor($get('field')))
                    ->multiple(fn (Get $get) => in_array($get('operator'), ['in', 'not_in'], true))
                    ->searchable()
                    ->visible(fn (Get $get) => self::hasOptions($get('field')) && ! in_array($get('operator'), ['is_empty', 'is_not_empty'], true))
                    ->required(fn (Get $get) => self::hasOptions($get('field')) && ! in_array($get('operator'), ['is_empty', 'is_not_empty'], true)),
                TextInput::make('value')
                    ->label('Value')
                    ->visible(fn (Get $get) => filled($get('field')) && ! self::hasOptions($get('field')) && ! in_array($get('operator'), ['is_empty', 'is_not_empty'], true))
                    ->required(fn (Get $get) => filled($get('field')) && ! self::hasOptions($get('field')) && ! in_array($get('operator'), ['is_empty', 'is_not_empty'], true)),
            ])
            ->columns(3)
            ->minItems(1)
            ->itemLabel(fn (array $state) => isset($state['field']) ? ($fields[$state['field']]['label'] ?? $state['field']).' '.(config('peopleos.rules.operators')[$state['operator'] ?? ''] ?? '') : null)
            ->columnSpanFull();
    }

    public static function hasOptions(?string $field): bool
    {
        $type = config("peopleos.rules.fields.{$field}.type");

        return in_array($type, ['model', 'enum', 'options', 'employee'], true);
    }

    /** @return array<string|int, string> */
    public static function optionsFor(?string $field): array
    {
        $definition = config("peopleos.rules.fields.{$field}");

        return match ($definition['type'] ?? null) {
            'model' => $definition['model']::query()->orderBy('name')->pluck('name', 'id')->all(),
            'enum' => collect($definition['enum']::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all(),
            'options' => config('peopleos.'.$definition['options_key'], []),
            'employee' => self::employees(),
            default => [],
        };
    }

    /**
     * Employees as "code · name", by id: all of them (capped) for a picker, or only the ids given (to label stored
     * conditions).
     *
     * @param  list<int|string>|null  $ids
     * @return array<int, string>
     */
    public static function employees(?array $ids = null, ?string $search = null): array
    {
        $term = '%'.trim((string) $search).'%';

        return Employee::query()->with('person')
            ->when($ids !== null, fn ($q) => $q->whereKey($ids))
            ->when(filled($search), fn ($q) => $q->where(fn ($q) => $q->where('employee_code', 'like', $term)
                ->orWhereHas('person', fn ($p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('preferred_name', 'like', $term))))
            ->orderBy('employee_code')->limit($ids === null ? 500 : count($ids))->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->employee_code.' · '.$e->person?->full_name])
            ->all();
    }

    /** Human-readable rendering of stored conditions. */
    public static function describe(array $conditions, string $match = 'all'): string
    {
        $fields = config('peopleos.rules.fields');
        $operators = config('peopleos.rules.operators');

        $parts = array_map(function (array $c) use ($fields, $operators) {
            $label = $fields[$c['field']]['label'] ?? $c['field'];
            $op = $operators[$c['operator']] ?? $c['operator'];
            $options = ($fields[$c['field']]['type'] ?? null) === 'employee' ? self::employees(array_values((array) ($c['value'] ?? []))) : self::optionsFor($c['field']);
            $values = array_map(fn ($v) => $options[$v] ?? $v, (array) ($c['value'] ?? []));

            return trim("{$label} {$op} ".implode(', ', $values));
        }, $conditions);

        return implode($match === 'any' ? ' OR ' : ' AND ', $parts);
    }
}
