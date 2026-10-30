<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Experience\Services\RequestFollowUps;
use App\Domain\Experience\Services\RequestPreview;
use App\Domain\Experience\Services\RequestRegistry;
use App\Domain\Experience\Services\RequestTimeline;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Filament\Pages\MyRequests;
use App\Filament\Pages\RequestTracker;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * HROne parity RQ-2 (matrix 20 §20.1.1 #1–3, #5): the chrome every request form shares. Presentation only: nothing in it is
 * submitted, and the module's own form, authorisation, validation, approvals and audit are unchanged.
 *
 * - header(): whose request it is — initials, name (code), company and designation of the employee the form is for, and
 *   "You are raising this for …" on an on-behalf form.
 * - route(): a collapsed "Who approves this" section — the read-only preview of RequestPreview (the workflow that would
 *   start and its approvers, else the module's own approvers; who is informed) and "Read the policy" links.
 * - confirmed(): the module's own success message, now with "Follow this request", "Notify others" (when switched on for
 *   the type), "Raise another" and "View my requests" — for the person's own request only; on-behalf requests keep the
 *   plain message.
 *
 * $type is a request-registry key, or a closure of the form state returning one (the time-office form picks its kind).
 */
final class RequestChrome
{
    /** @param  Closure(Get): ?Employee  $employee  the employee the form is for (an on-behalf form reads its own picker) */
    public static function header(Closure $employee): Text
    {
        return Text::make(fn (Get $get) => self::renderHeader(self::employee($employee, $get)))
            ->visible(fn (Get $get) => ($e = self::employee($employee, $get)) !== null && $e->exists);
    }

    /**
     * @param  string|Closure(Get): ?string  $type
     * @param  Closure(Get): ?Employee  $employee
     */
    public static function route(string|Closure $type, Closure $employee): Section
    {
        return Section::make('Who approves this')
            ->description('Who decides it, who is informed, and the policy behind it. Nothing is sent until you submit.')
            ->collapsible()->collapsed()
            ->visible(fn (Get $get) => self::type($type, $get) !== null && ($e = self::employee($employee, $get)) !== null && $e->exists)
            ->schema([Text::make(fn (Get $get) => self::renderRoute(self::type($type, $get), self::employee($employee, $get)))]);
    }

    /** The module's success message, with the follow-up links when the request is the signed-in person's own. */
    public static function confirmed(string $typeKey, ?Model $record, string $title, ?string $body = null): void
    {
        $notification = Notification::make()->success()->title($title);
        if ($body !== null) {
            $notification->body($body);
        }
        $actions = $record !== null ? self::followUps($typeKey, $record) : [];
        if ($actions !== []) {
            $notification->actions($actions);
        }
        $notification->send();
    }

    /** @return list<Action> */
    public static function followUps(string $typeKey, Model $record): array
    {
        try {
            $user = auth()->user();
            $registry = app(RequestRegistry::class);
            $type = $registry->find($typeKey);
            if ($user === null || $type === null || ! app(RequestTimeline::class)->isOwn($user, $type, $record)) {
                return [];
            }
            $key = $type->key().':'.$record->getKey();
            $actions = [Action::make('followRequest')->label('Follow this request')->url(RequestTracker::getUrl(['request' => $key]))];
            if (app(RequestFollowUps::class)->notifyRefusal($user, $type, $record) === null) {
                $actions[] = Action::make('notifyOthers')->label('Notify others')->url(RequestTracker::getUrl(['request' => $key, 'action' => 'notifyOthers']));
            }
            $again = $registry->isAvailable($user, $type->key()) ? $type->formUrl($user, $registry->employeeOf($user)) : null;
            if ($again !== null) {
                $actions[] = Action::make('raiseAnother')->label('Raise another')->url($again);
            }
            if (MyRequests::canAccess()) {
                $actions[] = Action::make('myRequests')->label('View my requests')->url(MyRequests::getUrl(['type' => $type->key()]));
            }

            return $actions;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public static function renderHeader(?Employee $employee): HtmlString
    {
        if ($employee === null || ! $employee->exists) {
            return new HtmlString('');
        }
        $employee->loadMissing(['person', 'currentPosition.designation', 'currentPosition.company']);
        $own = app(CurrentEmployee::class)->of(auth()->user());

        return new HtmlString(view('filament.partials.request-chrome.header', [
            'name' => $employee->person?->display_name ?? $employee->employee_code,
            'code' => $employee->employee_code,
            'company' => $employee->currentPosition?->company?->name,
            'designation' => $employee->currentPosition?->designation?->name,
            'own' => $own !== null && (int) $own->getKey() === (int) $employee->getKey(),
        ])->render());
    }

    public static function renderRoute(?string $typeKey, ?Employee $employee): HtmlString
    {
        $user = auth()->user();
        $type = $typeKey !== null ? app(RequestRegistry::class)->find($typeKey) : null;
        if ($user === null || ! $type instanceof RequestType) {
            return new HtmlString('');
        }
        try {
            $preview = app(RequestPreview::class)->for($type, $user, $employee);
        } catch (Throwable $e) {
            report($e);

            return new HtmlString('<p class="pos-body-sm pos-muted">The approval route cannot be shown right now.</p>');
        }

        return new HtmlString(view('filament.partials.request-chrome.route', ['preview' => $preview, 'type' => $type])->render());
    }

    /** @param  string|Closure(Get): ?string  $type */
    private static function type(string|Closure $type, ?Get $get = null): ?string
    {
        $key = $type instanceof Closure ? ($get !== null ? $type($get) : null) : $type;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** A picked employee id (an on-behalf form's select) as the employee, within the viewer's access scope. */
    public static function picked(mixed $id): ?Employee
    {
        return is_numeric($id) ? Employee::query()->find((int) $id) : null;
    }

    private static function employee(Closure $employee, ?Get $get = null): ?Employee
    {
        try {
            $value = $employee($get);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof Employee ? $value : null;
    }
}
