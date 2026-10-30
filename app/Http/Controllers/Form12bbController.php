<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Models\EmployeeTaxDeclaration;
use App\Domain\Compliance\Models\TdsEmployeeInvestment;
use App\Domain\Compliance\Services\TaxSettings;
use App\Domain\Compliance\Support\InvestmentCatalogue;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Form 12BB for a tax declaration (HROne parity PY-13): the employee's claims (rent and landlords, home-loan
 * interest, Chapter VI-A items) and previous-employer details, printable from the browser. Signed link →
 * authenticated user → tenant → declaration policy → audit (it shows landlord PANs) → render.
 */
class Form12bbController extends Controller
{
    public static function url(EmployeeTaxDeclaration $declaration): string
    {
        return URL::temporarySignedRoute('tax.form-12bb', now()->addMinutes(30), ['declaration' => $declaration->getKey()]);
    }

    /** HROne setup parity (PS-2): payroll always; the employee as the organisation decides (never / after submitting / always). */
    public static function visibleFor(EmployeeTaxDeclaration $declaration, ?User $user): bool
    {
        if ($user?->hasPermission('payroll.view')) {
            return true;
        }

        return match (app(TaxSettings::class)->form12bbVisibility()) {
            'never' => false, 'after_submit' => $declaration->status !== 'draft', default => true,
        };
    }

    public function __invoke(Request $request, int $declaration, AuditRecorder $audit): View
    {
        abort_unless($request->hasValidSignature(), 403);
        $declaration = EmployeeTaxDeclaration::query()->with('employee.person')->findOrFail($declaration);
        Gate::authorize('view', $declaration);
        abort_unless(self::visibleFor($declaration, $request->user()), 403);

        $items = TdsEmployeeInvestment::query()->where('employee_id', $declaration->employee_id)->where('financial_year', $declaration->financial_year)->orderBy('section')->orderBy('id')->get();
        $rent = $items->firstWhere('item_key', 'HRA_RENT.rent');
        $audit->record(AuditAction::Export, 'compliance', $declaration, metadata: ['document' => 'form_12bb', 'financial_year' => $declaration->financial_year], reason: 'Form 12BB printed');

        return view('compliance.form-12bb', [
            'declaration' => $declaration,
            'employee' => $declaration->employee,
            'pan' => $declaration->employee?->statutoryDetail?->pan,
            'rent' => $rent,
            'landlords' => $rent ? [['name' => $rent->details['landlord_name'] ?? null, 'pan' => $rent->details['landlord_pan'] ?? null], ...($rent->details['landlords'] ?? [])] : [],
            'housing' => $items->where('section', '24B'),
            'chapterVia' => $items->reject(fn ($i) => in_array($i->section, ['HRA_RENT', '24B', 'OTHER_INCOME'], true))->groupBy('section'),
            'otherIncome' => $items->where('section', 'OTHER_INCOME'),
            'sections' => InvestmentCatalogue::SECTIONS,
        ]);
    }
}
