<?php

namespace App\Filament\Resources\FeedbackTemplates;

use App\Domain\Feedback\Models\FeedbackTemplate;
use App\Domain\Feedback\Models\QuestionBankQuestion;
use App\Domain\Feedback\Services\FeedbackTemplates;
use App\Domain\Feedback\Support\FeedbackQuestions;
use App\Filament\Resources\FeedbackTemplates\Pages\ManageFeedbackTemplates;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * HROne setup parity P13 (Forms & templates → Feedback templates): templates bound to a process — exit interviews by the
 * employee, the manager and HR, confirmation, probation self-review, induction — written here or copied from the question
 * banks, and added from the library. Policies and onboarding items name the template; feedback keeps its own copy.
 */
class FeedbackTemplateResource extends Resource
{
    protected static ?string $model = FeedbackTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Feedback templates';

    protected static ?string $modelLabel = 'feedback template';

    protected static ?int $navigationSort = 82;

    /** @return array<string, string> */
    public static function processOptions(): array
    {
        return collect(config('peopleos.feedback.processes'))->map(fn (array $p) => $p['label'])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Feedback name')->required()->maxLength(120),
            TextInput::make('code')->required()->maxLength(32)->regex('/^[A-Za-z0-9_-]+$/'),
            Select::make('process')->label('Used for')->options(self::processOptions())->required()->native(false),
            Select::make('status')->options(FeedbackTemplate::STATUSES)->default('active')->required()->native(false),
            Textarea::make('description')->maxLength(500)->columnSpanFull(),
            Repeater::make('questions')->columnSpanFull()->columns(3)->minItems(1)->addActionLabel('Add a question')->reorderable()->collapsible()
                ->itemLabel(fn (array $state) => $state['title'] ?? null)
                ->schema([
                    Hidden::make('key'),
                    Select::make('bank_question_id')->label('From the question bank')->options(fn () => FeedbackTemplates::bankQuestionOptions())->searchable()->placeholder('Write my own')->columnSpanFull()->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $question = $state ? QuestionBankQuestion::query()->find($state) : null;
                            if ($question !== null) {
                                $set('title', $question->title);
                                $set('type', $question->type);
                                $set('options', array_column($question->options ?? [], 'label'));
                                $set('scale_max', $question->scale_max);
                                $set('mandatory', $question->is_mandatory);
                            }
                        }),
                    Textarea::make('title')->label('Question')->required()->maxLength(500)->rows(2)->columnSpanFull(),
                    Select::make('type')->label('Response type')->options(FeedbackQuestions::types())->required()->native(false)->live(),
                    TextInput::make('scale_max')->label('Rated from 1 to')->numeric()->minValue(2)->maxValue(10)->default(5)->visible(fn (Get $get) => $get('type') === 'rating'),
                    Toggle::make('mandatory')->default(true)->inline(false),
                    TagsInput::make('options')->label('Options')->placeholder('Type an option and press Enter')->columnSpanFull()
                        ->visible(fn (Get $get) => in_array($get('type'), ['single_choice', 'multiple_choice'], true)),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('requests'))
            ->columns([
                TextColumn::make('name')->label('Feedback name')->searchable()->description(fn (FeedbackTemplate $r) => $r->description),
                TextColumn::make('code'),
                TextColumn::make('process')->label('Used for')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.feedback.processes.{$state}.label", $state)),
                TextColumn::make('questions')->label('Questions')->state(fn (FeedbackTemplate $r) => count($r->questions ?? [])),
                TextColumn::make('requests_count')->label('Asked'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('process')->label('Used for')->options(self::processOptions()), SelectFilter::make('status')->options(FeedbackTemplate::STATUSES)])
            ->recordActions([
                Action::make('preview')->label('Preview')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (FeedbackTemplate $record) => collect($record->questions ?? [])->map(fn (array $q, int $i) => TextEntry::make('q'.$i)->label(($i + 1).'. '.$q['title'])
                        ->state(FeedbackQuestions::types()[$q['type']].($q['options'] ? ': '.implode(' · ', array_column($q['options'], 'label')) : '').($q['type'] === 'rating' ? ' (1 to '.$q['scale_max'].')' : '').($q['mandatory'] ? '' : ' — optional')))->all()),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data) => ['questions' => array_map(fn (array $q) => ['options' => array_column($q['options'] ?? [], 'label')] + $q, (array) ($data['questions'] ?? []))] + $data)
                    ->using(fn (Model $record, array $data) => self::save($record, $data)),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function save(?FeedbackTemplate $template, array $data): FeedbackTemplate
    {
        $reason = AuditReasonField::extract($data);
        $data['questions'] = array_values((array) ($data['questions'] ?? []));

        return app(FeedbackTemplates::class)->saveTemplate($template, $data, $reason, auth()->user());
    }

    public static function getPages(): array
    {
        return ['index' => ManageFeedbackTemplates::route('/')];
    }
}
