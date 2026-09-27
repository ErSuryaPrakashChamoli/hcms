<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Models\Article;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Domain\ServiceDesk\Policies\TicketPolicy;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use Throwable;

/** Ask HR, reply, assign, resolve, close, reopen, rate. */
final class ServiceDeskActions
{
    public static function me(): ?Employee
    {
        return Employee::query()->where('user_id', auth()->id())->first();
    }

    public static function isAgent(): bool
    {
        return auth()->user()->can('servicedesk.view');
    }

    /** "Ask HR": employees raise for themselves; agents may raise on someone's behalf. */
    public static function askHr(): Action
    {
        return Action::make('askHr')->label('Ask HR')->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)->color('primary')
            ->visible(fn () => auth()->user()->can('create', Ticket::class) && (self::me() !== null || self::isAgent()))
            ->schema([
                Select::make('employee_id')->label('For employee')->required()->searchable()->options(fn () => Employee::query()->with('person')->employed()->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all())
                    ->default(fn () => self::me()?->id)->visible(fn () => self::isAgent()),
                Select::make('ticket_category_id')->label('What is it about?')->required()->options(fn () => TicketCategory::query()->where('status', 'active')->orderBy('sort_order')->pluck('name', 'id')->all()),
                TextInput::make('subject')->required()->maxLength(255),
                Textarea::make('description')->required()->rows(5)->maxLength(4000),
                Select::make('priority')->options(config('peopleos.servicedesk.priorities'))->default('normal')->required(),
            ])
            ->action(function (array $data) {
                $employee = self::isAgent() && ! empty($data['employee_id']) ? Employee::query()->findOrFail($data['employee_id']) : self::me();
                self::run(function () use ($employee, $data) {
                    $ticket = app(ServiceDesk::class)->open($employee, TicketCategory::query()->findOrFail($data['ticket_category_id']), $data['subject'], $data['description'], $data['priority'], auth()->user());

                    return 'Request '.$ticket->number.' raised';
                }, fn ($m) => $m);
            });
    }

    /** @return array<int, Action> */
    public static function forTicket(): array
    {
        $agent = fn () => self::isAgent();
        $own = fn (Ticket $record) => TicketPolicy::isOwn(auth()->user(), $record);
        $disk = config('peopleos.documents.disk', 'local');

        return [
            Action::make('reply')->label('Reply')->icon(Heroicon::OutlinedChatBubbleLeft)->color('primary')
                ->visible(fn (Ticket $record) => $record->status !== 'closed' && ($own($record) || $agent() || $record->assignee_id === auth()->id()))
                ->schema([
                    Textarea::make('body')->label('Message')->required()->rows(4)->maxLength(4000),
                    FileUpload::make('attachment')->disk($disk)->directory('servicedesk')->maxSize(config('peopleos.documents.max_kb')),
                    Toggle::make('is_internal')->label('Internal note (hidden from the employee)')->visible($agent),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->comment($record, auth()->user(), $data['body'], (bool) ($data['is_internal'] ?? false), $data['attachment'] ?? null, isset($data['attachment']) ? basename($data['attachment']) : null), 'Reply posted')),
            Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Ticket $record) => $record->isOpen() && $agent())
                ->schema([Select::make('assignee_id')->label('Agent')->required()->searchable()->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->hasPermission('servicedesk.view'))->pluck('name', 'id')->all())->default(fn () => auth()->id())])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->assign($record, User::query()->findOrFail($data['assignee_id']), auth()->user()), 'Assigned')),
            Action::make('waiting')->label('Wait on employee')->icon(Heroicon::OutlinedClock)->color('warning')
                ->visible(fn (Ticket $record) => in_array($record->status, ['new', 'open'], true) && $agent())
                ->action(fn (Ticket $record) => self::run(fn () => app(ServiceDesk::class)->waitOnEmployee($record, auth()->user()), 'Waiting on the employee')),
            Action::make('resolve')->label('Resolve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Ticket $record) => $record->isOpen() && ($agent() || $record->assignee_id === auth()->id()))
                ->schema([
                    Textarea::make('resolution')->required()->rows(4)->maxLength(4000),
                    Select::make('article_id')->label('Link a knowledge article')->placeholder('—')->searchable()->options(fn () => Article::query()->where('status', 'published')->orderBy('title')->pluck('title', 'id')->all()),
                ])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->resolve($record, $data['resolution'], auth()->user(), $data['article_id'] ?? null), 'Resolved')),
            Action::make('close')->label(fn (Ticket $record) => $own($record) ? 'Close & rate' : 'Close')->icon(Heroicon::OutlinedLockClosed)->color('gray')
                ->visible(fn (Ticket $record) => $record->status === 'resolved' && ($own($record) || $agent()))
                ->schema(fn (Ticket $record) => $own($record) ? [
                    Radio::make('satisfaction')->label('How was the help?')->options([1 => '1 · Poor', 2 => '2', 3 => '3 · OK', 4 => '4', 5 => '5 · Excellent'])->inline(),
                    Textarea::make('comment')->maxLength(500),
                ] : [])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->close($record, auth()->user(), isset($data['satisfaction']) ? (int) $data['satisfaction'] : null, $data['comment'] ?? null), 'Closed')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedArrowPath)->color('danger')
                ->visible(fn (Ticket $record) => in_array($record->status, ['resolved', 'closed'], true) && ($own($record) || $agent()))
                ->schema([Textarea::make('reason')->required()->maxLength(500)])
                ->action(fn (Ticket $record, array $data) => self::run(fn () => app(ServiceDesk::class)->reopen($record, $data['reason'], auth()->user()), 'Reopened')),
        ];
    }

    public static function run(callable $callback, string|callable $success): void
    {
        try {
            $result = $callback();
            Notification::make()->success()->title(is_callable($success) ? $success($result) : $success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
