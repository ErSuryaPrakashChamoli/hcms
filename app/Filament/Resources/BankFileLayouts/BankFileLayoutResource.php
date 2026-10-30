<?php

namespace App\Filament\Resources\BankFileLayouts;

use App\Domain\Payroll\Models\BankFileLayout;
use App\Domain\Payroll\Services\BankFileLayouts;
use App\Filament\Resources\BankFileLayouts\Pages\ManageBankFileLayouts;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use RuntimeException;
use UnitEnum;

/**
 * HROne setup parity BP-13 / CA-5: bank-specific salary file formats as versioned data. Drafts are edited and activated;
 * an active version never changes (a change is a new version). Paying bank accounts name a layout by its code.
 */
class BankFileLayoutResource extends Resource
{
    protected static ?string $model = BankFileLayout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Bank file layouts';

    protected static ?string $modelLabel = 'bank file layout';

    protected static ?int $navigationSort = 80;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payroll.manage') ?? false;
    }

    /** @return list<mixed> the layout form (code only when creating) */
    public static function fields(bool $creating): array
    {
        return [
            ...($creating ? [TextInput::make('code')->required()->maxLength(32)->regex('/^[A-Za-z0-9_-]{2,32}$/')->helperText('Paying accounts name the layout by this code, e.g. HDFC_SALARY.')] : []),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('format')->options(['delimited' => 'Delimited (CSV and similar)', 'fixed_width' => 'Fixed width'])->default('delimited')->required()->live()->native(false),
            Select::make('delimiter')->options([',' => 'Comma', ';' => 'Semicolon', '|' => 'Pipe |', 'tab' => 'Tab'])->default(',')->native(false)->visible(fn (Get $get) => $get('format') !== 'fixed_width'),
            Select::make('quotes')->label('Quotes around text')->options(['as_needed' => 'When a value needs them (CSV)', 'never' => 'Never'])->default('as_needed')->native(false)->visible(fn (Get $get) => $get('format') !== 'fixed_width'),
            Toggle::make('column_headings')->label('First line holds the column headings')->default(true),
            Repeater::make('columns')->label('Columns, in order')->required()->minItems(1)->columns(4)->columnSpanFull()->schema([
                Select::make('field')->label('Prints')->options(BankFileLayout::FIELDS)->required()->live()->native(false),
                TextInput::make('heading')->maxLength(60),
                TextInput::make('width')->numeric()->minValue(1)->maxValue(500)->helperText('Fixed width; for delimited, the most characters'),
                Select::make('align')->options(['left' => 'Left', 'right' => 'Right'])->default('left')->native(false),
                TextInput::make('pad')->label('Pad with')->maxLength(1)->default(' ')->helperText('e.g. a space or 0'),
                Select::make('case')->label('Letters')->options(['upper' => 'CAPITALS'])->placeholder('As recorded')->native(false),
                TextInput::make('format')->maxLength(40)->helperText(fn (Get $get) => match ($get('field')) {
                    'amount' => 'decimal (two places), whole or paise', 'payment_date' => 'A date pattern, e.g. d/m/Y or Ymd', 'transfer_type' => 'Same bank|other bank, e.g. I|N', default => null,
                }),
                TextInput::make('value')->label('Text')->maxLength(60)->visible(fn (Get $get) => $get('field') === 'literal'),
            ]),
            Textarea::make('header_lines')->label('Header lines (one per line)')->rows(2)->columnSpanFull()
                ->helperText('Placeholders: {count}, {total}, {total_paise}, {total_whole}, {payment_date:d/m/Y}, {today:Ymd}, {period}, {company}, {debit_account}, {debit_ifsc}, {debit_bank_code}, {batch}'),
            Textarea::make('footer_lines')->label('Footer lines (one per line)')->rows(2)->columnSpanFull(),
            Select::make('line_ending')->options(['lf' => 'LF (Linux, most banks)', 'crlf' => 'CRLF (Windows)'])->default('lf')->native(false),
            Select::make('extension')->label('File type')->options(['csv' => '.csv', 'txt' => '.txt'])->default('csv')->native(false),
            Textarea::make('notes')->rows(2)->maxLength(500)->columnSpanFull(),
        ];
    }

    /** @return array<string, mixed> the specification from the form */
    public static function specification(array $data): array
    {
        $lines = fn (mixed $text) => array_values(array_filter(preg_split('/\R/', (string) $text) ?: [], fn (string $l) => trim($l) !== ''));

        return ['format' => $data['format'] ?? 'delimited', 'delimiter' => $data['delimiter'] ?? ',', 'quotes' => $data['quotes'] ?? 'as_needed', 'column_headings' => (bool) ($data['column_headings'] ?? false),
            'columns' => array_values((array) ($data['columns'] ?? [])), 'header_lines' => $lines($data['header_lines'] ?? ''), 'footer_lines' => $lines($data['footer_lines'] ?? ''),
            'line_ending' => $data['line_ending'] ?? 'lf', 'extension' => $data['extension'] ?? 'csv'];
    }

    /** @return array<string, mixed> the form from a layout */
    public static function formData(BankFileLayout $layout): array
    {
        $spec = (array) $layout->specification;

        return ['name' => $layout->name, 'notes' => $layout->notes, 'format' => $spec['format'] ?? 'delimited', 'delimiter' => $spec['delimiter'] ?? ',', 'quotes' => $spec['quotes'] ?? 'as_needed', 'column_headings' => (bool) ($spec['column_headings'] ?? false),
            'columns' => (array) ($spec['columns'] ?? []), 'header_lines' => implode("\n", (array) ($spec['header_lines'] ?? [])), 'footer_lines' => implode("\n", (array) ($spec['footer_lines'] ?? [])),
            'line_ending' => $spec['line_ending'] ?? 'lf', 'extension' => $spec['extension'] ?? 'csv'];
    }

    /** Two made-up transfers through the layout, to check it before activating. */
    public static function sample(BankFileLayout $layout): string
    {
        $rows = [
            ['sequence' => 1, 'employee_code' => 'E001', 'beneficiary_name' => 'Employee One', 'bank' => 'Sample Bank', 'ifsc' => 'SMPL0000001', 'account_number' => '000111222333', 'amount' => '45250.50', 'narration' => 'Salary Sep 2026', 'transfer_type' => 'same'],
            ['sequence' => 2, 'employee_code' => 'E002', 'beneficiary_name' => 'Employee Two', 'bank' => 'Other Bank', 'ifsc' => 'OTHR0000002', 'account_number' => '999888777', 'amount' => '38000.00', 'narration' => 'Salary Sep 2026', 'transfer_type' => 'other'],
        ];

        return app(BankFileLayouts::class)->render($layout, $rows, ['payment_date' => Carbon::parse('2026-09-30'), 'period' => 'Sep 2026', 'company' => 'Sample Company',
            'debit_account' => '123456789012', 'debit_ifsc' => 'SMPL0000001', 'debit_bank_code' => 'SAL01', 'batch' => 1]);
    }

    public static function table(Table $table): Table
    {
        $run = fn (Closure $call, string $done) => function (BankFileLayout $record, array $data) use ($call, $done) {
            try {
                $call($record, $data);
                Notification::make()->success()->title($done)->send();
            } catch (RuntimeException $e) {
                Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
            }
        };
        $layouts = fn () => app(BankFileLayouts::class);

        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('version')->label('v')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('format')->state(fn (BankFileLayout $r) => ($r->specification['format'] ?? '') === 'fixed_width' ? 'Fixed width' : 'Delimited'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BankFileLayout::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'draft' => 'warning', default => 'gray'
                    }),
                TextColumn::make('activated_at')->label('Active since')->dateTime()->placeholder('—'),
                TextColumn::make('checksum')->limit(12)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('code')
            ->recordActions([
                Action::make('preview')->label('Preview')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (BankFileLayout $record) => new HtmlString('<pre class="overflow-x-auto text-xs">'.e(self::sample($record)).'</pre>')),
                Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')->modalWidth('6xl')
                    ->visible(fn (BankFileLayout $record) => $record->status === 'draft' && auth()->user()->can('update', $record))
                    ->fillForm(fn (BankFileLayout $record) => self::formData($record))->schema(self::fields(false))
                    ->action($run(fn ($r, $d) => $layouts()->updateDraft($r, ['name' => $d['name'], 'notes' => $d['notes'] ?? null, 'specification' => self::specification($d)], auth()->user()), 'Draft saved')),
                Action::make('activate')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (BankFileLayout $record) => $record->status === 'draft' && auth()->user()->can('update', $record))
                    ->modalDescription('From now on the bank file uses this version for every account with this layout; the active version is retired. An active layout never changes.')
                    ->schema([Textarea::make('reason')->maxLength(500)])
                    ->action($run(fn ($r, $d) => $layouts()->activate($r, auth()->user(), $d['reason'] ?? null), 'Layout active')),
                Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')
                    ->visible(fn (BankFileLayout $record) => $record->status !== 'draft' && auth()->user()->can('update', $record))
                    ->action($run(fn ($r) => $layouts()->newVersion($r, auth()->user()), 'Draft version created')),
                DeleteAction::make()->visible(fn (BankFileLayout $record) => $record->status === 'draft' && auth()->user()->can('delete', $record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBankFileLayouts::route('/')];
    }
}
