<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Services\TimeOfficeDetails;
use App\Domain\Employment\Models\Employee;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Filament\Resources\WorkflowInstances\WorkflowInstanceResource;
use App\Http\Controllers\ParityFileController;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * HROne parity TX-1: read-only entries shared by the detail views of time-office requests, regularisations and leave
 * requests (HROne's card "Detail" flyers): the supporting documents behind signed, audited links, the day's current
 * status next to the request, and the approval workflow run with a link to its tracker for those who may open it.
 */
final class TimeOfficeDetailEntries
{
    /** At most this many days are listed with their status (a long on-duty trip shows its first days). */
    public const DAYS_SHOWN = 14;

    /** Supporting documents (ParityFileController type `time-office-request` / `regularisation`). */
    public static function attachments(string $type): TextEntry
    {
        return TextEntry::make('attachments_list')
            ->label('Supporting documents')
            ->state(function (Model $record) use ($type) {
                // Indexes as ParityFileController reads them (the list's own positions).
                $paths = array_filter(array_values((array) ($record->getAttribute('attachments') ?? [])), fn ($path) => is_string($path) && $path !== '');
                if ($paths === []) {
                    return null;
                }

                return new HtmlString(collect($paths)->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url($type, $record, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'));
            })
            ->html()
            ->placeholder('None attached')
            ->columnSpanFull();
    }

    /**
     * The status of each day of the request as it stands now (processed record, else weekly off / holiday).
     *
     * @param  callable(Model): array{0: ?Employee, 1: ?Carbon, 2: ?Carbon}  $span  employee, first and last day
     */
    public static function dayStatus(callable $span, string $heading = 'The day now'): Section
    {
        return Section::make($heading)
            ->description('What the attendance record says today, so a request on a weekly off, a holiday or a day of leave is seen before deciding.')
            ->schema([
                RepeatableEntry::make('day_status_rows')
                    ->hiddenLabel()
                    ->state(function (Model $record) use ($span) {
                        [$employee, $from, $to] = $span($record);
                        if ($employee === null || $from === null) {
                            return [];
                        }
                        $rows = [];
                        $to ??= $from;
                        for ($day = $from->copy(); $day->lte($to) && count($rows) < self::DAYS_SHOWN; $day->addDay()) {
                            $status = app(TimeOfficeDetails::class)->dayStatus($employee, $day);
                            $rows[] = ['date' => $day->format('D, d M Y'), 'status' => $status['label'].($status['locked'] ? ' · locked' : ''), 'detail' => $status['detail']];
                        }

                        return $rows;
                    })
                    ->columns(3)
                    ->schema([
                        TextEntry::make('date')->label('Day'),
                        TextEntry::make('status')->label('Status now')->badge()->color('gray'),
                        TextEntry::make('detail')->label('Shift, punches, holiday or leave')->placeholder('—'),
                    ]),
            ]);
    }

    /** The approval workflow run on this request (HROne "Workflow"), linked to its tracker when the viewer may open it. */
    public static function workflow(): TextEntry
    {
        $latest = fn (Model $record) => WorkflowInstance::query()->with('workflow')
            ->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())
            ->latest('id')->first();

        return TextEntry::make('workflow_run')
            ->label('Approval workflow')
            ->state(function (Model $record) use ($latest) {
                $run = $latest($record);

                return $run === null ? null : ($run->workflow?->name ?? 'Workflow').' · run #'.$run->id.' · '.$run->status->getLabel();
            })
            ->url(function (Model $record) use ($latest) {
                $run = $latest($record);

                return $run !== null && WorkflowInstanceResource::canView($run) ? WorkflowInstanceResource::getUrl('view', ['record' => $run]) : null;
            })
            ->placeholder('Decided in the Approval Center (no workflow run)');
    }
}
