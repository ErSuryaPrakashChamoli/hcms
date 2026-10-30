<?php

namespace App\Filament\Resources\VariablePayRequests;

use App\Domain\Employment\Models\Employee;
use App\Domain\Payroll\Models\VariablePayRequest;
use App\Domain\Payroll\Services\VariablePay;
use App\Filament\Resources\VariablePayRequests\Pages\ListVariablePayRequests;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
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
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use UnitEnum;

/** HROne parity PY-05: incentives, bonuses and other variable pay requested for a month, approved, paid by payroll. */
class VariablePayRequestResource extends Resource
{
    protected static ?string $model = VariablePayRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Variable pay';

    protected static ?string $modelLabel = 'variable pay request';

    protected static ?int $navigationSort = 71;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return auth()->user()?->can('payroll.manage') ? 'Payroll' : 'Me';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['employee.person', 'component', 'requester', 'reviewer']);

        return auth()->user()?->can('payroll.manage') ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (VariablePayRequest $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };

        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('component.name')->label('Pay item')->badge(),
                TextColumn::make('amount')->numeric(2)->sortable(),
                TextColumn::make('pay_month')->label('Month')->sortable(),
                TextColumn::make('reason')->limit(50)->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => VariablePayRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
                TextColumn::make('requester.name')->label('Asked by')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(VariablePayRequest::STATUSES)->multiple(),
                // HROne parity PO-1: pay month, pay group, requested dates and the WO-2 employee filters (payroll side only).
                PayrollRegisters::payMonth('payroll.manage'),
                PayrollRegisters::structure('payroll.manage'),
                ...PayrollRegisters::filters('payroll.manage', ['request_dates' => ['Requested', 'created_at']]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible(fn (VariablePayRequest $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->maxLength(500)])->action($run(fn ($r, $d) => app(VariablePay::class)->approve($r, $d['note'] ?? null), 'Approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible(fn (VariablePayRequest $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => app(VariablePay::class)->reject($r, $d['note']), 'Rejected')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (VariablePayRequest $r) => in_array($r->status, ['pending', 'approved'], true) && (auth()->user()->can('payroll.manage') || $r->requested_by === auth()->id()))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])->action($run(fn ($r, $d) => app(VariablePay::class)->cancel($r, $d['reason']), 'Cancelled')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVariablePayRequests::route('/')];
    }
}
