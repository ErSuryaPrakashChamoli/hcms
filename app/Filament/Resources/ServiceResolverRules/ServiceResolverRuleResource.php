<?php

namespace App\Filament\Resources\ServiceResolverRules;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use App\Domain\ServiceDesk\Models\ServiceResolverRule;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Models\TicketCategory;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ServiceResolverRules\Pages\CreateServiceResolverRule;
use App\Filament\Resources\ServiceResolverRules\Pages\EditServiceResolverRule;
use App\Filament\Resources\ServiceResolverRules\Pages\ListServiceResolverRules;
use App\Filament\Resources\ServiceResolverRules\Pages\ViewServiceResolverRule;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne setup parity HD-B (Helpdesk → Resolver rules): which team resolves which requests, who is intimated, and the
 * SLA (service days, working hours, due times per priority) that applies. Steps as in HROne: resolver details, define
 * SLA, review (the view page). Rules are tried by priority, then the most specific; no matching rule leaves the
 * service's and the category's routing as before.
 */
class ServiceResolverRuleResource extends Resource
{
    protected static ?string $model = ServiceResolverRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingIn;

    protected static string|UnitEnum|null $navigationGroup = 'Service Desk';

    protected static ?string $navigationLabel = 'Resolver rules';

    protected static ?string $modelLabel = 'resolver rule';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 7;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['assigneeRole', 'assigneeUser', 'notifyRole', 'slaPolicy']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resolver details')->columns(2)->schema([
                TextInput::make('name')->label('Rule name')->required()->maxLength(120),
                TextInput::make('priority')->numeric()->minValue(0)->maxValue(9999)->default(100)->required()
                    ->helperText('Lower numbers are tried first; among equals, the most specific rule wins.'),
                Select::make('category_ids')->label('Applicable categories')->multiple()->placeholder('Any category')
                    ->options(fn () => TicketCategory::query()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('service_ids')->label('Applicable subcategories (services)')->multiple()->placeholder('Any service')
                    ->options(fn () => ServiceDefinition::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('company_ids')->label('Only for employees of these companies')->multiple()->placeholder('Any company')
                    ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('location_ids')->label('Only for employees at these locations')->multiple()->placeholder('Any location')
                    ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('assignee_role_id')->label('Assigned to (role)')->required()
                    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Requests go to the least-loaded member who may work them.'),
                Select::make('assignee_user_id')->label('Default resolver (optional)')->placeholder('Least loaded member of the role')->searchable()
                    ->options(fn () => User::forCurrentTenant()->get()->filter(fn (User $u) => $u->isActive() && $u->hasPermission('servicedesk.agent'))->pluck('name', 'id')->all()),
                Select::make('notify_role_id')->label('Intimate this role when a request is assigned')->placeholder('No one')
                    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Its members who may see the request are told (at most 25).'),
            ]),
            Section::make('Define SLA')->columns(2)->schema([
                Select::make('sla_policy_id')->label('SLA policy')->placeholder('The service\'s or category\'s SLA')
                    ->options(fn () => ServiceSlaPolicy::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Its due times per priority, service days ("will not resolve on" the others) and working hours apply. Change them on SLA policies.'),
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
                DatePicker::make('effective_from')->native(false)->default(now()),
                DatePicker::make('effective_to')->native(false)->placeholder('Open-ended'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $names = fn (string $class, ?array $ids, string $any) => $ids ? $class::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') : $any;

        return $schema->components([
            Section::make('Rule details')->columns(3)->schema([
                TextEntry::make('name')->label('Rule name'),
                TextEntry::make('priority'),
                TextEntry::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextEntry::make('categories')->label('Applicable categories')->state(fn (ServiceResolverRule $record) => $names(TicketCategory::class, $record->category_ids, 'Any category')),
                TextEntry::make('services')->label('Applicable subcategories')->state(fn (ServiceResolverRule $record) => $names(ServiceDefinition::class, $record->service_ids, 'Any service')),
                TextEntry::make('where')->label('Employees of')->state(fn (ServiceResolverRule $record) => $names(Company::class, $record->company_ids, 'Any company').' · '.$names(Location::class, $record->location_ids, 'any location')),
                TextEntry::make('assigneeRole.name')->label('Assigned to'),
                TextEntry::make('assigneeUser.name')->label('Default resolver')->placeholder('Least loaded member'),
                TextEntry::make('notifyRole.name')->label('Intimated role')->placeholder('No one'),
                TextEntry::make('effective_from')->date()->placeholder('—'),
                TextEntry::make('effective_to')->date()->placeholder('Open-ended'),
            ]),
            Section::make('SLA')->columns(3)->schema([
                TextEntry::make('slaPolicy.name')->label('SLA policy')->placeholder('The service\'s or category\'s SLA'),
                TextEntry::make('non_working')->label('Resolver will not resolve tickets on')->state(fn (ServiceResolverRule $record) => self::offDays($record->slaPolicy)),
                TextEntry::make('working_hours')->label('Working hours')->state(fn (ServiceResolverRule $record) => self::workingHours($record->slaPolicy)),
                TextEntry::make('targets')->label('Due per priority (first response / resolution)')->columnSpanFull()->listWithLineBreaks()
                    ->state(fn (ServiceResolverRule $record) => $record->slaPolicy ? collect(config('peopleos.servicedesk.priorities'))->map(fn ($label, $key) => $label.': '.($t = $record->slaPolicy->targetFor($key))['first_response_hours'].' h / '.$t['resolution_hours'].' h')->values()->all() : ['—']),
                TextEntry::make('slaPolicy.escalationRole.name')->label('Escalation role')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')->sortable(),
                TextColumn::make('name')->label('Rule')->searchable(),
                TextColumn::make('category_ids')->label('Categories')->state(fn (ServiceResolverRule $record) => $record->category_ids ? count($record->category_ids).' selected' : 'Any'),
                TextColumn::make('service_ids')->label('Subcategories')->state(fn (ServiceResolverRule $record) => $record->service_ids ? count($record->service_ids).' selected' : 'Any'),
                TextColumn::make('assigneeRole.name')->label('Assigned to'),
                TextColumn::make('notifyRole.name')->label('Intimates')->placeholder('—')->toggleable(),
                TextColumn::make('slaPolicy.name')->label('SLA')->placeholder('Service / category'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->defaultSort('priority')
            ->filters([
                SelectFilter::make('id')->label('Rule')->multiple()->options(fn () => ServiceResolverRule::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive']),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function offDays(?ServiceSlaPolicy $policy): string
    {
        if ($policy?->calendar === 'calendar') {
            return 'Every day counts (calendar hours)';
        }
        $days = (array) (($policy?->business_hours ?? null)['days'] ?? config('peopleos.servicedesk.business_hours.days'));
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $off = array_diff_key($names, array_flip(array_map('intval', $days)));

        return ($off === [] ? 'No weekly off' : implode(', ', $off)).', and holidays';
    }

    public static function workingHours(?ServiceSlaPolicy $policy): string
    {
        if ($policy?->calendar === 'calendar') {
            return '24 hours';
        }
        $hours = ($policy?->business_hours ?? null) ?: (array) config('peopleos.servicedesk.business_hours');

        return ($hours['start'] ?? '').' – '.($hours['end'] ?? '');
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceResolverRules::route('/'),
            'create' => CreateServiceResolverRule::route('/create'),
            'view' => ViewServiceResolverRule::route('/{record}'),
            'edit' => EditServiceResolverRule::route('/{record}/edit'),
        ];
    }
}
