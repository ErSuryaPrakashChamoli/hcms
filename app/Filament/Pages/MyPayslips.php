<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\MyPay;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Resources\MyTaxCertificates\MyTaxCertificateResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\Pages\Concerns\ConfirmsPayPassword;
use App\Http\Middleware\ConfirmPasswordForPay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * HROne parity MS-9 (My space → Payslip): the employee's own payslips of a financial year — a "Salary <month>" card per
 * pay month (opening the issued payslip, audited there) and a "Tax <month>" card (the monthly income-tax slip read from
 * the payslip's tax snapshot; opening it is audited) — with the organisation's help text and a link to the tax
 * certificates. Only payslips of finalized or paid payroll (MyPay). Opens for the signed-in person's own employee record
 * with the payslip right; the password is asked again first while compensation.self_service.confirm_password is on.
 *
 * Not here yet: the payslip PDF, its password protection and the payslip language (BP-14). The help text setting is
 * shown now; the download appears with the PDF.
 */
class MyPayslips extends Page
{
    use ConfirmsPayPassword;

    protected static string|array $routeMiddleware = ConfirmPasswordForPay::class.':self';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyRupee;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'My payslips';

    protected static ?string $title = 'My payslips';

    protected static ?string $slug = 'my-payslips';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.my-payslips';

    #[Url]
    public string $year = '';

    #[Url]
    public string $search = '';

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
        if (! in_array($this->year, $this->years(), true)) {
            $this->year = $this->years()[0];
        }
    }

    public function updatedYear(): void
    {
        if (! in_array($this->year, $this->years(), true)) {
            $this->year = $this->years()[0];
        }
    }

    /** @return list<string> */
    public function years(): array
    {
        return app(MyPay::class)->payslipYears(auth()->user(), self::me());
    }

    /** @return Collection<int, Payslip> the year's own payslips, narrowed by the search (month, year or number) */
    public function payslips(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return app(MyPay::class)->payslips(auth()->user(), self::me(), $this->year)
            ->filter(fn (Payslip $p) => $term === '' || str_contains(mb_strtolower($p->get('period.label').' '.$p->number.' '.Carbon::create((int) $p->get('period.year'), (int) $p->get('period.month'), 1)->format('F')), $term))
            ->values();
    }

    public function salaryUrl(Payslip $payslip): string
    {
        return PayslipResource::getUrl('view', ['record' => $payslip]);
    }

    /** BP-14 seam: the payslip PDF (and its password and language) is not built yet, so there is nothing to download. */
    public function downloadUrl(Payslip $payslip): ?string
    {
        return null;
    }

    public function passwordHelp(): ?string
    {
        $text = trim((string) app(SettingsRepository::class)->get('payroll.payslip.password_help', ''));

        return $text === '' ? null : $text;
    }

    public function certificatesUrl(): ?string
    {
        return MyTaxCertificateResource::canAccess() ? MyTaxCertificateResource::getUrl('index') : null;
    }

    public function taxSlipAction(): Action
    {
        return Action::make('taxSlip')->label('Tax slip')->link()
            ->modalHeading(fn (array $arguments) => 'Tax '.($this->own($arguments)?->get('period.label') ?? ''))
            ->modalWidth(Width::Large)
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->mountUsing(function (array $arguments) {
                if (($payslip = $this->own($arguments)) !== null) {
                    app(AuditRecorder::class)->record(AuditAction::PayslipAccessed, 'payroll', $payslip, [], null, metadata: ['sensitive' => true, 'part' => 'tax_slip']);
                }
            })
            ->modalContent(fn (array $arguments) => view('filament.pages.my-payslips.tax-slip', ['slip' => ($p = $this->own($arguments)) ? MyPay::taxSlip($p) : null]));
    }

    /** The viewer's own payslip named by the action (null for anyone else's). */
    private function own(array $arguments): ?Payslip
    {
        $me = self::me();

        return $me === null ? null : app(MyPay::class)->payslip(auth()->user(), $me, (int) ($arguments['payslip'] ?? 0));
    }
}
