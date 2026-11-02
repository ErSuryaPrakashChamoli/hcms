<?php

namespace App\Filament\Resources\LeaveEncashmentBatches;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Models\LeaveEncashment;
use App\Domain\Leave\Models\LeaveEncashmentBatch;
use App\Domain\Leave\Services\LeaveEncashmentBatches;
use App\Domain\Leave\Support\PayMonth;
use App\Filament\Resources\LeaveEncashmentBatches\Pages\ListLeaveEncashmentBatches;
use App\Filament\Resources\LeaveEncashmentBatches\Pages\ViewLeaveEncashmentBatch;
use App\Filament\Resources\LeaveEncashmentBatches\RelationManagers\EncashmentsRelationManager;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity PO-8 (matrix 25 §25.6.14, HR Desk → Payroll → "Encash leaves"): the encashment batches HR made — leave
 * type, encashment month and date, employees, by whom, pay month — with HROne's filters (encashment date, encashed by, pay
 * month), the "Encash leaves" wizard (`?action=encashLeaves`), approval by a second person, and "Unprocess" on the batch.
 * Opens for leave viewers; making, deciding and unprocessing need leave.manage (LeaveEncashmentBatches checks again).
 */
class LeaveEncashmentBatchResource extends Resource
{
    protected static ?string $model = LeaveEncashmentBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Encash leaves';

    protected static ?string $modelLabel = 'encashment batch';

    protected static ?string $pluralModelLabel = 'encashment batches';

    protected static ?string $slug = 'leave-encashment-batches';

    protected static ?int $navigationSort = 66;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! (auth()->user()?->hasPermission('leave.manage') ?? false)) {
            return null;
        }
        $count = LeaveEncashmentBatch::query()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['leaveType', 'creator', 'approver']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Batch')->columns(3)->schema([
                TextEntry::make('leaveType.name')->label('Leave type'),
                TextEntry::make('encash')->label('Leave encash')->state(fn (LeaveEncashmentBatch $record) => $record->encashLabel()),
                TextEntry::make('pay_month')->label('Pay month')->state(fn (LeaveEncashmentBatch $record) => $record->payMonthLabel()),
                TextEntry::make('period_year')->label('Leave year'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state) => LeaveEncashmentBatch::STATUSES[$state] ?? $state)->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('employee_count')->label('Employees in the batch'),
                TextEntry::make('creator.name')->label('Leave encash by')->placeholder('—'),
                TextEntry::make('created_at')->label('Made on')->dateTime(),
                TextEntry::make('processed_at')->label('Leave encashment date')->dateTime()->placeholder('Not processed yet'),
                TextEntry::make('approver.name')->label('Decided by')->placeholder('—'),
                TextEntry::make('approved_at')->label('Decided on')->dateTime()->placeholder('—'),
                TextEntry::make('decision_note')->label('Decision note')->placeholder('—'),
                TextEntry::make('encashed')->label('Encashed')->state(fn (LeaveEncashmentBatch $record) => $record->processed_at === null ? '—'
                    : $record->encashed_count.' employee(s), '.LeaveEncashmentBatches::days((float) $record->encashed_days)),
                TextEntry::make('active')->label('Not undone')->state(fn (LeaveEncashmentBatch $record) => self::activeSummary($record))->visible(fn (LeaveEncashmentBatch $record) => $record->processed_at !== null),
                TextEntry::make('note')->label('Comments')->placeholder('—'),
                TextEntry::make('unprocessed')->label('Last unprocessed')->columnSpan(3)->visible(fn (LeaveEncashmentBatch $record) => $record->unprocessed_at !== null)
                    ->state(fn (LeaveEncashmentBatch $record) => $record->unprocessed_at?->format('d M Y H:i').' by '.($record->unprocessor?->name ?? '—').': '.$record->unprocess_reason),
                TextEntry::make('applicability')->label('Applicability')->columnSpan(3)->state(fn (LeaveEncashmentBatch $record) => self::whoLabel((array) $record->applicability)),
            ]),
            Section::make('Skipped')->collapsible()->visible(fn (LeaveEncashmentBatch $record) => self::skipped($record) !== [])->schema([
                TextEntry::make('skipped_list')->hiddenLabel()->html()->state(fn (LeaveEncashmentBatch $record) => self::skippedHtml($record)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Batch')->formatStateUsing(fn ($state) => '#'.$state)->sortable(),
                TextColumn::make('leaveType.name')->label('Leave type')->badge(),
                TextColumn::make('encash')->label('Leave encash')->state(fn (LeaveEncashmentBatch $record) => $record->encashLabel()),
                TextColumn::make('pay_month')->label('Pay month')->state(fn (LeaveEncashmentBatch $record) => $record->payMonthLabel())->sortable(query: fn (Builder $q, string $direction) => $q->orderBy('pay_month', $direction)),
                TextColumn::make('employee_count')->label('No of employees')->numeric(),
                TextColumn::make('encashed_days')->label('Days encashed')->numeric(1)->toggleable(),
                TextColumn::make('creator.name')->label('Leave encash by')->placeholder('—'),
                TextColumn::make('processed_at')->label('Leave encashment date')->date()->placeholder('—')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => LeaveEncashmentBatch::STATUSES[$state] ?? $state)->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('approver.name')->label('Decided by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Made on')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(LeaveEncashmentBatch::STATUSES),
                SelectFilter::make('leave_type_id')->label('Leave type')->relationship('leaveType', 'name')->preload(),
                // HROne's filters: Encashment date, Encashed by, Pay month.
                EmployeeFilters::dateRange('encashment_dates', 'Encashment date', 'processed_at'),
                SelectFilter::make('created_by')->label('Encashed by')->searchable()
                    ->options(fn () => User::query()->whereIn('id', LeaveEncashmentBatch::query()->select('created_by'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('pay_month')->label('Pay month')
                    ->options(fn () => LeaveEncashmentBatch::query()->whereNotNull('pay_month')->distinct()->orderByDesc('pay_month')->pluck('pay_month')
                        ->mapWithKeys(fn (string $m) => [$m => PayMonth::label($m)])->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
            ]);
    }

    /** "Approve": the second person (leave.manage, not who made it, not in it, every employee in reach). */
    public static function approveAction(): Action
    {
        return Action::make('approveBatch')->label('Approve')->icon('heroicon-m-check')->color('success')
            ->visible(fn (LeaveEncashmentBatch $record) => $record->status === 'pending' && ($user = auth()->user()) !== null && app(LeaveEncashmentBatches::class)->approvalRefusal($record, $user) === null)
            ->modalHeading('Approve the encashment batch')
            ->modalDescription(fn (LeaveEncashmentBatch $record) => 'Creates '.$record->employee_count.' approved encashment(s) of '.($record->leaveType?->name ?? 'leave').' and takes the days off the balances. Each employee is checked again against the encashment rule now.')
            ->schema([Textarea::make('note')->label('Note')->maxLength(500)])
            ->action(fn (LeaveEncashmentBatch $record, array $data) => self::run(fn () => app(LeaveEncashmentBatches::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Encashment batch approved'));
    }

    public static function rejectAction(): Action
    {
        return Action::make('rejectBatch')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
            ->visible(fn (LeaveEncashmentBatch $record) => $record->status === 'pending' && (auth()->user()?->hasPermission('leave.manage') ?? false) && (int) $record->created_by !== (int) auth()->id())
            ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(500)])
            ->action(fn (LeaveEncashmentBatch $record, array $data) => self::run(fn () => app(LeaveEncashmentBatches::class)->reject($record, auth()->user(), (string) $data['note']), 'Encashment batch rejected'));
    }

    public static function withdrawAction(): Action
    {
        return Action::make('withdrawBatch')->label('Withdraw')->icon('heroicon-m-arrow-uturn-left')->color('gray')
            ->visible(fn (LeaveEncashmentBatch $record) => $record->status === 'pending' && (auth()->user()?->hasPermission('leave.manage') ?? false))
            ->schema([Textarea::make('reason')->required()->maxLength(500)])
            ->action(fn (LeaveEncashmentBatch $record, array $data) => self::run(fn () => app(LeaveEncashmentBatches::class)->withdraw($record, auth()->user(), (string) $data['reason']), 'Encashment batch withdrawn'));
    }

    /** HROne's "Unprocess" ("Do you want to unprocess?" · All employees / Select employee). */
    public static function unprocessAction(): Action
    {
        return Action::make('unprocessBatch')->label('Unprocess')->icon('heroicon-m-arrow-uturn-left')->color('danger')
            ->visible(fn (LeaveEncashmentBatch $record) => $record->status === 'processed' && (auth()->user()?->hasPermission('leave.manage') ?? false))
            ->modalHeading('Do you want to unprocess?')
            ->modalDescription('Cancels the batch\'s encashments that are not paid yet and gives the days back to the balances. Paid encashments, and ones a payroll run being prepared already carries, stay.')
            ->modalSubmitActionLabel('Yes, unprocess')
            ->schema(fn (LeaveEncashmentBatch $record) => [
                Radio::make('who')->label('Unprocess for')->options(['all' => 'All employees', 'some' => 'Select employee'])->default('all')->required()->live(),
                Select::make('employee_ids')->label('Employees')->multiple()->searchable()->required(fn (Get $get) => $get('who') === 'some')->visible(fn (Get $get) => $get('who') === 'some')
                    ->options(fn () => self::unprocessable($record)),
                Textarea::make('reason')->required()->maxLength(500),
            ])
            ->action(function (LeaveEncashmentBatch $record, array $data) {
                try {
                    $ids = ($data['who'] ?? 'all') === 'some' ? array_values(array_map('intval', (array) ($data['employee_ids'] ?? []))) : null;
                    $out = app(LeaveEncashmentBatches::class)->unprocess($record, auth()->user(), $ids, (string) $data['reason']);
                    $kept = collect($out['kept'])->map(fn (array $k) => $k['label'].': '.$k['reason'])->take(10)->implode("\n");
                    Notification::make()->{$out['cancelled'] > 0 ? 'success' : 'warning'}()
                        ->title($out['cancelled'].' encashment(s) cancelled')
                        ->body(trim(($out['kept'] !== [] ? count($out['kept'])." left as they are:\n".$kept : '').($out['out_of_scope'] > 0 ? "\n".$out['out_of_scope'].' outside your access left as they are.' : '')) ?: null)
                        ->send();
                } catch (RuntimeException|AuthorizationException $e) {
                    Notification::make()->danger()->title('Not unprocessed')->body($e->getMessage())->send();
                }
            });
    }

    /** @return array<int, string> employee id => label, for the batch's encashments not cancelled (in the viewer's scope) */
    public static function unprocessable(LeaveEncashmentBatch $batch): array
    {
        return LeaveEncashment::query()->with('employee.person')->where('leave_encashment_batch_id', $batch->id)->where('status', 'approved')->get()
            ->mapWithKeys(fn (LeaveEncashment $e) => [(int) $e->employee_id => (string) $e->employee?->auditLabel()])->all();
    }

    /** @param  array<string, mixed>  $who */
    public static function whoLabel(array $who): string
    {
        return match ($who['source'] ?? null) {
            'employees' => count((array) ($who['employee_ids'] ?? [])).' employee(s) searched and added',
            'codes' => count(preg_split('/[\s,;]+/', trim((string) ($who['codes'] ?? ''))) ?: []).' pasted employee code(s)',
            'filters' => ($who['everyone'] ?? false) && count(array_intersect_key($who, EmployeeFilters::ORGANISATION)) === 0 ? 'Everyone in the maker\'s access'
                : collect(EmployeeFilters::ORGANISATION)->filter(fn ($def, string $key) => ! empty($who[$key]))
                    ->map(fn (array $def, string $key) => $def[0].': '.$def[1]::query()->whereIn('id', (array) $who[$key])->orderBy('name')->pluck('name')->implode(', '))->implode(' · '),
            default => '—',
        };
    }

    /** @return list<array{employee_id: int, reason: string, when: string}> skipped at review and at processing */
    public static function skipped(LeaveEncashmentBatch $batch): array
    {
        $out = array_map(fn (array $s) => ['employee_id' => (int) $s['employee_id'], 'reason' => (string) $s['reason'], 'when' => 'At review'], (array) ($batch->plan['skipped'] ?? []));
        foreach ((array) ($batch->results ?? []) as $employeeId => $result) {
            if (($result['status'] ?? null) === 'skipped') {
                $out[] = ['employee_id' => (int) $employeeId, 'reason' => (string) ($result['reason'] ?? ''), 'when' => 'At processing'];
            }
        }

        return $out;
    }

    private static function skippedHtml(LeaveEncashmentBatch $batch): HtmlString
    {
        $rows = self::skipped($batch);
        $labels = Employee::query()->with('person')->whereIn('id', array_column($rows, 'employee_id'))->get()->mapWithKeys(fn (Employee $e) => [(int) $e->id => $e->auditLabel()]);
        $visible = array_values(array_filter($rows, fn (array $r) => $labels->has($r['employee_id'])));
        $hidden = count($rows) - count($visible);

        return new HtmlString('<ul class="pos-body-sm space-y-1">'.collect($visible)->take(200)->map(fn (array $r) => '<li>'.e($labels[$r['employee_id']]).' — '.e($r['reason']).' <span class="text-gray-500">('.e(mb_strtolower($r['when'])).')</span></li>')->implode('')
            .($hidden > 0 ? '<li class="text-gray-500">'.$hidden.' outside your access.</li>' : '').'</ul>');
    }

    private static function activeSummary(LeaveEncashmentBatch $batch): string
    {
        $counts = LeaveEncashment::query()->where('leave_encashment_batch_id', $batch->id)->selectRaw('status, COUNT(*) as n')->groupBy('status')->toBase()->pluck('n', 'status');

        return collect(['approved' => 'approved, not paid yet', 'paid' => 'paid', 'cancelled' => 'unprocessed'])
            ->filter(fn ($label, string $status) => (int) ($counts[$status] ?? 0) > 0)->map(fn ($label, string $status) => (int) $counts[$status].' '.$label)->implode(' · ') ?: '—';
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'processed' => 'success', 'pending', 'processing' => 'warning', 'rejected' => 'danger', default => 'gray',
        };
    }

    /** Run a service call and tell the result (refusals as notifications). */
    public static function run(callable $call, string $done): void
    {
        try {
            $call();
            Notification::make()->success()->title($done)->send();
        } catch (RuntimeException|AuthorizationException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
        }
    }

    public static function getRelations(): array
    {
        return [EncashmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListLeaveEncashmentBatches::route('/'), 'view' => ViewLeaveEncashmentBatch::route('/{record}')];
    }
}
