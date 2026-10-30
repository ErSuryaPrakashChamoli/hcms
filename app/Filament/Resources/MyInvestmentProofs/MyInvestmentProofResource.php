<?php

namespace App\Filament\Resources\MyInvestmentProofs;

use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\InvestmentProofs;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Employment\Imports\EmployeeImports;
use App\Domain\Employment\Models\Employee;
use App\Filament\Resources\MyInvestmentProofs\Pages\ListMyInvestmentProofs;
use App\Http\Controllers\ParityFileController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne parity PY-12: an employee's own investment proofs — one row per item declared in Declare investments, with
 * the proof to attach in the proof window and payroll's verdict. The payroll register (Compliance → TDS investment
 * proofs) is unchanged.
 */
class MyInvestmentProofResource extends Resource
{
    protected static ?string $model = TdsEmployeeInvestment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My investment proofs';

    protected static ?string $modelLabel = 'investment proof';

    protected static ?string $slug = 'my-investment-proofs';

    protected static ?int $navigationSort = 46;

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('payroll.payslip') ?? false) && Employee::query()->where('user_id', auth()->id())->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('employee_id', Employee::query()->select('id')->where('user_id', auth()->id()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(fn () => 'Attach the proof of each item you declared. Proofs can be submitted '.app(TaxSettings::class)->proofWindow().'.')
            ->columns([
                TextColumn::make('financial_year')->label('FY'),
                TextColumn::make('section')->formatStateUsing(fn (string $state) => InvestmentCatalogue::SECTIONS[$state]['code'] ?? $state),
                TextColumn::make('description')->label('Item')->wrap(),
                TextColumn::make('declared_amount')->label('Declared')->numeric(2),
                TextColumn::make('proof_claimed_amount')->label('Proof shows')->numeric(2)->placeholder('—'),
                TextColumn::make('proof_files')->label('Files')->html()
                    ->state(fn (TdsEmployeeInvestment $record) => self::fileLinks($record)),
                TextColumn::make('proof_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state, TdsEmployeeInvestment $record) => $state === 'pending' && $record->proof_submitted_at === null ? 'Proof due' : (TdsEmployeeInvestment::STATUSES[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        'verified' => 'success', 'rejected' => 'danger', default => 'warning',
                    }),
                TextColumn::make('proof_amount')->label('Accepted')->numeric(2)->placeholder('—'),
                TextColumn::make('notes')->label('Payroll note')->placeholder('—')->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('submitProof')->label(fn (TdsEmployeeInvestment $record) => $record->proof_submitted_at ? 'Replace proof' : 'Submit proof')
                    ->icon('heroicon-m-paper-clip')
                    ->visible(fn (TdsEmployeeInvestment $record) => $record->proof_status !== 'verified')
                    ->fillForm(fn (TdsEmployeeInvestment $record) => ['amount' => (float) ($record->proof_claimed_amount ?? $record->declared_amount)])
                    ->schema([
                        FileUpload::make('files')->label('Proof (image or PDF)')->multiple()->required()->disk(EmployeeImports::disk())->directory('investment-proofs')->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240)),
                        TextInput::make('amount')->label('Amount the proof shows')->prefix('₹')->numeric()->minValue(1)->required(),
                    ])
                    ->action(function (TdsEmployeeInvestment $record, array $data) {
                        try {
                            app(InvestmentProofs::class)->submit($record, array_values((array) $data['files']), (float) $data['amount'], auth()->user());
                            Notification::make()->success()->title('Proof sent to payroll')->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /** Signed download links for an item's proof files. */
    public static function fileLinks(TdsEmployeeInvestment $record): HtmlString
    {
        $links = collect($record->proof_files ?? [])->values()
            ->map(fn (string $path, int $i) => '<a class="text-primary-600 underline" target="_blank" href="'.e(ParityFileController::url('investment-proof', $record, $i)).'">'.e(basename($path)).'</a>');

        return new HtmlString($links->isEmpty() ? '—' : $links->implode('<br>'));
    }

    public static function getPages(): array
    {
        return ['index' => ListMyInvestmentProofs::route('/')];
    }
}
