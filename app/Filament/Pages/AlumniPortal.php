<?php

namespace App\Filament\Pages;

use App\Domain\Alumni\Models\AlumniProfile;
use App\Domain\Alumni\Services\Alumni;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Domain\Payroll\Models\Payslip;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/** The alumni portal (§62): profile, documents that remain available, payslips, and document / reference requests. */
class AlumniPortal extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Alumni portal';

    protected static ?string $title = 'Alumni portal';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.alumni-portal';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('alumni.portal') ?? false) && self::profile() !== null;
    }

    public static function profile(): ?AlumniProfile
    {
        $me = ServiceDeskActions::me();

        return $me ? AlumniProfile::query()->with('employee.person')->where('employee_id', $me->id)->where('portal_enabled', true)->first() : null;
    }

    public function getProfile(): AlumniProfile
    {
        return self::profile();
    }

    public function getDocuments(): Collection
    {
        return EmployeeDocument::query()->with('type')->where('employee_id', $this->getProfile()->employee_id)->latest('id')->get()
            ->map(fn (EmployeeDocument $d) => ['title' => $d->title, 'type' => $d->type?->name, 'issued_on' => $d->issued_on?->toDateString(), 'url' => app(Documents::class)->downloadUrl($d)]);
    }

    public function getPayslips(): Collection
    {
        return Payslip::query()->where('employee_id', $this->getProfile()->employee_id)->latest('generated_at')->limit(12)->get()
            ->map(fn (Payslip $p) => ['label' => $p->get('period.label'), 'net' => (float) $p->get('totals.net'), 'url' => PayslipResource::getUrl('view', ['record' => $p])]);
    }

    public function getRequests(): Collection
    {
        return $this->getProfile()->requests()->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')->label('Request a document or reference')->icon(Heroicon::OutlinedDocumentPlus)->color('primary')
                ->schema([
                    Select::make('type')->options(config('peopleos.alumni.request_types'))->required(),
                    Textarea::make('details')->label('Purpose / details')->rows(3)->maxLength(1000),
                ])
                ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(Alumni::class)->request($this->getProfile(), $data['type'], $data['details'] ?? null, auth()->user()), fn ($r) => "Request {$r->number} submitted")),
        ];
    }
}
