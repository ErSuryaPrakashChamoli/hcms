<?php

namespace App\Filament\Support;

use App\Domain\Leave\Models\LeaveType;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/** Builds the settings form for a policy type from config('peopleos.policies.types'). */
final class PolicySettingsSchema
{
    public const STATE = 'settings';

    /** @return array<int, Component> */
    public static function components(string $type): array
    {
        $fields = config("peopleos.policies.types.{$type}.fields", []);

        if ($fields === []) {
            return [KeyValue::make(self::STATE)->label('Settings')->keyLabel('Setting')->valueLabel('Value')->columnSpanFull()];
        }

        return array_map(fn (array $f) => self::component($f, self::STATE.'.'), $fields);
    }

    private static function component(array $field, string $prefix = ''): Component
    {
        $name = $prefix.$field['key'];

        $component = match ($field['type']) {
            'number' => TextInput::make($name)->numeric(),
            'boolean' => Toggle::make($name),
            'select' => Select::make($name)->options(fn () => self::options($field)),
            'multiselect' => Select::make($name)->options(fn () => self::options($field))->multiple(),
            'repeater' => Repeater::make($name)
                ->schema(array_map(fn (array $f) => self::component($f), $field['fields'] ?? []))
                ->columns(3)
                ->collapsible()
                ->itemLabel(fn (array $state) => $state[$field['fields'][0]['key'] ?? ''] ?? null)
                ->columnSpanFull(),
            default => TextInput::make($name)->maxLength(255),
        };

        return $component->label($field['label']);
    }

    /** @return array<string, string> */
    private static function options(array $field): array
    {
        return match ($field['options_from'] ?? null) {
            'leave_types' => LeaveType::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get()->mapWithKeys(fn ($t) => [$t->code => "{$t->name} ({$t->code})"])->all(),
            default => $field['options'] ?? [],
        };
    }
}
