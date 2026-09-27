<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Models\CustomField;
use App\Domain\Configuration\Services\CustomFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a tenant's custom fields (§41) into any Filament form or infolist. Values live under
 * the `custom_fields.*` state path; pages persist them with SavesCustomFields.
 */
final class CustomFieldsSchema
{
    public const STATE = 'custom_fields';

    /** @param  class-string<Model>  $model */
    public static function formSection(string $model): Section
    {
        return Section::make('Additional information')
            ->columns(2)
            ->schema(fn () => self::formComponents($model))
            ->visible(fn () => app(CustomFields::class)->definitionsFor($model)->isNotEmpty());
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<int, Component>
     */
    public static function formComponents(string $model): array
    {
        return app(CustomFields::class)->definitionsFor($model)
            ->map(fn (CustomField $field) => self::formComponent($field))
            ->all();
    }

    /** @param  class-string<Model>  $model */
    public static function infolistSection(string $model): Section
    {
        return Section::make('Additional information')
            ->columns(3)
            ->schema(fn () => app(CustomFields::class)->definitionsFor($model)
                ->map(fn (CustomField $field) => TextEntry::make('custom_field_'.$field->key)
                    ->label($field->label)
                    ->placeholder('—')
                    ->state(fn (Model $record) => self::display($field, $record->customFields()[$field->key] ?? null)))
                ->all())
            ->visible(fn () => app(CustomFields::class)->definitionsFor($model)->isNotEmpty());
    }

    private static function formComponent(CustomField $field): Component
    {
        $name = self::STATE.'.'.$field->key;

        $component = match ($field->type) {
            'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false),
            'boolean' => Toggle::make($name),
            'dropdown' => Select::make($name)->options($field->optionMap())->searchable(),
            'multiselect' => Select::make($name)->options($field->optionMap())->multiple(),
            'email' => TextInput::make($name)->email(),
            'phone' => TextInput::make($name)->tel(),
            'url' => TextInput::make($name)->url(),
            default => TextInput::make($name)->maxLength(255),
        };

        return $component
            ->label($field->label)
            ->helperText($field->help_text)
            ->required($field->is_required);
    }

    private static function display(CustomField $field, mixed $state): mixed
    {
        return match ($field->type) {
            'boolean' => $state === null ? null : ($state ? 'Yes' : 'No'),
            'dropdown' => $field->optionMap()[$state] ?? $state,
            'multiselect' => is_array($state) ? implode(', ', array_map(fn ($v) => $field->optionMap()[$v] ?? $v, $state)) : $state,
            default => $state,
        };
    }
}
