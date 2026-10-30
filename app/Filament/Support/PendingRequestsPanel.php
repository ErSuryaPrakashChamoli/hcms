<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\MyRequests;
use App\Domain\Identity\Services\CurrentEmployee;
use Closure;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

/**
 * HROne parity RQ-1 (matrix 20 §20.1.1 #7 "View pending request", §20.1.8 #10, §20.1.18 Leave / On duty #11): inside a
 * request form of the time-office family (leave, attendance regularisation, on duty, short leave, restricted holiday,
 * shift and week-off changes, overtime, comp-off), the person's own requests of that family still waiting for a decision —
 * read from the request registry. Shown only when the form is for the signed-in person (never on an on-behalf form);
 * collapsed, read only, nothing in it is submitted.
 */
final class PendingRequestsPanel
{
    public const LIMIT = 10;

    /** @param  Closure(): ?Employee  $employee  the employee the form is for */
    public static function make(Closure $employee, string $family = 'time_office'): Section
    {
        return Section::make('Your open requests')
            ->description('Requests of this kind still waiting for a decision.')
            ->collapsible()->collapsed()
            ->visible(fn () => self::isOwn($employee()))
            ->schema([Text::make(fn () => self::render($family))]);
    }

    /** Whether the form is for the signed-in person's own employee record. */
    public static function isOwn(?Employee $employee): bool
    {
        $own = app(CurrentEmployee::class)->of(auth()->user());

        return $employee !== null && $employee->exists && $own !== null && (int) $own->getKey() === (int) $employee->getKey();
    }

    public static function render(string $family): HtmlString
    {
        $rows = app(MyRequests::class)->pending(auth()->user(), perType: self::LIMIT, family: $family)->take(self::LIMIT);
        if ($rows->isEmpty()) {
            return new HtmlString('<p class="pos-body-sm pos-muted">No open requests.</p>');
        }
        $items = $rows->map(fn (array $row) => '<li class="pos-body-sm">'.e(implode(' · ', array_filter([
            $row['title'], $row['status_label'], $row['detail'],
        ]))).'</li>')->implode('');

        return new HtmlString('<ul class="space-y-2">'.$items.'</ul>');
    }
}
