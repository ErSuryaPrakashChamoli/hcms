<?php

namespace App\Filament\Resources\ReimbursementClaims;

use App\Domain\Claims\Models\ReimbursementClaim;
use App\Domain\Claims\Services\ReimbursementClaims;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\ReimbursementClaims\Pages\ListReimbursementClaims;
use App\Filament\Support\BulkRecordActions;
use App\Filament\Support\EmployeeFilters;
use App\Filament\Support\PayrollRegisters;
use App\Filament\Support\SendBackActions;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/** HROne parity PY-02: reimbursement claims with bills against pay-on-claim components. */
class ReimbursementClaimResource extends Resource
{
    protected static ?string $model = ReimbursementClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Reimbursement claims';

    protected static ?string $modelLabel = 'reimbursement claim';

    protected static ?int $navigationSort = 73;

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
        $query = parent::getEloquentQuery()->with(['employee.person', 'component', 'reviewer']);

        return auth()->user()?->can('payroll.manage') ? $query : $query->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (ReimbursementClaim $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();
            }
        };
        $claims = fn () => app(ReimbursementClaims::class);

        return $table
            ->columns([
                TextColumn::make('employee.person.display_name')->label('Employee')->searchable(query: EmployeeFilters::search('employee')),
                TextColumn::make('component.name')->label('Reimbursement')->badge(),
                TextColumn::make('claim_date')->date()->sortable(),
                TextColumn::make('amount')->numeric(2),
                TextColumn::make('approved_amount')->label('Approved')->numeric(2)->placeholder('—'),
                TextColumn::make('description')->limit(50)->wrap(),
                TextColumn::make('bills')->label('Bills')->html()->state(fn (ReimbursementClaim $r) => new HtmlString(e(count($r->bills ?? []).' bill(s)').'<br>'.collect($r->attachments ?? [])->values()
                    ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('claim', $r, $i)).'">'.e(basename($path)).'</a>')->implode('<br>'))),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ReimbursementClaim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'approved' => 'info', 'rejected', 'cancelled' => 'gray', default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(ReimbursementClaim::STATUSES)->multiple(),
                // HROne parity PO-1: pay group, claim dates and the WO-2 employee filters (payroll side only).
                PayrollRegisters::structure('payroll.manage'),
                ...PayrollRegisters::filters('payroll.manage', ['request_dates' => ['Claim date', 'claim_date']]),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('approve')->icon('heroicon-m-check')->color('success')->visible(fn (ReimbursementClaim $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->fillForm(fn (ReimbursementClaim $r) => ['approved_amount' => (float) $r->amount])
                    ->schema([TextInput::make('approved_amount')->label('Approve')->numeric()->minValue(0.01)->required(), Textarea::make('note')->maxLength(500)])
                    ->action($run(fn ($r, $d) => $claims()->approve($r, (float) $d['approved_amount'], $d['note'] ?? null), 'Claim approved')),
                Action::make('reject')->icon('heroicon-m-x-mark')->color('danger')->visible(fn (ReimbursementClaim $r) => $r->status === 'pending' && auth()->user()->can('approve', $r))
                    ->schema([Textarea::make('note')->required()->maxLength(500)])->action($run(fn ($r, $d) => $claims()->reject($r, $d['note']), 'Claim rejected')),
                Action::make('cancel')->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (ReimbursementClaim $r) => in_array($r->status, ['pending', 'approved'], true) && (auth()->user()->can('payroll.manage') || Employee::query()->whereKey($r->employee_id)->value('user_id') === auth()->id()))
                    ->schema([Textarea::make('reason')->required()->maxLength(500)])->action($run(fn ($r, $d) => $claims()->cancel($r, $d['reason']), 'Claim cancelled')),
                // HROne setup parity P3: a request sent back to its requester is resubmitted here.
                SendBackActions::resubmit(),
            ])
            // HROne parity RQ-1 (matrix 20 §20.1.19 #18): payroll cancels several claims at once (bulk.run + payroll.manage).
            ->toolbarActions([BulkRecordActions::selected('reimbursement_claims', ['reimbursement_claims.cancel'])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReimbursementClaims::route('/')];
    }
}
