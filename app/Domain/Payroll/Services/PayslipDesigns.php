<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\SalaryAnnexure;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollEntry;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Support\PayslipWordTemplate;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NumberFormatter;
use RuntimeException;

/**
 * Payslip designs (owner, 2026-11-27: "4–5 payslip templates the client chooses from, or upload its own payslip").
 *
 * A payslip's content is the immutable snapshot taken when its month closed (Payslips); a design is only how that
 * snapshot is printed, so changing the design re-prints every payslip, old ones included, without changing a figure.
 * Five built-in designs, an accent colour and what to show (logo, attendance, bank, IDs, employer contributions, year
 * to date, the income-tax summary, net pay in words), a title and a footer; the organisation may pick another design
 * for some salary structures (workers on Compact, managers on Detailed), and may upload its own Word layout, filled with
 * the payslip's {{ variables }} and tables and downloaded as a Word file.
 *
 * Settings live in `payroll.payslip.design` (audited like every tenant setting). Lines are always the payslip's own —
 * the pay items of the employee's salary structure, adjustments and statutory deductions — so a design never shows a
 * pay item the month did not pay; items hidden from the payslip (Pay components) stay hidden in every design.
 */
final class PayslipDesigns
{
    public const SETTING = 'payroll.payslip.design';

    /** design => [name, what it looks like] */
    public const DESIGNS = [
        'classic' => ['Classic', 'The traditional payslip: details in two columns, earnings beside deductions, net pay in words.'],
        'modern' => ['Modern', 'A coloured header with net pay in large figures; earnings and deductions as two cards.'],
        'compact' => ['Compact', 'Everything in one narrow column on half a page; quick to read and to print.'],
        'detailed' => ['Detailed', 'Classic with the year to date beside every line, employer contributions and the income-tax summary.'],
        'statement' => ['Statement', 'Totals on top, then one ledger of every line, like a bank statement.'],
    ];

    /** The organisation's own Word layout. */
    public const CUSTOM = 'custom';

    /** accent => [name, colour] (print colours, not theme tokens: the page stands alone). */
    public const ACCENTS = [
        'indigo' => ['Indigo', '#4338ca'], 'navy' => ['Navy', '#1e3a8a'], 'teal' => ['Teal', '#0f766e'], 'green' => ['Green', '#15803d'],
        'maroon' => ['Maroon', '#9f1239'], 'orange' => ['Orange', '#c2410c'], 'slate' => ['Slate', '#334155'],
    ];

    /** option => [label, default] */
    public const OPTIONS = [
        'show_logo' => ['Company logo', true],
        'show_attendance' => ['Days paid and loss of pay', true],
        'show_bank' => ['Bank and account (masked)', true],
        'show_ids' => ['PAN, UAN and ESI numbers (masked)', true],
        'show_words' => ['Net pay in words', true],
        'show_employer' => ['Employer contributions (PF, ESI …)', false],
        'show_ytd' => ['Year to date beside each line', false],
        'show_tax' => ['Income-tax summary (regime, taxable income, tax deducted)', false],
    ];

    public const DEFAULT_FOOTER = 'This is a computer-generated payslip and needs no signature.';

    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * The design settings with every default filled in.
     *
     * @return array{layout: string, accent: string, title: string, footer: string, by_structure: array<string, string>, custom: ?array<string, mixed>}&array<string, mixed>
     */
    public function settings(): array
    {
        $stored = (array) ($this->settings->get(self::SETTING) ?? []);
        $options = collect(self::OPTIONS)->mapWithKeys(fn (array $o, string $key) => [$key => (bool) ($stored[$key] ?? $o[1])])->all();
        $layout = (string) ($stored['layout'] ?? 'classic');

        return [
            'layout' => isset(self::DESIGNS[$layout]) || $layout === self::CUSTOM ? $layout : 'classic',
            'accent' => isset(self::ACCENTS[$stored['accent'] ?? '']) ? (string) $stored['accent'] : 'indigo',
            'title' => trim((string) ($stored['title'] ?? '')) ?: 'Payslip',
            'footer' => array_key_exists('footer', $stored) ? trim((string) $stored['footer']) : self::DEFAULT_FOOTER,
            'by_structure' => array_filter((array) ($stored['by_structure'] ?? []), fn ($d) => isset(self::DESIGNS[$d]) || $d === self::CUSTOM),
            'custom' => is_array($stored['custom'] ?? null) ? $stored['custom'] : null,
        ] + $options;
    }

    /**
     * Save the design (audited as a tenant setting). The custom Word file is kept unless $data says otherwise.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, User $actor, ?string $reason = null): array
    {
        abort_unless(self::mayManage($actor), 403);
        $current = $this->settings();
        $layout = (string) ($data['layout'] ?? $current['layout']);
        if (! isset(self::DESIGNS[$layout]) && $layout !== self::CUSTOM) {
            throw new RuntimeException('Choose one of the payslip designs.');
        }
        $custom = array_key_exists('custom', $data) ? $data['custom'] : $current['custom'];
        $byStructure = [];
        // Rows from the form ({structure, design}) or the saved map (code => design).
        foreach ((array) ($data['by_structure'] ?? []) as $key => $row) {
            [$code, $design] = is_array($row) ? [trim((string) ($row['structure'] ?? '')), (string) ($row['design'] ?? '')] : [trim((string) $key), (string) $row];
            if ($code !== '' && (isset(self::DESIGNS[$design]) || $design === self::CUSTOM)) {
                $byStructure[$code] = $design;
            }
        }
        if (($layout === self::CUSTOM || in_array(self::CUSTOM, $byStructure, true)) && $custom === null) {
            throw new RuntimeException('Upload your Word payslip layout before choosing it.');
        }
        $value = [
            'layout' => $layout, 'accent' => isset(self::ACCENTS[$data['accent'] ?? '']) ? (string) $data['accent'] : $current['accent'],
            'title' => mb_substr(trim((string) ($data['title'] ?? $current['title'])), 0, 60) ?: 'Payslip',
            'footer' => mb_substr(trim((string) ($data['footer'] ?? '')), 0, 300),
            'by_structure' => $byStructure, 'custom' => $custom,
        ] + collect(self::OPTIONS)->mapWithKeys(fn (array $o, string $key) => [$key => (bool) ($data[$key] ?? $current[$key])])->all();
        $this->settings->set(self::SETTING, $value, $reason ?? 'Payslip design changed');

        return $this->settings();
    }

    /** Payroll configures payslips. */
    public static function mayManage(?User $user): bool
    {
        return $user !== null && $user->hasPermission('payroll.manage');
    }

    /** The design a payslip prints in: its salary structure's own choice, else the organisation's. */
    public function designFor(Payslip $payslip, ?array $settings = null): string
    {
        $settings ??= $this->settings();
        $structure = $this->structureOf($payslip);
        $design = $structure !== null ? ($settings['by_structure'][$structure] ?? $settings['layout']) : $settings['layout'];

        return $design === self::CUSTOM && $settings['custom'] === null ? 'classic' : $design;
    }

    /**
     * Everything a design prints, ready to show: amounts are numbers, the view formats them in the payslip's currency.
     *
     * @return array<string, mixed>
     */
    public function document(Payslip $payslip, ?array $settings = null): array
    {
        $s = $settings ?? $this->settings();
        $company = $this->companyOf($payslip);
        $ytd = $s['show_ytd'] || $s['show_tax'] ? $this->yearToDate($payslip) : null;
        $lines = fn (string $key, string $type) => collect((array) $payslip->get($key, []))->map(fn (array $l) => [
            'code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? ''), 'amount' => (float) ($l['amount'] ?? 0),
            'ytd' => $ytd !== null ? round((float) ($ytd['lines'][$type][strtoupper((string) ($l['code'] ?? ''))] ?? 0), 2) : null,
        ])->values()->all();
        $year = (int) $payslip->get('period.year');
        $month = (int) $payslip->get('period.month');
        $start = $payslip->get('period.start');
        $end = $payslip->get('period.end');
        $tax = (array) ($payslip->get('tax') ?? []);
        $tds = (float) collect((array) $payslip->get('deductions', []))->whereIn('code', ['TDS', 'TAX'])->sum('amount');

        return [
            'settings' => $s, 'accent' => self::ACCENTS[$s['accent']][1], 'title' => $s['title'], 'footer' => $s['footer'],
            // Plain colours (no CSS variables or color-mix): the same page is printed by the browser and by the PDF engine.
            'accent_soft' => self::mix(self::ACCENTS[$s['accent']][1], '#ffffff', 0.88), 'accent_dark' => self::mix(self::ACCENTS[$s['accent']][1], '#0f172a', 0.35),
            'currency' => (string) ($payslip->get('currency') ?? 'INR'),
            'number' => (string) $payslip->number,
            'company' => [
                'name' => (string) $payslip->get('company.name'), 'code' => $payslip->get('company.code'),
                'place' => $company ? collect([$company->city, $company->state_code])->filter()->implode(', ') : null,
                'logo' => $s['show_logo'] ? $this->logo($company) : null,
            ],
            'period' => [
                'label' => (string) $payslip->get('period.label'),
                'month' => $year > 0 && $month > 0 ? Carbon::create($year, $month, 1)->format('F Y') : (string) $payslip->get('period.label'),
                'range' => $start && $end ? Carbon::parse($start)->format('j M Y').' – '.Carbon::parse($end)->format('j M Y') : null,
            ],
            'employee' => [
                'name' => (string) $payslip->get('employee.name'), 'code' => (string) $payslip->get('employee.code'),
                'designation' => $payslip->get('employee.designation'), 'department' => $payslip->get('employee.department'),
                'joined' => $payslip->get('employee.joining_date') ? Carbon::parse($payslip->get('employee.joining_date'))->format('j M Y') : null,
                'pan' => $payslip->get('employee.pan'), 'uan' => $payslip->get('employee.uan'), 'esic' => $payslip->get('employee.esic'),
                'bank' => $payslip->get('employee.bank.name'), 'account' => $payslip->get('employee.bank.account'),
            ],
            'days' => ['in_period' => (float) $payslip->get('days.in_period'), 'paid' => (float) $payslip->get('days.paid'), 'lop' => (float) $payslip->get('days.lop')],
            'earnings' => $lines('earnings', 'earnings'), 'deductions' => $lines('deductions', 'deductions'),
            'employer' => $s['show_employer'] ? $lines('employer_contributions', 'employer') : [],
            'totals' => [
                'gross' => (float) $payslip->get('totals.gross'), 'earnings' => (float) $payslip->get('totals.earnings'),
                'deductions' => (float) $payslip->get('totals.deductions'), 'net' => (float) $payslip->get('totals.net'),
                'employer' => (float) $payslip->get('totals.employer_cost'),
            ],
            'ytd' => $s['show_ytd'] && $ytd !== null ? $ytd['totals'] : null,
            'words' => $s['show_words'] ? (string) $payslip->get('net_in_words') : null,
            'tax' => $s['show_tax'] && $tax !== [] ? array_filter([
                'Financial year' => $tax['financial_year'] ?? null,
                'Tax regime' => match ($tax['regime'] ?? null) {
                    'new' => 'New regime', 'old' => 'Old regime', null => null, default => ucfirst((string) $tax['regime'])
                },
                'Taxable income for the year' => isset($tax['taxable_income']) ? self::money((float) $tax['taxable_income']) : null,
                'Tax for the year' => isset($tax['annual_tax']) ? self::money((float) $tax['annual_tax']) : null,
                'Deducted earlier this year' => isset($tax['already_deducted']) ? self::money((float) $tax['already_deducted']) : null,
                'Deducted this month' => self::money($tds),
            ], fn ($v) => $v !== null) : null,
            'revision' => preg_match('/-R(\d+)$/', (string) $payslip->number, $m) ? (int) $m[1] : null,
        ] + ['facts' => $this->facts($payslip, $s)];
    }

    /**
     * The employee facts a design prints, label => value; the options decide the IDs, the bank and the days.
     *
     * @return array<string, string>
     */
    private function facts(Payslip $payslip, array $s): array
    {
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $lop = (float) $payslip->get('days.lop');
        $joined = $payslip->get('employee.joining_date');

        return array_filter([
            'Employee' => $payslip->get('employee.name'), 'Employee code' => $payslip->get('employee.code'),
            'Designation' => $payslip->get('employee.designation'), 'Department' => $payslip->get('employee.department'),
            'Date of joining' => $joined ? Carbon::parse($joined)->format('j M Y') : null,
            'PAN' => $s['show_ids'] ? $payslip->get('employee.pan') : null, 'UAN' => $s['show_ids'] ? $payslip->get('employee.uan') : null,
            'ESI number' => $s['show_ids'] ? $payslip->get('employee.esic') : null,
            'Bank' => $s['show_bank'] ? $payslip->get('employee.bank.name') : null, 'Account' => $s['show_bank'] ? $payslip->get('employee.bank.account') : null,
            'Days paid' => $s['show_attendance'] ? $num($payslip->get('days.paid')).' of '.$num($payslip->get('days.in_period')) : null,
            'Loss of pay' => $s['show_attendance'] ? $num($lop).($lop === 1.0 ? ' day' : ' days') : null,
        ], fn ($v) => filled($v));
    }

    /** The payslip as a printable page in its design (or the one asked for, e.g. a preview). */
    public function html(Payslip $payslip, ?string $design = null, ?array $settings = null, bool $embedded = false, bool $pdf = false): string
    {
        $settings ??= $this->settings();
        $design ??= $this->designFor($payslip, $settings);
        $design = isset(self::DESIGNS[$design]) ? $design : 'classic';
        if ($design === 'detailed') {
            // Detailed always has the year to date, the employer contributions and the tax summary.
            $settings = ['show_ytd' => true, 'show_employer' => true, 'show_tax' => true] + $settings;
        }

        return view('payslips.designs.'.$design, ['doc' => $this->document($payslip, $settings), 'design' => $design, 'money' => self::money(...), 'embedded' => $embedded, 'pdf' => $pdf])->render();
    }

    /**
     * The payslip filled into the organisation's Word layout.
     *
     * @return array{content: string, name: string, mime: string}
     */
    public function word(Payslip $payslip, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $custom = $settings['custom'] ?? throw new RuntimeException('No Word payslip layout is uploaded.');
        $disk = Storage::disk($custom['disk'] ?? config('peopleos.documents.disk'));
        if (! $disk->exists((string) $custom['path'])) {
            throw new RuntimeException('The Word payslip layout is missing. Upload it again on Payroll → Payslip designs.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'payslip');
        file_put_contents($tmp, (string) $disk->get((string) $custom['path']));
        try {
            $content = DocxLetter::render($tmp, $this->wordContext($payslip, ['show_ytd' => true, 'show_employer' => true] + $settings));
        } finally {
            @unlink($tmp);
        }

        return ['content' => $content, 'name' => Str::slug($payslip->number).'.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    /**
     * Keep an uploaded Word layout (checked as the letter studio checks Word letters); returns the settings' custom entry.
     *
     * @return array<string, mixed>
     */
    public function storeWord(string $localPath, string $originalName, User $actor): array
    {
        abort_unless(self::mayManage($actor), 403);
        if (($problem = DocxLetter::problem($localPath)) !== null) {
            throw new RuntimeException($problem);
        }
        $unknown = array_values(array_diff(DocxLetter::variables($localPath), array_keys(PayslipWordTemplate::VARIABLES)));
        if ($unknown !== []) {
            throw new RuntimeException('The Word file uses variables a payslip does not have: '.implode(', ', array_map(fn ($v) => '{{ '.$v.' }}', $unknown)).'. See the list of payslip variables.');
        }
        $disk = config('peopleos.documents.disk');
        $path = 'tenants/'.app(TenantContext::class)->id().'/payroll/payslip-layouts/'.Str::ulid().'.docx';
        Storage::disk($disk)->put($path, (string) file_get_contents($localPath));

        return ['disk' => $disk, 'path' => $path, 'name' => mb_substr($originalName, 0, 120), 'uploaded_at' => now()->toIso8601String(), 'uploaded_by' => $actor->id,
            'variables' => DocxLetter::variables($localPath)];
    }

    /**
     * The values a Word layout's {{ variables }} take for a payslip: text, and the four tables as blocks.
     *
     * @return array<string, mixed>
     */
    public function wordContext(Payslip $payslip, ?array $settings = null): array
    {
        $doc = $this->document($payslip, $settings ?? $this->settings());
        $table = function (string $title, array $lines, float $total, string $totalLabel) use ($doc): SalaryAnnexure {
            $rows = array_map(fn (array $l) => ['kind' => 'line', 'label' => $l['name'], 'monthly' => $l['amount'], 'annual' => $l['ytd']], $lines);
            $rows[] = ['kind' => 'total', 'label' => $totalLabel, 'monthly' => $total, 'annual' => null];

            return new SalaryAnnexure($doc['currency'], $rows, [$title, 'This month', 'Year to date']);
        };
        $m = self::money(...);

        return [
            'company' => ['name' => $doc['company']['name'], 'code' => $doc['company']['code'], 'place' => $doc['company']['place']],
            'payslip' => [
                'title' => $doc['title'], 'number' => $doc['number'], 'month' => $doc['period']['month'], 'period' => $doc['period']['range'] ?? $doc['period']['label'],
                'earnings' => $table('Earnings', $doc['earnings'], $doc['totals']['earnings'], 'Total earnings'),
                'deductions' => $table('Deductions', $doc['deductions'], $doc['totals']['deductions'], 'Total deductions'),
                'employer' => $table('Employer contributions', $doc['employer'], $doc['totals']['employer'], 'Total employer contributions'),
                'footer' => $doc['footer'],
            ],
            'employee' => $doc['employee'] + ['joining_date' => $doc['employee']['joined'], 'bank_name' => $doc['employee']['bank'], 'bank_account' => $doc['employee']['account']],
            'days' => ['in_month' => self::number($doc['days']['in_period']), 'paid' => self::number($doc['days']['paid']), 'lop' => self::number($doc['days']['lop'])],
            'totals' => ['gross' => $m($doc['totals']['gross']), 'earnings' => $m($doc['totals']['earnings']), 'deductions' => $m($doc['totals']['deductions']),
                'net' => $m($doc['totals']['net']), 'employer' => $m($doc['totals']['employer'])],
            'net' => ['words' => (string) $payslip->get('net_in_words')],
        ];
    }

    /**
     * A payslip to preview designs with: the latest one in the organisation, else a made-up one (never saved).
     */
    public function sample(?User $viewer = null): Payslip
    {
        $latest = Payslip::query()->latest('generated_at')->first();
        if ($latest !== null) {
            return $latest;
        }
        $company = Company::query()->orderBy('id')->first();
        $month = now()->subMonth();
        $earnings = [['code' => 'BASIC', 'name' => 'Basic', 'amount' => 25000.0], ['code' => 'HRA', 'name' => 'House rent allowance', 'amount' => 12500.0],
            ['code' => 'CONV', 'name' => 'Conveyance', 'amount' => 1600.0], ['code' => 'SPECIAL', 'name' => 'Special allowance', 'amount' => 10900.0]];
        $deductions = [['code' => 'PF_EE', 'name' => 'Provident fund', 'amount' => 1800.0], ['code' => 'PT', 'name' => 'Professional tax', 'amount' => 200.0], ['code' => 'TDS', 'name' => 'Income tax (TDS)', 'amount' => 2350.0]];

        return new Payslip(['number' => 'PS-'.$month->format('Ym').'-SAMPLE', 'generated_at' => now(), 'snapshot' => [
            'number' => 'PS-'.$month->format('Ym').'-SAMPLE',
            'period' => ['label' => $month->format('M Y'), 'year' => $month->year, 'month' => $month->month, 'start' => $month->copy()->startOfMonth()->toDateString(), 'end' => $month->copy()->endOfMonth()->toDateString()],
            'company' => ['name' => $company?->legal_name ?: ($company?->name ?? 'Your organisation'), 'code' => $company?->code, 'id' => $company?->id],
            'employee' => ['code' => 'EMP0001', 'name' => 'Sample Employee', 'joining_date' => now()->subYears(2)->toDateString(), 'designation' => 'Senior Analyst', 'department' => 'Finance',
                'pan' => '••••••234F', 'uan' => '••••••••9012', 'esic' => null, 'bank' => ['name' => 'Sample Bank', 'account' => '••••••7890']],
            'days' => ['in_period' => $month->daysInMonth, 'paid' => $month->daysInMonth - 1, 'lop' => 1],
            'earnings' => $earnings, 'deductions' => $deductions,
            'employer_contributions' => [['code' => 'PF_ER', 'name' => 'Provident fund (employer)', 'amount' => 1800.0]],
            'totals' => ['gross' => 50000.0, 'earnings' => 50000.0, 'deductions' => 4350.0, 'net' => 45650.0, 'employer_cost' => 1800.0],
            'tax' => ['financial_year' => $month->month >= 4 ? $month->year.'-'.($month->year + 1) : ($month->year - 1).'-'.$month->year, 'regime' => 'new', 'taxable_income' => 525000, 'annual_tax' => 28200, 'already_deducted' => 14100],
            'net_in_words' => Payslips::inWords(45650).' rupees only',
            'structure' => null,
        ]]);
    }

    /** The salary structure (code) a payslip was paid on. */
    public function structureOf(Payslip $payslip): ?string
    {
        $code = $payslip->get('structure');
        if ($code === null && $payslip->exists && $payslip->payroll_entry_id) {
            $inputs = PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->whereKey($payslip->payroll_entry_id)->value('inputs');
            $code = is_array($inputs) ? ($inputs['structure'] ?? null) : (is_string($inputs) ? (json_decode($inputs, true)['structure'] ?? null) : null);
        }

        return filled($code) ? (string) $code : null;
    }

    /** "₹48,00,000" in Indian grouping, paise only when there are any. */
    public static function money(float $amount, string $currency = 'INR'): string
    {
        $fraction = abs($amount - round($amount)) >= 0.005 ? 2 : 0;
        $formatter = new NumberFormatter($currency === 'INR' ? 'en_IN' : 'en', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $fraction);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $fraction);
        $text = $formatter->formatCurrency($amount, $currency);

        return $text === false ? $currency.' '.number_format($amount, $fraction) : $text;
    }

    /** $a moved towards $b by $share (0–1), as #rrggbb. */
    private static function mix(string $a, string $b, float $share): string
    {
        [$a, $b] = [sscanf($a, '#%02x%02x%02x'), sscanf($b, '#%02x%02x%02x')];

        return vsprintf('#%02x%02x%02x', array_map(fn ($x, $y) => (int) round($x + ($y - $x) * $share), $a, $b));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * The year to date of each line (by type and code) and of the totals: this employee's payslips of the same financial
     * year (April to March) up to this month.
     *
     * @return array{lines: array<string, array<string, float>>, totals: array{earnings: float, deductions: float, net: float}}
     */
    private function yearToDate(Payslip $payslip): array
    {
        $year = (int) $payslip->get('period.year');
        $month = (int) $payslip->get('period.month');
        $fyStart = $month >= 4 ? $year : $year - 1;
        $ordinal = $year * 12 + $month;
        $slips = $payslip->exists && $payslip->employee_id
            ? Payslip::query()->withoutGlobalScope(AccessScope::class)->where('employee_id', $payslip->employee_id)->get(['id', 'snapshot'])
                ->filter(function (Payslip $p) use ($fyStart, $ordinal) {
                    $o = (int) $p->get('period.year') * 12 + (int) $p->get('period.month');

                    return $o >= $fyStart * 12 + 4 && $o <= $ordinal;
                })
            : collect([$payslip]);
        $lines = ['earnings' => [], 'deductions' => [], 'employer' => []];
        $totals = ['earnings' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
        foreach ($slips as $slip) {
            foreach (['earnings' => 'earnings', 'deductions' => 'deductions', 'employer_contributions' => 'employer'] as $key => $type) {
                foreach ((array) $slip->get($key, []) as $line) {
                    $code = strtoupper((string) ($line['code'] ?? ''));
                    $lines[$type][$code] = ($lines[$type][$code] ?? 0.0) + (float) ($line['amount'] ?? 0);
                }
            }
            $totals['earnings'] += (float) $slip->get('totals.earnings');
            $totals['deductions'] += (float) $slip->get('totals.deductions');
            $totals['net'] += (float) $slip->get('totals.net');
        }

        return ['lines' => $lines, 'totals' => array_map(fn ($v) => round($v, 2), $totals)];
    }

    private function companyOf(Payslip $payslip): ?Company
    {
        $id = $payslip->get('company.id');
        if ($id === null && $payslip->exists && $payslip->payroll_entry_id) {
            $id = PayrollEntry::query()->withoutGlobalScope(AccessScope::class)->whereKey($payslip->payroll_entry_id)
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_entries.payroll_run_id')->value('payroll_runs.company_id');
        }

        return $id ? Company::query()->find($id) : Company::query()->where('code', $payslip->get('company.code'))->first();
    }

    /** The company logo as a data URI (it lives on the private disk), when there is one of a sensible size. */
    private function logo(?Company $company): ?string
    {
        if ($company === null || blank($company->logo_path)) {
            return null;
        }
        $disk = Storage::disk(config('peopleos.documents.disk'));
        try {
            if (! $disk->exists($company->logo_path) || $disk->size($company->logo_path) > 600 * 1024) {
                return null;
            }
            $bytes = (string) $disk->get($company->logo_path);
        } catch (\Throwable) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }
}
