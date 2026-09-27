<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Employment\Models\Employee;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/** Turns a published FormVersion's field list into Filament components (the "Collect" step). */
final class FormFieldsSchema
{
    /** @return array<int, Component> */
    public static function components(?FormVersion $version): array
    {
        if ($version === null) {
            return [];
        }

        return array_map(fn (array $field) => self::component($field), $version->fields ?? []);
    }

    private static function component(array $field): Component
    {
        $key = $field['key'];

        $component = match ($field['type'] ?? 'text') {
            'textarea' => Textarea::make($key)->rows(3),
            'number' => TextInput::make($key)->numeric(),
            'date' => DatePicker::make($key)->native(false),
            'dropdown' => Select::make($key)->options(FormVersion::optionMap($field)),
            'radio' => Radio::make($key)->options(FormVersion::optionMap($field)),
            'checkbox' => Checkbox::make($key),
            'email' => TextInput::make($key)->email(),
            'employee' => Select::make($key)->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])->all())->searchable(),
            'department' => Select::make($key)->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            'location' => Select::make($key)->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            default => TextInput::make($key)->maxLength(255),
        };

        return $component
            ->label($field['label'] ?? $key)
            ->helperText($field['help_text'] ?? null)
            ->required((bool) ($field['required'] ?? false));
    }
}
