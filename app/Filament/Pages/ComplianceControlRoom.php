<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\EstablishmentStatutoryProfile;
use App\Domain\Compliance\Models\StatutoryRegistration;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Models\TdsCertificate;
use App\Domain\Compliance\Services\ComplianceReadiness;
use App\Domain\Compliance\Services\ComplianceRules;
use App\Domain\Organisation\Models\Establishment;
use App\Filament\Resources\EpfReturns\EpfReturnResource;
use App\Filament\Resources\EsiReturns\EsiReturnResource;
use App\Filament\Resources\LwfReturns\LwfReturnResource;
use App\Filament\Resources\ProfessionalTaxReturns\ProfessionalTaxReturnResource;
use App\Filament\Resources\TdsReturns\TdsReturnResource;
use App\Filament\Support\StatutoryReturnResourceBase;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Compliance Control Room (Phase 5 Part O), the statutory counterpart of the Payroll Control Room:
 * rule verification, registrations per establishment, every statutory output with its lifecycle
 * status and the production gate. EXPORTED is shown apart from SUBMITTED: nothing is filed until a
 * person records the portal reference.
 */
class ComplianceControlRoom extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Control room';

    protected static ?string $title = 'Compliance control room';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.compliance-control-room';

    /** @var array<string, class-string<StatutoryReturnResourceBase>> */
    public const RESOURCES = ['EPF' => EpfReturnResource::class, 'ESI' => EsiReturnResource::class, 'PT' => ProfessionalTaxReturnResource::class, 'LWF' => LwfReturnResource::class, 'TDS' => TdsReturnResource::class];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasPermission('compliance.returns.view') || $user->hasPermission('compliance.view'));
    }

    public function getSubheading(): ?string
    {
        return (ComplianceRules::enforced() ? 'Verified-rule enforcement is ON.' : 'Verified-rule enforcement is OFF (development / test only).')
            .' Exported files are not filings: a return is filed only when its portal reference is recorded.';
    }

    /** @return array<string, int> */
    public function getRuleSummary(): array
    {
        return ComplianceRule::query()->where('status', 'active')->get()->countBy('verification_status')->all() + array_fill_keys(ComplianceRule::STATUSES, 0);
    }

    /** @return array<string, int> */
    public function getReturnSummary(): array
    {
        $base = StatutoryReturn::query();

        return [
            'blocking' => (clone $base)->whereIn('status', StatutoryReturn::EDITABLE)->where('blocking_count', '>', 0)->count(),
            'awaiting_approval' => (clone $base)->where('status', StatutoryReturn::VALIDATED)->count(),
            'exported_not_filed' => (clone $base)->where('status', StatutoryReturn::EXPORTED)->count(),
            'reconciliation_required' => (clone $base)->where('status', StatutoryReturn::RECONCILIATION_REQUIRED)->count(),
            'certificates_to_issue' => TdsCertificate::query()->where('status', 'generated')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function getEstablishments(): array
    {
        return Establishment::query()->with('legalEntity')->where('status', 'active')->orderBy('name')->get()->map(function (Establishment $e) {
            $profiles = EstablishmentStatutoryProfile::query()->where('establishment_id', $e->id)->currentlyEffective()->get();
            $registrations = StatutoryRegistration::query()->where('legal_entity_id', $e->legal_entity_id)->where(fn ($q) => $q->where('establishment_id', $e->id)->orWhereNull('establishment_id'))->where('status', 'active')->pluck('registration_type')->all();
            $needs = ['EPF' => 'epf_establishment_code', 'ESI' => 'esic_employer_code', 'PT' => 'pt_registration_certificate', 'LWF' => 'lwf_registration', 'TDS' => 'tan'];

            return [
                'establishment' => $e,
                'profiles' => $profiles->isEmpty() ? 'Legacy company profile' : $profiles->where('applicable', true)->pluck('statute')->implode(', '),
                'missing' => $profiles->where('applicable', true)->pluck('statute')->reject(fn ($s) => in_array($needs[$s], $registrations, true))->values()->all(),
            ];
        })->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => StatutoryReturn::query()->with(['establishment', 'legalEntity']))
            ->columns([
                TextColumn::make('return_type')->label('Type')->badge(),
                TextColumn::make('form_code')->label('Form')->formatStateUsing(fn (string $state, StatutoryReturn $record) => $state.($record->legacy_form_code ? " ({$record->legacy_form_code})" : '')),
                TextColumn::make('period_key')->label('Period')->sortable(),
                TextColumn::make('establishment.name')->label('Establishment')->placeholder('Legal entity'),
                TextColumn::make('status')->badge()->color(fn (string $state) => StatutoryReturnResourceBase::statusColor($state))->formatStateUsing(fn (string $state) => config("peopleos.compliance.return_statuses.{$state}", $state)),
                TextColumn::make('blocking_count')->label('Blocking')->badge()->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('reconciliation_status')->label('Reconciliation')->placeholder('—'),
                IconColumn::make('ready')->label('Production gate')->boolean()->state(fn (StatutoryReturn $record) => app(ComplianceReadiness::class)->forReturn($record)['ready'])
                    ->tooltip(fn (StatutoryReturn $record) => collect(app(ComplianceReadiness::class)->forReturn($record)['checks'])->reject(fn ($c) => $c['passed'])->pluck('check')->implode(', ') ?: 'All controls satisfied'),
            ])
            ->filters([
                SelectFilter::make('return_type')->label('Type')->options(collect(config('peopleos.compliance.return_types'))->map(fn ($t) => $t['label'])->all()),
                SelectFilter::make('status')->options(config('peopleos.compliance.return_statuses')),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->recordActions([
                Action::make('open')->icon('heroicon-m-arrow-top-right-on-square')->url(fn (StatutoryReturn $record) => (self::RESOURCES[$record->return_type])::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No statutory returns yet')
            ->emptyStateDescription('Generate EPF, ESI, PT, LWF or TDS returns from finalized payroll.');
    }
}
