<?php

namespace App\Filament\Pages;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Roles\RoleResource;
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
 * HROne setup parity (Automation → Role, "employee view" / "group view"): the roles of each person, read-only. Each
 * role can also be shown as its own column (a users × roles matrix). Inactive roles are marked, as they grant nothing;
 * "Reliever for" lists the roles whose approvals the person takes while nobody active holds them. Changes are made on
 * the role (Members) or the user.
 */
class RolesByPerson extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Roles by person';

    protected static ?string $title = 'Roles by person';

    protected static ?int $navigationSort = 21;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.roles-by-person';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('role.view') && $user->can('user.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('byRole')->label('By role')->icon(Heroicon::OutlinedShieldCheck)->color('gray')->url(RoleResource::getUrl('index'))];
    }

    public function table(Table $table): Table
    {
        $roles = Role::query()->orderBy('name')->get(['id', 'name', 'slug', 'status', 'reliever_user_id']);
        $relieving = $roles->filter(fn (Role $r) => $r->reliever_user_id !== null)->groupBy('reliever_user_id');

        return $table
            ->query(fn (): Builder => User::forCurrentTenant()->where('is_platform_admin', false)->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('role_list')->label('Roles')->badge()->separator(',')
                    ->state(fn (User $record) => $record->roles->keyBy('id')->sortBy('name')->map(fn (Role $r) => $r->isInactive() ? "{$r->name} (inactive)" : $r->name)->values()->all())
                    ->color(fn (string $state) => str_ends_with($state, '(inactive)') ? 'gray' : 'primary')
                    ->placeholder('No role'),
                TextColumn::make('relieves')->label('Reliever for')->placeholder('—')->toggleable()
                    ->state(fn (User $record) => ($relieving[$record->id] ?? collect())->pluck('name')->implode(', ')),
                // The matrix: one column per role, shown from the column toggle.
                ...$roles->map(fn (Role $role) => IconColumn::make("role_{$role->id}")->label($role->name)->boolean()
                    ->state(fn (User $record) => $record->roles->contains('id', $role->id))
                    ->toggleable(isToggledHiddenByDefault: true))->all(),
            ])
            ->filters([
                SelectFilter::make('roles')->label('Role')->relationship('roles', 'name')->multiple()->preload(),
                SelectFilter::make('status')->options(UserStatus::class),
            ])
            ->defaultSort('name');
    }
}
