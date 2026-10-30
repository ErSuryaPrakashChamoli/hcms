<?php

namespace App\Filament\Resources\ConfirmationReviews;

use App\Domain\Feedback\Services\FeedbackRequests;
use App\Domain\Lifecycle\Models\ConfirmationReview;
use App\Domain\Lifecycle\Services\ConfirmationReviews;
use App\Filament\Resources\ConfirmationReviews\Pages\ListConfirmationReviews;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne setup parity (Workforce → Confirmation policy): confirmation reviews to confirm, extend the probation or terminate. */
class ConfirmationReviewResource extends Resource
{
    protected static ?string $model = ConfirmationReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Confirmation reviews';

    protected static ?string $modelLabel = 'confirmation review';

    protected static ?int $navigationSort = 60;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('employee.lifecycle') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (ConfirmationReview $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
            }
        };
        $reviews = fn () => app(ConfirmationReviews::class);
        $pending = fn (ConfirmationReview $r) => $r->status === 'pending';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'decider']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (ConfirmationReview $r) => $r->employee?->employee_code),
                TextColumn::make('probation_end_date')->label('Probation ends')->date()->sortable(),
                TextColumn::make('extension_number')->label('Extensions so far'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ConfirmationReview::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'confirmed' => 'success', 'extended' => 'info', 'terminated' => 'danger', 'cancelled' => 'gray', default => 'warning',
                    }),
                // HROne setup parity P13: the manager's feedback and the probation self-review (statuses; answers on Feedback).
                TextColumn::make('feedback')->label('Feedback')->state(fn (ConfirmationReview $r) => app(FeedbackRequests::class)->summary($r))->placeholder('—')->wrap(),
                TextColumn::make('extended_to')->label('Extended to')->date()->placeholder('—'),
                TextColumn::make('decision_note')->label('Note')->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('decider.name')->label('Decided by')->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(ConfirmationReview::STATUSES)->default('pending'),
                // HROne parity WO-2: the shared employee filters and the probation end date.
                ...EmployeeFilters::forRegister('employee', 'probation_end_date', 'Probation ends')])
            ->defaultSort('probation_end_date')
            ->recordActions([
                Action::make('confirm')->icon('heroicon-m-check-badge')->color('success')->visible($pending)
                    ->schema([Textarea::make('note')->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $reviews()->confirm($r, $d['note'] ?? null, auth()->user()), 'Employee confirmed')),
                Action::make('extend')->label('Extend probation')->icon('heroicon-m-clock')->color('info')->visible($pending)
                    ->schema([TextInput::make('months')->numeric()->minValue(1)->maxValue(24)->default(3)->required(), Textarea::make('note')->label('Why')->required()->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $reviews()->extend($r, (int) $d['months'], $d['note'], auth()->user()), 'Probation extended')),
                Action::make('terminate')->icon('heroicon-m-x-circle')->color('danger')->visible($pending)->requiresConfirmation()
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $reviews()->terminate($r, $d['note'], auth()->user()), 'Termination started')),
            ])
            // HROne parity WO-1: confirm the selected reviews (each through ConfirmationReviews::confirm).
            ->toolbarActions([BulkRecordActions::selected('confirmation_reviews', ['confirmation_reviews.confirm'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListConfirmationReviews::route('/')];
    }
}
