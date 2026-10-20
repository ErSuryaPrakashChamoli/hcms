<?php

namespace App\Filament\Resources\ServiceDefinitions\RelationManagers;

use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Configuration\Models\FormVersion;
use App\Domain\Identity\Models\Role;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Location;
use App\Domain\ServiceDesk\Models\ServiceDefinitionVersion;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Domain\ServiceDesk\Services\DomainActions;
use App\Domain\ServiceDesk\Services\ServiceCatalogue;
use App\Domain\Workflow\Models\Workflow;
use App\Filament\Support\ServiceDeskActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: a service's versions (the Phase 11 configuration lifecycle). Only a draft is edited; it is
 * submitted and approved by a second person; an approved version never changes and open requests stay
 * pinned to it — a change is a new version from a later date.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', ServiceDefinitionVersion::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['preparer', 'approver', 'slaPolicy']))
            ->columns([
                TextColumn::make('version')->label('v')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ServiceDefinitionVersion::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success', 'scheduled' => 'info', 'pending_approval' => 'warning', default => 'gray'
                    }),
                TextColumn::make('effective_from')->label('From')->date(),
                TextColumn::make('effective_to')->label('To')->date()->placeholder('Open'),
                TextColumn::make('domain_action')->label('Hands off to')->formatStateUsing(fn (?string $state) => $state ? (app(DomainActions::class)->options()[$state] ?? $state) : '—')->placeholder('Case only'),
                IconColumn::make('approval_required')->label('Approval')->boolean(),
                TextColumn::make('confidentiality')->badge()->color(fn (string $state) => match ($state) {
                    'restricted' => 'danger', 'sensitive' => 'warning', default => 'gray'
                }),
                TextColumn::make('slaPolicy.name')->label('SLA')->placeholder('Category hours'),
                TextColumn::make('preparer.name')->label('Prepared by')->placeholder('—')->toggleable(),
                TextColumn::make('approver.name')->label('Approved by')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('version', 'desc')
            ->emptyStateHeading('No versions yet')
            ->headerActions([
                Action::make('newVersion')->label('New version')->icon('heroicon-m-document-duplicate')
                    ->visible(fn () => auth()->user()->can('servicedesk.manage'))
                    ->schema([DatePicker::make('effective_from')->native(false)->required()->default(now()->addDay())->helperText('Copies the latest version; edit the draft, then submit it for approval.')])
                    ->action(fn (array $data) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->newVersion($this->getOwnerRecord(), auth()->user(), $data['effective_from']), 'Draft version created')),
            ])
            ->recordActions([
                Action::make('details')->label('Details')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)
                    ->schema(fn (ServiceDefinitionVersion $record) => [
                        TextEntry::make('d')->label('Description')->state($record->description ?: '—'),
                        TextEntry::make('f')->label('Form')->state($record->formVersion ? $record->formVersion->auditLabel() : 'No form (domain action fields only)'),
                        TextEntry::make('a')->label('Available to')->state(collect($record->availability ?? ['employee' => true, 'hr' => true])->filter()->keys()->implode(', ')),
                        TextEntry::make('w')->label('Approval workflow')->state($record->approval_required ? (string) $record->workflow_key : 'No approval'),
                        TextEntry::make('s')->label('Field classification')->state(collect($record->field_security ?? [])->map(fn ($r, $k) => $k.': '.($r['class'] ?? 'standard').(($r['employee_visible'] ?? true) ? '' : ' (HR only)'))->implode('; ') ?: 'All standard'),
                        TextEntry::make('c')->label('Checksum')->state($record->checksum ?? '—'),
                        TextEntry::make('n')->label('Decision note')->state($record->decision_note ?? '—'),
                    ]),
                ActionGroup::make([
                    Action::make('edit')->label('Edit draft')->icon('heroicon-m-pencil-square')
                        ->visible(fn (ServiceDefinitionVersion $record) => $record->status === 'draft' && auth()->user()->can('servicedesk.manage'))
                        ->fillForm(fn (ServiceDefinitionVersion $record) => self::draftFill($record))
                        ->schema(self::draftSchema())
                        ->action(fn (ServiceDefinitionVersion $record, array $data) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->updateDraft($record, self::draftData($data), auth()->user()), 'Draft saved')),
                    Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->requiresConfirmation()
                        ->visible(fn (ServiceDefinitionVersion $record) => $record->status === 'draft' && auth()->user()->can('servicedesk.manage'))
                        ->action(fn (ServiceDefinitionVersion $record) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->submit($record, auth()->user()), 'Submitted for approval')),
                    Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                        ->visible(fn (ServiceDefinitionVersion $record) => $record->status === 'pending_approval' && auth()->user()->can('servicedesk.catalogue_approve') && (int) $record->prepared_by !== (int) auth()->id())
                        ->schema([Textarea::make('note')->label('Note (optional)')])
                        ->action(fn (ServiceDefinitionVersion $record, array $data) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->approve($record, auth()->user(), $data['note'] ?? null), 'Version approved')),
                    Action::make('return')->label('Return to draft')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (ServiceDefinitionVersion $record) => $record->status === 'pending_approval' && auth()->user()->can('servicedesk.catalogue_approve'))
                        ->schema([Textarea::make('note')->required()])
                        ->action(fn (ServiceDefinitionVersion $record, array $data) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->returnToDraft($record, auth()->user(), $data['note']), 'Returned to draft')),
                    Action::make('archive')->label('Archive')->icon('heroicon-m-archive-box')->color('danger')
                        ->visible(fn (ServiceDefinitionVersion $record) => in_array($record->status, ['draft', 'pending_approval', 'scheduled'], true) && auth()->user()->can('servicedesk.manage'))
                        ->schema([Textarea::make('reason')->required()])
                        ->action(fn (ServiceDefinitionVersion $record, array $data) => ServiceDeskActions::run(fn () => app(ServiceCatalogue::class)->archive($record, auth()->user(), $data['reason']), 'Version archived')),
                ]),
            ]);
    }

    /** @return list<mixed> */
    private static function draftSchema(): array
    {
        return [
            Section::make('Service')->columns(2)->schema([
                DatePicker::make('effective_from')->native(false)->required(),
                Select::make('default_priority')->options(config('peopleos.servicedesk.priorities'))->required(),
                Textarea::make('description')->rows(2)->columnSpanFull(),
                Select::make('form_version_id')->label('Request form (published Configuration Form version)')->placeholder('None')
                    ->options(fn () => FormVersion::query()->with('form')->where('status', VersionStatus::Published)->get()->mapWithKeys(fn (FormVersion $v) => [$v->id => $v->auditLabel()])->all()),
                Select::make('domain_action')->label('Hands off to (domain action)')->placeholder('None — a case HR resolves')->options(fn () => app(DomainActions::class)->options()),
                Select::make('attachment_rule')->options(config('peopleos.servicedesk.attachment_rules'))->required(),
                Select::make('confidentiality')->options(config('peopleos.servicedesk.confidentiality'))->required()->helperText('Restricted: only people assigned or explicitly granted see the case.'),
            ]),
            Section::make('Who may use it')->columns(2)->schema([
                CheckboxList::make('audiences')->label('Available to')->options(['employee' => 'Employees, for themselves', 'manager' => 'Managers, for their team', 'hr' => 'HR, on someone\'s behalf'])->required(),
                Toggle::make('listed')->label('Shown in the catalogue'),
                Toggle::make('visible_to_employee')->label('The employee concerned can see the request'),
                Toggle::make('manager_visible')->label('Managers see its status for their team (standard cases only)'),
                Select::make('lifecycle_states')->label('Lifecycle states (empty = all)')->multiple()->options(collect(LifecycleState::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))])->all()),
                Select::make('company_ids')->label('Companies (empty = all)')->multiple()->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('department_ids')->label('Departments (empty = all)')->multiple()->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('location_ids')->label('Locations (empty = all)')->multiple()->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
            ]),
            Section::make('Approval, SLA and assignment')->columns(2)->schema([
                Toggle::make('approval_required')->label('Needs approval (existing workflow engine)')->live(),
                Select::make('workflow_key')->label('Approval workflow')->options(fn () => Workflow::query()->where('status', 'active')->pluck('name', 'key')->all())->visible(fn ($get) => (bool) $get('approval_required')),
                Select::make('sla_policy_id')->label('SLA policy')->placeholder('Category hours')->options(fn () => ServiceSlaPolicy::query()->where('status', 'active')->pluck('name', 'id')->all()),
                Select::make('assignment_role_id')->label('Team (role)')->placeholder('None')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
            ]),
            Section::make('Field classification')->schema([
                Repeater::make('field_rules')->label('')->schema([
                    TextInput::make('key')->label('Field key')->required(),
                    Select::make('class')->options(config('peopleos.servicedesk.field_classes'))->default('standard')->required(),
                    Toggle::make('employee_visible')->label('Employee sees it')->default(true),
                ])->columns(3)->defaultItems(0)->helperText('Sensitive values are masked unless the reader holds the domain permission; restricted values need explicit case access.'),
            ]),
            Textarea::make('change_note')->label('What changes and why')->rows(2),
        ];
    }

    /** @return array<string, mixed> */
    private static function draftFill(ServiceDefinitionVersion $record): array
    {
        return $record->only(['effective_from', 'default_priority', 'description', 'form_version_id', 'domain_action', 'attachment_rule', 'confidentiality', 'listed', 'visible_to_employee', 'manager_visible', 'lifecycle_states', 'approval_required', 'workflow_key', 'sla_policy_id', 'change_note']) + [
            'audiences' => collect($record->availability ?? ['employee' => true, 'hr' => true])->filter()->keys()->all(),
            'company_ids' => data_get($record->org_scope, 'company_ids', []), 'department_ids' => data_get($record->org_scope, 'department_ids', []), 'location_ids' => data_get($record->org_scope, 'location_ids', []),
            'assignment_role_id' => data_get($record->assignment, 'role_id'),
            'field_rules' => collect($record->field_security ?? [])->map(fn ($r, $k) => ['key' => $k, 'class' => $r['class'] ?? 'standard', 'employee_visible' => (bool) ($r['employee_visible'] ?? true)])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function draftData(array $data): array
    {
        $audiences = (array) ($data['audiences'] ?? []);

        return collect($data)->except(['audiences', 'company_ids', 'department_ids', 'location_ids', 'assignment_role_id', 'field_rules'])->all() + [
            'availability' => ['employee' => in_array('employee', $audiences, true), 'manager' => in_array('manager', $audiences, true), 'hr' => in_array('hr', $audiences, true)],
            'org_scope' => array_filter(['company_ids' => array_map('intval', (array) ($data['company_ids'] ?? [])), 'department_ids' => array_map('intval', (array) ($data['department_ids'] ?? [])), 'location_ids' => array_map('intval', (array) ($data['location_ids'] ?? []))]) ?: null,
            'assignment' => filled($data['assignment_role_id'] ?? null) ? ['role_id' => (int) $data['assignment_role_id']] : null,
            'field_security' => collect($data['field_rules'] ?? [])->filter(fn ($r) => filled($r['key'] ?? null))->mapWithKeys(fn ($r) => [$r['key'] => ['class' => $r['class'] ?? 'standard', 'employee_visible' => (bool) ($r['employee_visible'] ?? true)]])->all() ?: null,
            'workflow_key' => ($data['approval_required'] ?? false) ? ($data['workflow_key'] ?? null) : null,
        ];
    }
}
