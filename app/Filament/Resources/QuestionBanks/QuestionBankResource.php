<?php

namespace App\Filament\Resources\QuestionBanks;

use App\Domain\Feedback\Models\QuestionBank;
use App\Domain\Feedback\Models\QuestionBankQuestion;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Filament\Resources\QuestionBanks\Pages\ManageQuestionBanks;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** HROne setup parity P13 (Forms & templates → Feedback → Question bank): reusable questions feedback templates copy from. */
class QuestionBankResource extends Resource
{
    protected static ?string $model = QuestionBank::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Question banks';

    protected static ?string $modelLabel = 'question bank';

    protected static ?int $navigationSort = 83;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('code')->required()->maxLength(32)->regex('/^[A-Za-z0-9_-]+$/'),
            Select::make('module')->options(config('peopleos.feedback.modules'))->default('workforce')->required()->native(false),
            Select::make('status')->options(QuestionBank::STATUSES)->default('active')->required()->native(false),
            Textarea::make('description')->maxLength(500)->columnSpanFull(),
            Repeater::make('bank_questions')->label('Questions')->columnSpanFull()->columns(3)->minItems(1)->addActionLabel('Add a question')->reorderable()->collapsible()
                ->itemLabel(fn (array $state) => $state['title'] ?? null)
                ->schema(self::questionFields()),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /** The question fields shared with feedback templates (HROne "Response type", options, rating, mandatory). */
    public static function questionFields(bool $withStatus = true): array
    {
        return array_values(array_filter([
            Hidden::make('id'),
            Textarea::make('title')->label('Question')->required()->maxLength(500)->rows(2)->columnSpanFull(),
            Select::make('type')->label('Response type')->options(FeedbackQuestions::types())->required()->native(false)->live(),
            TextInput::make('scale_max')->label('Rated from 1 to')->numeric()->minValue(2)->maxValue(10)->default(5)->visible(fn (Get $get) => $get('type') === 'rating'),
            Toggle::make('is_mandatory')->label('Mandatory')->default(true)->inline(false),
            $withStatus ? Select::make('status')->options(QuestionBank::STATUSES)->default('active')->native(false) : null,
            TagsInput::make('options')->label('Options')->placeholder('Type an option and press Enter')->columnSpanFull()
                ->visible(fn (Get $get) => in_array($get('type'), ['single_choice', 'multiple_choice'], true)),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('questions'))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (QuestionBank $r) => $r->description),
                TextColumn::make('code'),
                TextColumn::make('module')->formatStateUsing(fn (string $state) => config("peopleos.feedback.modules.{$state}", $state)),
                TextColumn::make('questions_count')->label('Questions'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('status')->options(QuestionBank::STATUSES)])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, QuestionBank $record) => $data + ['bank_questions' => $record->questions()->get()->map(fn (QuestionBankQuestion $q) => [
                        'id' => $q->id, 'title' => $q->title, 'type' => $q->type, 'scale_max' => $q->scale_max, 'is_mandatory' => $q->is_mandatory, 'status' => $q->status,
                        'options' => array_column($q->options ?? [], 'label'),
                    ])->all()])
                    ->using(fn (Model $record, array $data) => self::save($record, $data)),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function save(?QuestionBank $bank, array $data): QuestionBank
    {
        $reason = AuditReasonField::extract($data);
        $questions = array_values((array) ($data['bank_questions'] ?? []));
        unset($data['bank_questions']);

        return app(FeedbackTemplates::class)->saveBank($bank, $data, $questions, $reason, auth()->user());
    }

    public static function getPages(): array
    {
        return ['index' => ManageQuestionBanks::route('/')];
    }
}
