<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\AccessScopeGroup;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopeGroups;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\RoleMembers;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Division;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\Grade;
use App\Domain\Organisation\Models\Level;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\Team;
use App\Filament\Support\AuditReasonField;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        // SaaS.2: administrators never choose or see a password. A new user is invited by e-mail and
                        // chooses their own; a forgotten password is reset by its owner (or a reset link is sent).
                        Select::make('status')
                            ->options(fn (?User $record) => $record?->status === UserStatus::Invited
                                ? [UserStatus::Invited->value => UserStatus::Invited->getLabel()]
                                : [UserStatus::Active->value => UserStatus::Active->getLabel(), UserStatus::Suspended->value => UserStatus::Suspended->getLabel()])
                            ->visibleOn('edit')
                            ->disabled(fn (?User $record) => $record?->status === UserStatus::Invited)
                            ->dehydrated(fn (?User $record) => $record?->status !== UserStatus::Invited)
                            ->helperText(fn (?User $record) => $record?->status === UserStatus::Invited ? 'Activated when the person accepts their invitation.' : null)
                            ->required(),
                        TextInput::make('timezone')->placeholder('Asia/Kolkata')->maxLength(64),
                    ]),
                Section::make('Roles')
                    ->schema([
                        Select::make('roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->disabled(fn () => ! auth()->user()->can('user.assign_roles'))
                            // HROne parity SV-7: the form holds the roles given by hand; grants made by membership rules are
                            // listed below and never changed by saving the form.
                            ->loadStateFromRelationshipsUsing(fn (Select $component, ?User $record) => $component->state($record === null ? [] : array_map('strval', app(RoleMembers::class)->manualRoleIds($record))))
                            ->saveRelationshipsUsing(fn (?User $record, $state) => $record === null ? null : app(RoleMembers::class)->syncManual($record, (array) $state, auth()->user()))
                            // SaaS.10 (E1): a role newly given here must be one the editor may grant (RoleGrants). SV-7: a role held
                            // only through a rule counts as new (giving it by hand makes it permanent).
                            ->rule(fn (?User $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $refused = app(RoleGrants::class)->refused(auth()->user(), (array) $value, $record === null ? [] : app(RoleMembers::class)->manualRoleIds($record));
                                if ($refused->isNotEmpty()) {
                                    $fail(app(RoleGrants::class)->refusal($refused->first()));
                                }
                            })
                            ->helperText('Tenant-wide role assignments. Organisational restrictions are set under Access scope below. You can give a custom role only if you hold all its permissions; only a Tenant Super Admin gives that role.'),
                        Text::make(fn (?User $record) => self::ruleGrantsText($record))
                            ->visible(fn (?User $record) => $record !== null && app(RoleMembers::class)->ruleGrants($record) !== []),
                    ]),
                Section::make('Access scope')
                    ->description('Leave empty for tenant-wide access. Selections restrict the employees and organisation units this user can reach; dimensions combine with AND.')
                    ->schema([
                        Select::make('access_scope.company')->label('Companies')->multiple()->searchable()->preload()
                            ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.location')->label('Locations')->multiple()->searchable()->preload()
                            ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.department')->label('Departments')->multiple()->searchable()->preload()
                            ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.business_unit')->label('Business units')->multiple()->searchable()->preload()
                            ->options(fn () => BusinessUnit::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.establishment')->label('Establishments (statutory data)')->multiple()->searchable()->preload()
                            ->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')),
                        // HROne parity SV-7 (AR-3): divisions and teams (kept when the form is saved; they were dropped before),
                        // designations, grades and levels.
                        Select::make('access_scope.division')->label('Divisions')->multiple()->searchable()->preload()
                            ->options(fn () => Division::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.team')->label('Teams')->multiple()->searchable()->preload()
                            ->options(fn () => Team::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.designation')->label('Designations')->multiple()->searchable()->preload()
                            ->options(fn () => Designation::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.grade')->label('Grades')->multiple()->searchable()->preload()
                            ->options(fn () => Grade::query()->orderBy('name')->pluck('name', 'id')),
                        Select::make('access_scope.level')->label('Levels')->multiple()->searchable()->preload()
                            ->options(fn () => Level::query()->orderBy('name')->pluck('name', 'id')),
                        // HROne parity CA-9 (Data access, employee view): the person's data-access groups (each narrows further).
                        Text::make(fn (?User $record) => self::groupsText($record))->columnSpanFull()
                            ->visible(fn (?User $record) => $record !== null),
                    ])
                    ->columns(2)
                    ->disabled(fn () => ! auth()->user()->can('user.assign_roles'))
                    ->dehydrated(fn () => auth()->user()->can('user.assign_roles')),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }

    private static function ruleGrantsText(?User $record): string
    {
        $grants = $record === null ? [] : app(RoleMembers::class)->ruleGrants($record);

        return 'Also held through role membership rules (change the rule to change these): '
            .implode(', ', array_map(fn (string $role, string $rule) => "{$role} (rule \"{$rule}\")", array_keys($grants), $grants)).'.';
    }

    private static function groupsText(?User $record): string
    {
        $groups = $record === null ? collect() : app(AccessScopeGroups::class)->groupsOf($record);
        if ($groups->isEmpty()) {
            return 'Data access groups: none (only the scope above applies).';
        }
        $modules = AccessScopes::modules();

        return 'Data access groups (each narrows this person\'s data in its module further): '
            .$groups->map(fn (AccessScopeGroup $g) => $g->name.' · '.($modules[$g->module] ?? $g->module).($g->isActive() ? '' : ' (inactive)'))->implode('; ').'.';
    }
}
