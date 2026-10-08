<?php

namespace App\Filament\Resources\TalentReviews;

use App\Domain\Identity\Models\User;
use App\Domain\Talent\Models\TalentReviewItem;
use App\Domain\Talent\Models\TalentReviewSession;
use App\Domain\Talent\Services\TalentReviews;
use App\Filament\Resources\TalentReviews\Pages\ManageTalentReviews;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 9 talent reviews: a facilitator (talent.manage) sets the population and participants;
 * participants (talent.review) record a decision with a reason per employee. Decisions are recorded,
 * never executed automatically. Completion is locked and may wait for an approval workflow.
 */
class TalentReviewResource extends Resource
{
    protected static ?string $model = TalentReviewSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Talent reviews';

    protected static ?int $navigationSort = 70;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount(['items', 'items as decided_items' => fn ($q) => $q->where('status', 'decided')]);
        $user = auth()->user();

        // Participants without talent.view see only the sessions they take part in.
        return $user->can('talent.view') || $user->can('talent.manage') ? $query : $query->whereJsonContains('participants', (int) $user->id);
    }

    /** @return array<int, Action> */
    public static function headerActions(): array
    {
        return [
            Action::make('create')->label('New talent review')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->can('talent.manage'))
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('organisation_node_id')->label('Scope (organisation unit)')->options(fn () => TalentActions::nodeOptions())->searchable(),
                    Select::make('participants')->label('Participants')->multiple()->searchable()->options(fn () => User::forCurrentTenant()->orderBy('name')->limit(500)->pluck('name', 'id')->all()),
                    DatePicker::make('scheduled_for')->native(false),
                ])
                ->action(fn (array $data) => TalentActions::run(fn () => app(TalentReviews::class)->create($data['name'], $data['organisation_node_id'] ?? null, array_map('intval', $data['participants'] ?? []), auth()->user(), $data['scheduled_for'] ?? null), 'Talent review created')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('scheduled_for')->date()->placeholder('—'),
                TextColumn::make('progress')->label('Decided')->state(fn (TalentReviewSession $record) => "{$record->decided_items} / {$record->items_count}"),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'in_progress' => 'warning', 'completed' => 'success', 'cancelled' => 'gray', default => 'info'
                }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('items')->label('Population')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (TalentReviewSession $record) => $record->items()->with('employee.person')->get()->map(fn (TalentReviewItem $i) => TextEntry::make("i{$i->id}")
                        ->label(($i->employee?->employee_code ?? '').' · '.($i->employee?->person?->full_name ?? ''))
                        ->state($i->decision ? config("peopleos.talent.review_decisions.{$i->decision}", $i->decision) : 'Pending')->helperText($i->reason))->all() ?: [TextEntry::make('none')->hiddenLabel()->state('No employees yet.')]),
                Action::make('addEmployees')->label('Add employees')->icon(Heroicon::OutlinedUserPlus)
                    ->visible(fn (TalentReviewSession $record) => in_array($record->status, ['draft', 'in_progress'], true) && auth()->user()->can('talent.manage'))
                    ->schema([Select::make('employee_ids')->label('Employees')->multiple()->options(fn () => TalentActions::scopedPeopleOptions())->searchable()->required()])
                    ->action(fn (TalentReviewSession $record, array $data) => TalentActions::run(fn () => app(TalentReviews::class)->addEmployees($record, array_map('intval', $data['employee_ids']), auth()->user()), fn ($n) => "{$n} employee(s) added")),
                Action::make('start')->label('Start')->icon(Heroicon::OutlinedPlay)->color('primary')->requiresConfirmation()
                    ->visible(fn (TalentReviewSession $record) => $record->status === 'draft' && auth()->user()->can('talent.manage'))
                    ->action(fn (TalentReviewSession $record) => TalentActions::run(fn () => app(TalentReviews::class)->start($record, auth()->user()), 'Review started')),
                Action::make('decide')->label('Record decision')->icon(Heroicon::OutlinedPencilSquare)->color('primary')
                    ->visible(fn (TalentReviewSession $record) => $record->status === 'in_progress' && (auth()->user()->can('talent.review') || auth()->user()->can('talent.manage')))
                    ->schema(fn (TalentReviewSession $record) => [
                        Select::make('item_id')->label('Employee')->required()->options($record->items()->with('employee.person')->where('status', '!=', 'decided')->get()
                            ->reject(fn ($i) => $i->employee_id === TalentActions::me()?->id)->mapWithKeys(fn ($i) => [$i->id => "{$i->employee?->employee_code} · {$i->employee?->person?->full_name}"])->all()),
                        Select::make('decision')->options(config('peopleos.talent.review_decisions'))->required()->helperText('Recorded only — any follow-up (pool, successor, development) is a separate action by a person.'),
                        Textarea::make('reason')->required()->maxLength(1000),
                    ])
                    ->action(fn (TalentReviewSession $record, array $data) => TalentActions::run(fn () => app(TalentReviews::class)->recordDecision($record->items()->findOrFail($data['item_id']), $data['decision'], $data['reason'], auth()->user()), 'Decision recorded')),
                Action::make('complete')->label('Complete')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (TalentReviewSession $record) => $record->status === 'in_progress' && ! $record->workflow_instance_id && auth()->user()->can('talent.manage'))
                    ->schema([Textarea::make('summary')->label('Summary')->required()->maxLength(2000)])
                    ->action(fn (TalentReviewSession $record, array $data) => TalentActions::run(fn () => app(TalentReviews::class)->complete($record, $data['summary'], auth()->user()), fn ($s) => $s->status === 'completed' ? 'Review completed' : 'Sent for approval')),
                Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->visible(fn (TalentReviewSession $record) => in_array($record->status, ['draft', 'in_progress'], true) && auth()->user()->can('talent.manage'))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])
                    ->action(fn (TalentReviewSession $record, array $data) => TalentActions::run(fn () => app(TalentReviews::class)->cancel($record, $data['reason'], auth()->user()), 'Review cancelled')),
            ])
            ->emptyStateHeading('No talent reviews');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTalentReviews::route('/')];
    }
}
