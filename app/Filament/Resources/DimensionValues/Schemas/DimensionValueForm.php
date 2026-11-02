<?php

namespace App\Filament\Resources\DimensionValues\Schemas;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\DimensionValue;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Data import DI-1: a value of the organisation's own dimension, optionally under another value. */
class DimensionValueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code, used by uploads and JV codes.')
                            ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                        Select::make('parent_id')->label('Parent value')->placeholder('None (a top-level value)')->searchable()
                            ->options(fn (?DimensionValue $record) => DimensionValue::query()->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('A value may sit under another value, never under one of its own.')
                            ->rules([fn (?DimensionValue $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (filled($value) && DimensionValue::wouldLoop($record, (int) $value)) {
                                    $fail('That value sits under this one: a value cannot sit under one of its own values.');
                                }
                            }]),
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                        Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
