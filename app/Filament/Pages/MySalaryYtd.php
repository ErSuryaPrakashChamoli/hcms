<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\Services\FinancialYear;
use App\Domain\Compliance\Services\PfOptions;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Payroll\Contracts\PaidPayReader;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PaidPay;
use App\Filament\Resources\MyTaxCertificates\MyTaxCertificateResource;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Http\Middleware\ConfirmPasswordForPay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity MS-10 (My space → Salary ytd): what the employee was paid in a financial year — the employee header,
 * a financial-year choice, tabs Salary (component × month grid in the financial year's order with totals, arrears and a
 * "How it is calculated" panel per component), Reimbursement (paid through payroll) and Statutory (PF, voluntary PF, ESI,
 * professional tax, LWF, overtime and bonus, each from what payroll recorded on the lines; ceilings only from verified
 * rule versions). Every figure comes from finalized or paid payroll through PaidPayReader::yearToDate — never an open run,
 * never a recomputation. Own record only (no employee id in the URL); opening it is an audited sensitive view; the
 * password is asked again first while compensation.self_service.confirm_password is on.
 *
 * Not here yet: claimed amounts and bills of reimbursements (RQ-5 claim totals), the statutory bonus detail beyond the
 * verified rule's rates (bonus rule verification), and the year's statutory form ("Form 124": its identity must be
 * verified first — the existing tax certificates are linked).
 */
class MySalaryYtd extends Page
{
    use ConfirmsPayPassword;

    protected static string|array $routeMiddleware = ConfirmPasswordForPay::class.':self';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My salary YTD';

    protected static ?string $title = 'My salary YTD';

    protected static ?string $slug = 'my-salary-ytd';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.my-salary-ytd';

    public const TABS = ['salary' => 'Salary', 'reimbursement' => 'Reimbursement', 'statutory' => 'Statutory'];

    #[Url]
    public string $year = '';

    #[Url]
    public string $tab = 'salary';

    /** @var array<string, array<string, mixed>> the years read for this request, by financial year */
    private array $read = [];

    public static function canAccess(): bool
    {
        $me = self::me();

        return $me !== null && auth()->user()->can('viewAny', Payslip::class);
    }

    public static function me(): ?Employee
    {
        return auth()->check() ? app(CurrentEmployee::class)->of(auth()->user()) : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(SensitiveAccessAuditor::class)->recordView(self::me(), 'payroll', 'salary-ytd');
        $this->normalise();
    }

    public function updatedYear(): void
    {
        $this->normalise();
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'salary';
    }

    /** @return list<string> financial years with finalized or paid pay, and the current one, newest first */
    public function years(): array
    {
        return PaidPay::financialYears(self::me())->push(app(FinancialYear::class)->label(now()))->unique()->sortDesc()->values()->all();
    }

    /** @return array<string, mixed> the year from finalized or paid payroll (PaidPayReader::yearToDate) */
    public function ytd(): array
    {
        return $this->read[$this->year] ??= app(PaidPayReader::class)->yearToDate(self::me(), $this->year);
    }

    /** @return array{name: string, code: string, company: ?string, business_unit: ?string} */
    public function header(): array
    {
        $me = self::me()->loadMissing(['person', 'currentPosition.company', 'currentPosition.businessUnit']);

        return ['name' => (string) ($me->person?->display_name ?? $me->employee_code), 'code' => (string) $me->employee_code,
            'company' => $me->currentPosition?->company?->name, 'business_unit' => $me->currentPosition?->businessUnit?->name];
    }

    /** The employee's PF choices today (wage basis, voluntary PF): configuration, shown on the PF panel. */
    public function pfOption(): ?array
    {
        $option = app(PfOptions::class)->for((int) self::me()->getKey(), now());

        return $option === null ? null : ['wage_basis' => $option->wage_basis, 'vpf_mode' => $option->vpf_mode, 'vpf_value' => $option->vpf_value === null ? null : (float) $option->vpf_value];
    }

    public function certificatesUrl(): ?string
    {
        return MyTaxCertificateResource::canAccess() ? MyTaxCertificateResource::getUrl('index') : null;
    }

    /** HROne "component panel": how a component is set up and what each month recorded, with its arrears. */
    public function howAction(): Action
    {
        return Action::make('how')->label('How it is calculated')->link()->size('sm')
            ->modalHeading(fn (array $arguments) => ($this->row($arguments)['name'] ?? 'Component').' — how it is calculated')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view('filament.pages.my-salary-ytd.how', ['row' => $this->row($arguments), 'months' => $this->ytd()['months']]));
    }

    /** A row of this year's grid by its key (null for an unknown key). */
    private function row(array $arguments): ?array
    {
        $key = (string) ($arguments['row'] ?? '');
        $ytd = $this->ytd();

        return collect($ytd['salary'])->flatten(1)->merge($ytd['reimbursement'])->firstWhere('key', $key);
    }

    private function normalise(): void
    {
        $years = $this->years();
        if (! in_array($this->year, $years, true)) {
            $this->year = $years[0];
        }
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'salary';
        }
    }
}
