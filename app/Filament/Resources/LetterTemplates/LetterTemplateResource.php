<?php

namespace App\Filament\Resources\LetterTemplates;

use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\LetterTemplates\Pages\ManageLetterTemplates;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Letter factory templates (§40). */
class LetterTemplateResource extends Resource
{
    protected static ?string $model = LetterTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Select::make('type')->options(config('peopleos.letters.types'))->default('custom')->required(),
            TextInput::make('subject')->required()->maxLength(255)->columnSpan(2)->helperText('Variables: {{ employee.name }}, {{ employee.code }}, {{ employee.designation }}, {{ employee.department }}, {{ employee.joining_date }}, {{ employee.exit_date }}, {{ employee.ctc_annual }}, {{ company.name }}, {{ letter.number }}, {{ letter.date }}, plus any extra variable supplied at generation.'),
            Toggle::make('requires_approval')->label('Needs approval before issue')->inline(false),
            MarkdownEditor::make('body')->required()->columnSpanFull(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                IconColumn::make('requires_approval')->label('Approval')->boolean(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(function (LetterTemplate $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLetterTemplates::route('/')];
    }
}
