<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\People\Services\Birthdays;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Services\Recognitions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** HROne setup parity HP-9c: "Give a badge" (Recognition list), mounted optionally with ['employee' => id]. */
final class RecognitionActions
{
    public static function give(?Closure $after = null): Action
    {
        return Action::make('giveBadge')->label('Give a badge')->icon(Heroicon::OutlinedTrophy)
            ->visible(fn () => auth()->user()?->can('recognition.give') ?? false)
            ->modalHeading('Give a badge')
            ->modalDescription(function () {
                $left = app(Recognitions::class)->quotaLeft(auth()->user());

                return $left === null ? 'Recognise a colleague. They are told at once.' : "Recognise a colleague. You can give {$left} more ".($left === 1 ? 'badge' : 'badges').' this month.';
            })
            ->modalSubmitActionLabel('Give badge')
            ->fillForm(fn (array $arguments) => ['employee_id' => $arguments['employee'] ?? null])
            ->schema([
                Select::make('employee_id')->label('Colleague')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::colleagues($search))
                    ->getOptionLabelUsing(fn ($value) => Employee::query()->with('person')->find($value)?->auditLabel()),
                Select::make('badge_id')->label('Badge')->required()->live()->native(false)
                    ->options(fn () => app(Recognitions::class)->badgesFor(auth()->user())->pluck('name', 'id')->all())
                    ->helperText(fn (Get $get) => self::badge($get('badge_id'))?->description),
                Textarea::make('message')->label('Reason')->rows(3)->maxLength(Recognitions::MESSAGE_MAX)
                    ->required(fn (Get $get) => (bool) self::badge($get('badge_id'))?->reason_required),
                FileUpload::make('attachments')->label('Attachment')->multiple()->maxFiles(3)->disk(config('peopleos.documents.disk', 'local'))
                    ->directory(fn () => Recognitions::directory())->visibility('private')->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize((int) config('peopleos.documents.max_kb', 10240))
                    ->required(fn (Get $get) => (bool) self::badge($get('badge_id'))?->attachment_required),
            ])
            ->action(function (array $data) use ($after) {
                try {
                    $badge = Badge::query()->findOrFail($data['badge_id']);
                    $employee = Employee::query()->with('person')->findOrFail($data['employee_id']);
                    app(Recognitions::class)->give($badge, $employee, auth()->user(), $data['message'] ?? null, array_values((array) ($data['attachments'] ?? [])));
                    Notification::make()->success()->title($badge->name.' given to '.$employee->person->first_name)->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not give the badge')->body($e->getMessage())->persistent()->send();
                }
                if ($after !== null) {
                    $after();
                }
            });
    }

    /** @return array<int, string> colleagues working here now, matching $search, never oneself */
    public static function colleagues(string $search): array
    {
        return Employee::query()->with('person')->whereIn('lifecycle_state', Birthdays::STATES)
            ->where(fn (Builder $q) => $q->where('user_id', '!=', auth()->id())->orWhereNull('user_id'))
            ->where(fn (Builder $q) => $q->where('employee_code', 'like', "%{$search}%")->orWhereHas('person', fn (Builder $p) => $p->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
            ->limit(25)->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->auditLabel()])->all();
    }

    private static function badge(mixed $id): ?Badge
    {
        return is_numeric($id) ? Badge::query()->find((int) $id) : null;
    }
}
