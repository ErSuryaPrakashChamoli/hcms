<?php

namespace App\Filament\Resources\EmployeeFeedback;

use App\Domain\Employment\Models\Employee;
use App\Domain\Engagement\Models\EmployeeFeedback;
use App\Domain\Engagement\Services\Feedback;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Filament\Resources\EmployeeFeedback\Pages\ListEmployeeFeedback;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 13: the feedback inbox (engagement.feedback). Identified items show their author (in
 * scope). Confidential and anonymous items show no author. A confidential author is identified only
 * through the reasoned, audited reveal when referring the item; an anonymous author never.
 *
 * Feedback is routed, not worked here: to an HR service desk request, or to an anonymous grievance.
 */
class EmployeeFeedbackResource extends Resource
{
    protected static ?string $model = EmployeeFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Feedback inbox';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static ?string $slug = 'employee-feedback';

    protected static ?int $navigationSort = 4;

    public static function getEloquentQuery(): Builder
    {
        return app(Feedback::class)->inbox(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $run = fn (callable $callback, string $done) => ServiceDeskActions::run($callback, $done);

        return $table
            ->columns([
                TextColumn::make('submitted_on')->date()->sortable(),
                TextColumn::make('mode')->badge()->color(fn (string $state) => $state === 'anonymous' ? 'success' : ($state === 'confidential' ? 'warning' : 'gray')),
                TextColumn::make('category')->formatStateUsing(fn (string $state) => config("peopleos.engagement.feedback_categories.{$state}", $state)),
                TextColumn::make('from')->label('From')->state(fn (EmployeeFeedback $record) => $record->employee_id ? self::employeeLabel((int) $record->employee_id) : '— (not identified)'),
                TextColumn::make('body')->label('Feedback')->limit(80)->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => config("peopleos.engagement.feedback_statuses.{$state}", $state)),
            ])
            ->defaultSort('submitted_on', 'desc')
            ->filters([
                SelectFilter::make('status')->options(config('peopleos.engagement.feedback_statuses')),
                SelectFilter::make('category')->options(config('peopleos.engagement.feedback_categories')),
            ])
            ->recordActions([
                Action::make('read')->label('Read')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (EmployeeFeedback $record) => [
                        TextEntry::make('b')->label('Feedback')->state($record->body),
                        TextEntry::make('n')->label('Handling note')->state($record->handling_note ?? '—'),
                        TextEntry::make('r')->label('Referred to')->state($record->referred_type ? $record->referred_type.' #'.$record->referred_id : '—'),
                    ]),
                ActionGroup::make([
                    Action::make('review')->label('Mark in review')->icon('heroicon-m-eye')
                        ->visible(fn (EmployeeFeedback $record) => $record->status === 'new')
                        ->action(fn (EmployeeFeedback $record) => $run(fn () => app(Feedback::class)->setStatus($record, 'in_review', null, auth()->user()), 'In review')),
                    Action::make('close')->label('Close')->icon('heroicon-m-check')
                        ->visible(fn (EmployeeFeedback $record) => in_array($record->status, ['new', 'in_review'], true))
                        ->schema([Textarea::make('note')->label('Handling note')])
                        ->action(fn (EmployeeFeedback $record, array $data) => $run(fn () => app(Feedback::class)->setStatus($record, 'closed', $data['note'] ?? null, auth()->user()), 'Closed')),
                    Action::make('refer')->label('Refer to HR service desk')->icon('heroicon-m-lifebuoy')
                        ->visible(fn (EmployeeFeedback $record) => $record->mode !== 'anonymous' && in_array($record->status, ['new', 'in_review'], true) && auth()->user()->can('servicedesk.agent'))
                        ->schema(fn (EmployeeFeedback $record) => array_filter([
                            Select::make('category')->label('Request category')->required()->options(fn () => TicketCategory::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                            $record->mode === 'confidential' ? Textarea::make('reason')->label('Why the author must be identified (audited)')->required()->minLength(10) : null,
                        ]))
                        ->action(fn (EmployeeFeedback $record, array $data) => $run(fn () => app(Feedback::class)->referToServiceDesk($record, TicketCategory::query()->findOrFail($data['category']), auth()->user(), $data['reason'] ?? null), 'Referred to the service desk')),
                    Action::make('grievance')->label('Refer as anonymous grievance')->icon('heroicon-m-shield-exclamation')
                        ->visible(fn (EmployeeFeedback $record) => $record->mode === 'anonymous' && in_array($record->status, ['new', 'in_review'], true))
                        ->schema([Select::make('category')->label('Grievance category')->required()->options(fn () => GrievanceCategory::query()->where('allow_anonymous', true)->orderBy('name')->pluck('name', 'id')->all())])
                        ->action(fn (EmployeeFeedback $record, array $data) => $run(fn () => app(Feedback::class)->referToGrievance($record, GrievanceCategory::query()->findOrFail($data['category']), auth()->user()), 'Referred as an anonymous grievance')),
                ]),
            ]);
    }

    private static function employeeLabel(int $id): string
    {
        $employee = AccessScope::withoutScoping(fn () => Employee::query()->with('person')->find($id));

        return $employee ? $employee->employee_code.' · '.$employee->person?->full_name : '—';
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeFeedback::route('/')];
    }
}
