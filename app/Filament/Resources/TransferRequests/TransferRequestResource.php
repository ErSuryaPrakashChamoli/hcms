<?php

namespace App\Filament\Resources\TransferRequests;

use App\Domain\Employment\Models\TransferRequest;
use App\Domain\Employment\Services\TransferRequests;
use App\Filament\Resources\TransferRequests\Pages\ListTransferRequests;
use App\Filament\Support\EmployeeFilters;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;
use UnitEnum;

/** HROne setup parity (Workforce → Transfer policy): transfers requested, approved by someone else, then applied. */
class TransferRequestResource extends Resource
{
    protected static ?string $model = TransferRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Transfer requests';

    protected static ?string $modelLabel = 'transfer request';

    protected static ?int $navigationSort = 61;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('employee.position') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (TransferRequest $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
            }
        };
        $service = fn () => app(TransferRequests::class);
        $decidable = fn (TransferRequest $r) => $r->status === 'pending' && (int) $r->requested_by !== (int) auth()->id();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee.person', 'reason', 'requester', 'reviewer', 'newManager.person']))
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee'))->description(fn (TransferRequest $r) => $r->employee?->employee_code),
                TextColumn::make('effective_date')->label('From')->date()->sortable(),
                TextColumn::make('changes')->label('Changes')->state(fn (TransferRequest $r) => $r->summary())->wrap(),
                TextColumn::make('reason.name')->label('Reason')->placeholder('—'),
                // HROne setup parity 08.6: transfer allowances and whether payroll has paid them.
                TextColumn::make('allowances')->label('Allowances')->state(fn (TransferRequest $r) => $r->allowanceSummary())->placeholder('—')->wrap()
                    ->description(fn (TransferRequest $r) => $r->allowanceSummary() === null ? null : ($r->allowances_paid_at ? 'Paid in payroll on '.$r->allowances_paid_at->format('d M Y') : ($r->status === 'approved' ? 'Due with payroll' : null))),
                TextColumn::make('requester.name')->label('Requested by')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => TransferRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(TransferRequest::STATUSES),
                // HROne parity WO-2: the shared employee filters, the request date and the effective date.
                ...EmployeeFilters::forRegister('employee', 'created_at', 'Requested'),
                EmployeeFilters::dateRange('effective_dates', 'Effective', 'effective_date')])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible($decidable)
                    ->schema([Textarea::make('note')->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $service()->approve($r, $d['note'] ?? null, auth()->user()), 'Transfer approved and applied')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible($decidable)
                    ->schema([Textarea::make('note')->required()->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $service()->reject($r, $d['note'], auth()->user()), 'Transfer rejected')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')->visible(fn (TransferRequest $r) => $r->status === 'pending')
                    ->schema([Textarea::make('note')->label('Reason')->required()->maxLength(1000)])
                    ->action($run(fn ($r, $d) => $service()->cancel($r, $d['note'], auth()->user()), 'Request cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTransferRequests::route('/')];
    }
}
