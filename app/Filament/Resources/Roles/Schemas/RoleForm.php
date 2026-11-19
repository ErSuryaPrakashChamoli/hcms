<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Domain\Experience\Services\MySpace;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PermissionAreas;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\Forms\GroupedCheckboxList;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(64)
                            ->alphaDash()
                            ->disabled(fn (string $operation, $record) => $operation === 'edit' && $record?->is_system)
                            ->dehydrated()
                            ->helperText('Stable identifier used by policies and integrations.'),
                        TextInput::make('description')->maxLength(255)->columnSpanFull(),
                        // HROne setup parity (Automation → Role): status and the reliever who takes the role's approvals.
                        Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active->value)->required()
                            ->disabled(fn (?Role $record) => (bool) $record?->is_system)->dehydrated(fn (?Role $record) => ! $record?->is_system)
                            ->helperText(fn (?Role $record) => $record?->is_system ? 'System roles stay active; remove their members instead.' : 'An inactive role grants no permissions and receives no new workflow tasks.'),
                        Select::make('reliever_user_id')->label('Reliever')->searchable()->placeholder('Nobody')
                            ->options(fn () => User::forCurrentTenant()->where('status', UserStatus::Active->value)->where('is_platform_admin', false)->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Takes the role\'s new workflow approvals while nobody active holds it (or the role is inactive).'),
                    ]),
                // Owner 2026-11-19: permissions by area and module, each explained, with a live "what this role can do".
                Section::make('Permissions')
                    ->description('What people with this role may see and do, grouped by area of PeopleOS and then by module. Open an area, read what it covers and tick what the job needs.')
                    ->schema([
                        GroupedCheckboxList::make('permissions')
                            ->label('What this role allows')
                            ->relationship('permissions', 'key')
                            ->getOptionLabelFromRecordUsing(fn (Permission $permission) => PermissionAreas::actionLabel($permission->key))
                            ->optionCodes(fn () => Permission::query()->pluck('key', 'id')->all())
                            ->groups(fn () => PermissionAreas::groups(Permission::query()->pluck('key', 'id')->all()))
                            ->groupsCollapsed()
                            ->countWord('allowed')
                            ->summary('This role can work in:', 'Nothing ticked yet: on its own, this role lets people do nothing.')
                            ->explainer('How permissions work', [
                                'Ticked means people with this role may do it. Unticked means this role does not give it (another of their roles still might).',
                                'Roles add up: someone with several roles can do everything any one of them allows.',
                                'Rights over one\'s own things (apply for leave, own payslips, own goals) suit every employee; team rights suit managers; set-up, approve and run rights suit HR, payroll and admins.',
                                'You can only give permissions you hold yourself.',
                            ])
                            ->searchPrompt('Find a permission, e.g. payslip, approve leave or export')
                            // SaaS.10 (E1): a permission added to a role must be one the editor holds (RoleGrants).
                            ->rule(fn (?Role $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $added = array_diff(array_map('intval', (array) $value), $record?->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all() ?? []);
                                $beyond = app(RoleGrants::class)->keysBeyond(auth()->user(), $added);
                                if ($beyond !== []) {
                                    $fail('You cannot add permissions you do not hold yourself: '.implode(', ', array_slice($beyond, 0, 5)).(count($beyond) > 5 ? ' …' : '').'.');
                                }
                            })
                            ->descriptions(fn () => Permission::query()->pluck('description', 'id')->all())
                            ->bulkToggleable()
                            ->columns(3)
                            ->gridDirection('row'),
                    ]),
                // HROne parity CA-9 (Page access per My space item): the self pages left out of My space for this role's holders,
                // by My space group (owner 2026-11-19), with what hiding does and does not do.
                Section::make('Pages in My space')
                    ->description('Choose the self-service pages people with this role should not see in My space. Nothing is hidden unless you tick it.')
                    ->schema([
                        GroupedCheckboxList::make('myspace_hidden_items')->label('Hide from My space')
                            ->options(fn () => app(MySpace::class)->grouped()['options'])
                            ->descriptions(fn () => app(MySpace::class)->grouped()['descriptions'])
                            ->groups(fn () => app(MySpace::class)->grouped()['groups'])
                            ->countWord('hidden')
                            ->summary('Hidden for this role:', 'Nothing hidden: people with this role see every My space page their permissions allow.')
                            ->explainer('What “Hide from My space” means', [
                                'My space is each person\'s self-service corner: their payslips, leave, profile, goals and requests.',
                                'Tick a page to keep it out of My space, its favourites and its search for people with this role, e.g. Loans and advances for interns, or Alumni portal for current staff.',
                                'Hiding only tidies the menu. It never gives or takes away access: what people can open is still decided by the permissions on the previous step.',
                                'Someone with several roles still sees a page if any one of their roles keeps it.',
                            ])
                            ->searchPrompt('Find a page, e.g. payslip or leave')
                            ->bulkToggleable()->columns(3)->gridDirection('row'),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
