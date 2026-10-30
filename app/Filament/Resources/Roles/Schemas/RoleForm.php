<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Domain\Experience\Services\MySpace;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use Closure;
use Filament\Forms\Components\CheckboxList;
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
                Section::make('Permissions')
                    ->description('resource.action keys from the platform catalogue. Tenants cannot invent new keys.')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->relationship('permissions', 'key')
                            // SaaS.10 (E1): a permission added to a role must be one the editor holds (RoleGrants).
                            ->rule(fn (?Role $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $added = array_diff(array_map('intval', (array) $value), $record?->permissions()->pluck('permissions.id')->map(fn ($id) => (int) $id)->all() ?? []);
                                $beyond = app(RoleGrants::class)->keysBeyond(auth()->user(), $added);
                                if ($beyond !== []) {
                                    $fail('You cannot add permissions you do not hold yourself: '.implode(', ', array_slice($beyond, 0, 5)).(count($beyond) > 5 ? ' …' : '').'.');
                                }
                            })
                            ->descriptions(fn () => Permission::query()->pluck('description', 'id')->all())
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(3)
                            ->gridDirection('row'),
                    ]),
                // HROne parity CA-9 (Page access per My space item): the self pages left out of My space for this role's holders.
                Section::make('Pages in My space')
                    ->description('Page access for self-service: tick the pages holders of this role should not find in My space (and its favourites and search). Someone with several roles still finds a page any of their roles leaves in. The pages themselves keep following the permissions above; nothing here grants anything.')
                    ->schema([
                        CheckboxList::make('myspace_hidden_items')->label('Leave out of My space')
                            ->options(fn () => app(MySpace::class)->options())
                            ->searchable()->bulkToggleable()->columns(3)->gridDirection('row'),
                    ]),
                AuditReasonField::make()->visibleOn('edit'),
            ]);
    }
}
