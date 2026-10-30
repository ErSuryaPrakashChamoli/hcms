<?php

namespace App\Filament\Resources\Recognitions;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Models\Recognition;
use App\Domain\Recognition\Services\Recognitions;
use App\Filament\Resources\Recognitions\Pages\ListRecognitions;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity HP-9c: the recognitions ledger. Employees see public badges and their own (given, received, their
 * reports'); HR (recognition.manage) sees every badge in their scope and may withdraw one. "Give a badge" is on the list.
 */
class RecognitionResource extends Resource
{
    protected static ?string $model = Recognition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $navigationLabel = 'Recognition';

    protected static ?string $modelLabel = 'recognition';

    protected static ?int $navigationSort = 62;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return auth()->user()?->can('recognition.manage') ? 'Engagement' : 'Me';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(Recognitions::class)->visibleTo(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Given on')->date()->sortable(),
                TextColumn::make('badge.name')->label('Badge')->icon(fn (Recognition $record) => $record->badge?->icon)->searchable(),
                TextColumn::make('employee.person.display_name')->label('To')->description(fn (Recognition $record) => $record->employee?->employee_code),
                TextColumn::make('giver.name')->label('From'),
                TextColumn::make('message')->label('Reason')->limit(80)->wrap()->placeholder('—'),
                TextColumn::make('attachments')->label('Attachment')->html()->placeholder('—')
                    ->state(fn (Recognition $record) => collect($record->attachments ?? [])->values()->isEmpty() ? null : new HtmlString(collect($record->attachments)->values()
                        ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('recognition', $record, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'))),
                TextColumn::make('visibility')->badge()->color(fn (string $state) => $state === 'public' ? 'info' : 'gray')->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('withdrawn_at')->label('Status')->badge()->state(fn (Recognition $record) => $record->isWithdrawn() ? 'Withdrawn' : 'Given')
                    ->color(fn (string $state) => $state === 'Withdrawn' ? 'danger' : 'success')
                    ->tooltip(fn (Recognition $record) => $record->isWithdrawn() ? $record->withdraw_reason : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('badge_id')->label('Badge')->options(fn () => Badge::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('visibility')->options(['public' => 'Public', 'private' => 'Private']),
                Filter::make('mine')->label('Received by me')->query(fn (Builder $query) => $query->whereHas('employee', fn (Builder $q) => $q->where('user_id', auth()->id()))),
                Filter::make('given')->label('Given by me')->query(fn (Builder $query) => $query->where('giver_user_id', auth()->id())),
                Filter::make('period')->schema([DatePicker::make('from')->native(false), DatePicker::make('until')->native(false)])
                    ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('recognitions.created_at', '>=', $d))->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('recognitions.created_at', '<=', $d))),
            ])
            ->emptyStateHeading('No badges yet')
            ->emptyStateDescription('Recognise a colleague with “Give a badge”.')
            ->recordActions([
                Action::make('linkedin')->label('Share on LinkedIn')->icon(Heroicon::OutlinedShare)->color('gray')
                    ->visible(fn (Recognition $record) => app(Recognitions::class)->linkedInUrl($record, auth()->user()) !== null)
                    ->url(fn (Recognition $record) => app(Recognitions::class)->linkedInUrl($record, auth()->user()))->openUrlInNewTab(),
                Action::make('certificate')->label('Certificate')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->visible(fn (Recognition $record) => self::certificateDocument($record) !== null)
                    ->url(fn (Recognition $record) => ($document = self::certificateDocument($record)) ? app(Documents::class)->downloadUrl($document) : null)->openUrlInNewTab(),
                Action::make('withdraw')->label('Withdraw')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                    ->visible(fn (Recognition $record) => ! $record->isWithdrawn() && auth()->user()->can('recognition.manage'))
                    ->modalDescription('The badge leaves the wall of fame and no longer counts. The record stays, marked withdrawn.')
                    ->schema([Textarea::make('reason')->required()->maxLength(255)])
                    ->action(function (Recognition $record, array $data) {
                        try {
                            app(Recognitions::class)->withdraw($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Badge withdrawn')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot withdraw')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /** The issued certificate's document, for its receiver or HR. */
    public static function certificateDocument(Recognition $record): ?EmployeeDocument
    {
        $user = auth()->user();
        if ($record->isWithdrawn() || ((int) $record->employee?->user_id !== (int) $user?->id && ! $user?->can('recognition.manage'))) {
            return null;
        }
        $letter = $record->certificate;

        return $letter?->status === 'issued' && $letter->document_id ? EmployeeDocument::query()->find($letter->document_id) : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListRecognitions::route('/')];
    }
}
