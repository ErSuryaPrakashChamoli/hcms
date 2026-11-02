<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Services\OwnRecords;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Services\LeaveAdjustments;
use App\Domain\Leave\Services\LeaveEncashmentBatches;
use App\Domain\Leave\Support\PayMonth;
use App\Filament\Resources\LeaveEncashmentBatches\LeaveEncashmentBatchResource;
use App\Filament\Resources\LeaveEncashments\LeaveEncashmentResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * HROne parity PO-8: the two HR encashment forms.
 *
 * - "Encash leaves" (`batch()`, HROne `app-leave-encashment`: Leave type → Applicability → Review → Process): leave type,
 *   pay month, "As per policy" or "Specify" days; who (search employees, organisation and location, or pasted codes); the
 *   review lists each employee's days and who is skipped and why; "Process" makes the batch (LeaveEncashmentBatches::
 *   submit), processed at once or after another person's approval as Setup → Payroll says.
 * - "Encash for an employee" (`forEmployee()`, HROne "Leave encashment request" on behalf): one employee in the person's
 *   access scope, never their own, through LeaveAdjustments::requestEncashment exactly as the employee's own request
 *   (rule, balance, limits, supporting file) — it waits for approval like theirs. Offered with leave.manage.
 */
final class LeaveEncashmentWizards
{
    public static function batch(string $name = 'encashLeaves'): Action
    {
        return Action::make($name)
            ->label('Encash leaves')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn () => LeaveEncashmentBatchResource::canAccess() && app(LeaveEncashmentBatches::class)->mayManage(auth()->user()))
            ->modalHeading('Encash leaves')
            ->modalDescription(fn () => 'Encash one leave type for many employees, as their encashment rule allows or a set number of days. '
                .(app(LeaveEncashmentBatches::class)->approvalRequired()
                    ? 'Another person who manages leave approves the batch before any encashment is made.'
                    : 'The encashments are made as soon as you process the batch.'))
            ->modalSubmitActionLabel('Process')
            ->fillForm(fn () => ['mode' => 'policy', 'source' => 'employees'])
            ->steps([
                Step::make('Leave type')->description('Leave type, pay month and days')->schema([
                    Select::make('leave_type_id')->label('Leave type')->required()->native(false)->live()
                        ->options(fn () => LeaveType::query()->where('is_encashable', true)->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('pay_month')->label('Pay month')->options(fn () => PayMonth::options())->placeholder('The next pay run')->native(false)
                        ->helperText('The payroll month that pays the encashments. Blank: the next payroll run after they are made.'),
                    Radio::make('mode')->label('Leave encash')->options(LeaveEncashmentBatch::MODES)->required()->live()
                        ->descriptions(['policy' => 'The most each employee\'s encashment rule allows now', 'specified' => 'The same number of days for everyone, never above what their rule allows']),
                    TextInput::make('specified_days')->label('Specify value (days)')->numeric()->minValue(0.5)->maxValue(365)->step(0.5)
                        ->required(fn (Get $get) => $get('mode') === 'specified')->visible(fn (Get $get) => $get('mode') === 'specified'),
                    Textarea::make('note')->label('Comments')->rows(2)->maxLength(500),
                ]),
                Step::make('Applicability')->description('Who is included')->schema(self::whoFields()),
                Step::make('Review')->description('Days per employee, then process')->schema([
                    Text::make(fn (Get $get) => self::renderReview($get))->columnSpanFull(),
                    TextInput::make('confirm_count')
                        ->label(fn (Get $get) => 'Type '.self::lineCount($get).' to confirm')
                        ->helperText('More than '.BulkRuns::confirmOver().' employees: type the number to confirm.')
                        ->required(fn (Get $get) => BulkRuns::needsTypedCount(self::lineCount($get)))
                        ->visible(fn (Get $get) => BulkRuns::needsTypedCount(self::lineCount($get)))
                        ->in(fn (Get $get) => [(string) self::lineCount($get)])->validationMessages(['in' => 'Type exactly the number of employees.']),
                ]),
            ])
            ->action(function (array $data) {
                try {
                    $batch = app(LeaveEncashmentBatches::class)->submit(LeaveType::query()->findOrFail((int) $data['leave_type_id']), (string) $data['mode'],
                        isset($data['specified_days']) && $data['specified_days'] !== '' ? (float) $data['specified_days'] : null, $data['pay_month'] ?? null,
                        self::who($data), $data['note'] ?? null, auth()->user());
                    Notification::make()->success()
                        ->title(match ($batch->status) {
                            'pending' => 'Encashment batch sent for approval', 'processed' => 'Encashments made', default => 'Encashment batch processing',
                        })
                        ->body(match ($batch->status) {
                            'pending' => $batch->employee_count.' employee(s). Someone else who manages leave approves it; the encashments are made then.',
                            'processed' => $batch->encashed_count.' encashment(s), '.LeaveEncashmentBatches::days((float) $batch->encashed_days).' in all.',
                            default => $batch->employee_count.' employee(s): the encashments are being made in the background.',
                        })
                        ->actions([Action::make('open')->label('Open the batch')->url(LeaveEncashmentBatchResource::getUrl('view', ['record' => $batch]))])
                        ->send();
                } catch (RuntimeException|AuthorizationException $e) {
                    Notification::make()->danger()->title('Not processed')->body($e->getMessage())->send();
                }
            });
    }

    public static function forEmployee(string $name = 'encashForEmployee'): Action
    {
        $employee = fn (Get $get): ?Employee => self::picked($get('employee_id'));

        return Action::make($name)
            ->label('Encash for an employee')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->visible(fn () => self::onBehalfOffered())
            ->modalHeading('Encash leave for an employee')
            ->modalDescription('Raise an encashment request in the employee\'s name. Their leave rule, balance and limits apply, and it waits for approval like their own request.')
            ->modalSubmitActionLabel('Request')
            ->steps([
                Step::make('Employee')->description('Whose encashment it is')->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->helperText('Employees in your access scope. For yourself, use My encashments.')
                        ->getSearchResultsUsing(fn (string $search) => self::candidates()->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))
                            ->orderBy('employee_code')->limit(20)->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all())
                        ->getOptionLabelUsing(fn ($value) => self::picked($value)?->auditLabel()),
                    RequestChrome::header($employee),
                ]),
                Step::make('Encashment')->description('Leave type, days and files')->schema([
                    Select::make('leave_type_id')->label('Leave type')->required()->native(false)->live()
                        ->options(fn () => LeaveType::query()->where('is_encashable', true)->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    Text::make(fn (Get $get) => self::renderAllowance($employee($get), $get('leave_type_id')))->visible(fn (Get $get) => filled($get('leave_type_id')) && $employee($get) !== null),
                    TextInput::make('days')->label('Specify leaves (days)')->numeric()->minValue(0.5)->step(0.5)->required(),
                    Select::make('pay_month')->label('Pay month')->options(fn () => PayMonth::options())->placeholder('The next pay run')->native(false)
                        ->helperText('Blank: paid by the next payroll run after it is approved.'),
                    Textarea::make('reason')->label('Comments')->maxLength(255),
                    FileUpload::make('attachments')->label('Supporting file')->multiple()->maxFiles(LeaveAdjustments::MAX_FILES)
                        ->disk(config('peopleos.documents.disk', 'local'))->directory(rtrim(LeaveAdjustments::UPLOAD_AREA, '/'))->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                        ->storeFileNamesIn('attachment_names')
                        ->required(fn (Get $get) => self::needsFile($employee($get), $get('leave_type_id')))
                        ->helperText(fn (Get $get) => self::needsFile($employee($get), $get('leave_type_id')) ? 'Required for this leave type.' : 'Optional.'),
                ]),
                Step::make('Review')->description('Who approves it')->schema([
                    Text::make('The encashment is requested in the employee\'s name under their leave policy and goes to its approvers (or the leave encashment workflow), as their own request would. It records that you raised it.'),
                    RequestChrome::route('leave_encashment', $employee),
                ]),
            ])
            ->action(function (array $data) {
                try {
                    $for = self::resolve($data['employee_id'] ?? null);
                    $encashment = app(LeaveAdjustments::class)->requestEncashment($for, LeaveType::query()->findOrFail((int) $data['leave_type_id']), (float) $data['days'], $data['reason'] ?? null,
                        attachments: array_values((array) ($data['attachments'] ?? [])), names: (array) ($data['attachment_names'] ?? []), payMonth: $data['pay_month'] ?? null);
                    RequestChrome::confirmed('leave_encashment', $encashment, 'Encashment requested for '.$for->display_name, 'It waits for approval like the employee\'s own request.');
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                }
            });
    }

    /** "Encash for an employee" is offered to people who manage leave and can open the encashments register. */
    public static function onBehalfOffered(): bool
    {
        return (auth()->user()?->hasPermission('leave.manage') ?? false) && LeaveEncashmentResource::canViewAny();
    }

    /** The employees one may encash for: in the access scope, not one's own. */
    public static function candidates(): Builder
    {
        $me = auth()->id();

        return Employee::query()->with('person')->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $me));
    }

    public static function picked(mixed $id): ?Employee
    {
        return is_numeric($id) ? Employee::query()->with('person')->find((int) $id) : null;
    }

    /** The employee to raise it for, or why not: out of scope, one's own, or no right. */
    public static function resolve(mixed $id): Employee
    {
        $user = auth()->user();
        if ($user === null || ! self::onBehalfOffered()) {
            throw new RuntimeException('Raising an encashment for an employee needs the right to manage leave.');
        }
        $employee = self::picked($id) ?? throw new RuntimeException('Choose an employee in your access scope.');
        if (app(OwnRecords::class)->isOwn($user, $employee->getKey())) {
            throw new RuntimeException('This is your own record: use "Request encashment" in My encashments.');
        }

        return $employee;
    }

    public static function needsFile(?Employee $employee, mixed $typeId): bool
    {
        $type = $employee !== null && filled($typeId) ? LeaveType::query()->find($typeId) : null;

        return $type !== null && app(LeaveAdjustments::class)->encashmentAllowance($employee, $type)['needs_file'];
    }

    /** The employee's balance and encashment rule for the chosen type (RQ-4's helper, worded for HR). */
    public static function renderAllowance(?Employee $employee, mixed $typeId): HtmlString
    {
        $type = $employee !== null && filled($typeId) ? LeaveType::query()->find($typeId) : null;
        if ($type === null) {
            return new HtmlString('');
        }
        $a = app(LeaveAdjustments::class)->encashmentAllowance($employee, $type);
        $lines = ['Available leave balance: '.LeaveEncashmentBatches::days($a['available']).'.'];
        if (! $a['allowed']) {
            $lines[] = (string) $a['reason'];
        } else {
            if ($a['year_limit'] !== null) {
                $lines[] = 'At most '.LeaveEncashmentBatches::days($a['year_limit']).' a year; '.LeaveEncashmentBatches::days($a['already']).' asked for this year.';
            }
            if ($a['per_request'] !== null) {
                $lines[] = 'At most '.LeaveEncashmentBatches::days($a['per_request']).' in one request.';
            }
            if ($a['keep'] > 0) {
                $lines[] = 'Keeps at least '.LeaveEncashmentBatches::days($a['keep']).' after encashing.';
            }
            $lines[] = 'Up to '.LeaveEncashmentBatches::days($a['max_now']).' can be encashed now.';
            if ($a['needs_file']) {
                $lines[] = 'A supporting file is required.';
            }
        }

        return new HtmlString('<div class="pos-body-sm space-y-1">'.collect($lines)->map(fn (string $l) => '<p>'.e($l).'</p>')->implode('').'</div>');
    }

    /** @return list<mixed> HROne's Applicability step: search employees, organisation and location, or "Add bulk employees" */
    private static function whoFields(): array
    {
        $is = fn (string $source) => fn (Get $get) => $get('source') === $source;
        $organisation = [];
        foreach (EmployeeFilters::ORGANISATION as $field => [$label, $model]) {
            $organisation[] = Select::make($field)->label($label)->multiple()->searchable()->preload()
                ->options(fn () => $model::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all());
        }

        return [
            Radio::make('source')->label('Who')->options(LeaveEncashmentBatches::SOURCES)->required()->live(),
            Select::make('employee_ids')->label('Search employees')->multiple()->searchable()->visible($is('employees'))->required($is('employees'))
                ->helperText('Search by name, code, designation or e-mail; add as many as you need.')
                ->getSearchResultsUsing(fn (string $search) => Employee::query()->with('person')->employed()->where(fn (Builder $q) => EmployeeFilters::search()($q, $search))
                    ->orderBy('employee_code')->limit(50)->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all())
                ->getOptionLabelsUsing(fn (array $values) => Employee::query()->with('person')->whereIn('id', array_map('intval', $values))->get()->mapWithKeys(fn (Employee $e) => [$e->getKey() => $e->auditLabel()])->all()),
            Grid::make(2)->visible($is('filters'))->schema([
                ...$organisation,
                Toggle::make('everyone')->label('Everyone in my access (no other filter needed)')->columnSpanFull(),
            ]),
            Textarea::make('codes')->label('Employee codes')->rows(3)->placeholder('e.g. EMP001, EMP002')->visible($is('codes'))->required($is('codes'))
                ->helperText('Up to '.EmployeeFilters::codesMax().' codes, separated by commas, spaces or new lines.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the "who" of the form, as LeaveEncashmentBatches::employees() reads it
     */
    private static function who(array $data): array
    {
        $who = ['source' => $data['source'] ?? null];
        match ($who['source']) {
            'employees' => $who['employee_ids'] = array_values(array_map('intval', (array) ($data['employee_ids'] ?? []))),
            'codes' => $who['codes'] = (string) ($data['codes'] ?? ''),
            'filters' => $who += ['everyone' => (bool) ($data['everyone'] ?? false)] + array_map(fn ($v) => array_values(array_map('intval', (array) $v)), array_intersect_key($data, EmployeeFilters::ORGANISATION)),
            default => null,
        };

        return $who;
    }

    /** @return array<string, mixed> the wizard's state */
    private static function state(Get $get): array
    {
        $state = [];
        foreach (['leave_type_id', 'pay_month', 'mode', 'specified_days', 'source', 'employee_ids', 'codes', 'everyone', ...array_keys(EmployeeFilters::ORGANISATION)] as $key) {
            $state[$key] = $get($key);
        }

        return $state;
    }

    /** @return array{error?: string, lines?: list<array<string, mixed>>, skipped?: list<array<string, mixed>>, period?: int} */
    private static function review(Get $get): array
    {
        $state = self::state($get);
        // Memo for this request only (the review and the typed-count field read it several times per render).
        $key = 'pos.encashment_review.'.md5(json_encode($state).'|'.auth()->id());
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }

        $review = (function () use ($state) {
            $type = filled($state['leave_type_id']) ? LeaveType::query()->find((int) $state['leave_type_id']) : null;
            if ($type === null) {
                return ['error' => 'Choose the leave type first.'];
            }
            try {
                return app(LeaveEncashmentBatches::class)->preview($type, (string) ($state['mode'] ?? ''), filled($state['specified_days']) ? (float) $state['specified_days'] : null, self::who($state), auth()->user());
            } catch (RuntimeException|AuthorizationException $e) {
                return ['error' => $e->getMessage()];
            }
        })();
        request()->attributes->set($key, $review);

        return $review;
    }

    private static function lineCount(Get $get): int
    {
        return count(self::review($get)['lines'] ?? []);
    }

    private static function renderReview(Get $get): HtmlString
    {
        $review = self::review($get);
        if (isset($review['error'])) {
            return new HtmlString('<p class="pos-body-sm">'.e($review['error']).'</p>');
        }
        $state = self::state($get);
        $type = LeaveType::query()->find((int) $state['leave_type_id']);
        $mode = ($state['mode'] ?? 'policy') === 'specified' ? LeaveEncashmentBatches::days((float) $state['specified_days']).' each (never above what the rule allows)' : 'As per policy';
        $lines = $review['lines'] ?? [];
        $skipped = $review['skipped'] ?? [];
        $summary = [
            'Leave type' => (string) $type?->name, 'Leave encash' => $mode, 'Pay month' => PayMonth::label($state['pay_month'] ?? null),
            'Leave year' => (string) ($review['period'] ?? ''), 'Applicability' => LeaveEncashmentBatches::SOURCES[$state['source'] ?? ''] ?? '—',
            'Selected employees' => count($lines).' to encash, '.LeaveEncashmentBatches::days(array_sum(array_column($lines, 'days'))).' in all'.($skipped !== [] ? '; '.count($skipped).' skipped' : ''),
            'Approval' => app(LeaveEncashmentBatches::class)->approvalRequired() ? 'Another person who manages leave approves it before the encashments are made' : 'None: made when you process',
        ];
        $html = '<dl class="pos-body-sm grid grid-cols-1 gap-1">'.collect($summary)->map(fn (string $v, string $k) => '<div><dt class="inline font-medium">'.e($k).':</dt> <dd class="inline">'.e($v).'</dd></div>')->implode('').'</dl>';
        if ($lines !== []) {
            $html .= '<div class="mt-3 overflow-x-auto" tabindex="0" role="region" aria-label="Days per employee"><table class="pos-body-sm w-full"><thead><tr><th class="text-start">Employee</th><th class="text-end">Days</th><th class="text-start">Note</th></tr></thead><tbody>'
                .collect($lines)->take(50)->map(fn (array $l) => '<tr><td>'.e($l['label']).'</td><td class="text-end">'.e(rtrim(rtrim(number_format((float) $l['days'], 2), '0'), '.')).'</td><td>'.e((string) ($l['note'] ?? '')).'</td></tr>')->implode('')
                .'</tbody></table>'.(count($lines) > 50 ? '<p class="text-gray-500">… and '.(count($lines) - 50).' more.</p>' : '').'</div>';
        }
        if ($skipped !== []) {
            $html .= '<p class="mt-3 font-medium pos-body-sm">Skipped</p><ul class="pos-body-sm space-y-1">'
                .collect($skipped)->take(50)->map(fn (array $s) => '<li>'.e($s['label']).' — '.e($s['reason']).'</li>')->implode('')
                .(count($skipped) > 50 ? '<li class="text-gray-500">… and '.(count($skipped) - 50).' more.</li>' : '').'</ul>';
        }
        if ($lines === []) {
            $html .= '<p class="mt-3 pos-body-sm">Nobody can encash this leave now: go back and change the leave type or who is included.</p>';
        }

        return new HtmlString($html);
    }
}
