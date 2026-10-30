<?php

namespace App\Filament\Resources\Roles\RelationManagers;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RoleMembers;
use App\Domain\Organisation\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne setup parity (Automation → Role, employee view): who holds this role, with their employee code, the companies
 * the grant is limited to and their account status. Adding and removing members follows the user form's rules
 * (`user.assign_roles`, the delegation ceiling) through RoleMembers, audited on the person.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    /** @var array<int, array{code: ?string, companies: string}>|null */
    private ?array $details = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('user.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->select('users.*')->distinct())
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('employee_code')->label('Employee')->placeholder('—')->state(fn (User $record) => $this->details()[$record->id]['code'] ?? null),
                TextColumn::make('companies')->label('Companies')->state(fn (User $record) => $this->details()[$record->id]['companies'] ?? 'All companies'),
                // HROne parity SV-7: given by hand, or by a membership rule (which then decides).
                TextColumn::make('granted_by')->label('Given')->state(fn (User $record) => $this->details()[$record->id]['granted_by'] ?? 'By hand'),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->headerActions([
                Action::make('addMember')->label('Add member')->icon('heroicon-o-user-plus')
                    ->visible(fn () => auth()->user()->can('user.assign_roles'))
                    ->schema([
                        Select::make('user_id')->label('User')->required()->searchable()
                            ->options(fn () => User::forCurrentTenant()->whereIn('status', [UserStatus::Active->value, UserStatus::Invited->value])
                                ->where('is_platform_admin', false)->orderBy('name')->get(['id', 'name', 'email'])->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->email})"])->all()),
                        Select::make('company_id')->label('Limited to company')->placeholder('All companies')
                            ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        $user = User::forCurrentTenant()->findOrFail((int) $data['user_id']);
                        $this->attempt(fn () => app(RoleMembers::class)->add($this->role(), $user, filled($data['company_id'] ?? null) ? (int) $data['company_id'] : null, auth()->user(), $data['reason']), "{$user->name} now holds this role.");
                    }),
            ])
            ->recordActions([
                Action::make('removeMember')->label('Remove')->icon('heroicon-o-user-minus')->color('danger')
                    ->visible(fn () => auth()->user()->can('user.assign_roles'))
                    ->requiresConfirmation()
                    ->modalDescription('The person loses this role (for every company it was given for by hand; a membership rule keeps deciding its own grant). Audited.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (User $record, array $data) => $this->attempt(fn () => app(RoleMembers::class)->remove($this->role(), $record, auth()->user(), $data['reason']), "{$record->name} no longer holds this role.")),
            ]);
    }

    private function role(): Role
    {
        /** @var Role */
        return $this->getOwnerRecord();
    }

    /** Employee codes and company limits of the role's members, read once per request. @return array<int, array{code: ?string, companies: string}> */
    private function details(): array
    {
        if ($this->details !== null) {
            return $this->details;
        }
        $pivots = DB::table('role_user')->where('role_id', $this->role()->getKey())->get(['user_id', 'company_id', 'source', 'membership_rule_id']);
        $rules = RoleMembershipRule::query()->whereIn('id', $pivots->pluck('membership_rule_id')->filter()->unique()->all())->pluck('name', 'id');
        $companies = Company::query()->whereIn('id', $pivots->pluck('company_id')->filter()->unique()->all())->pluck('name', 'id');
        // Employee codes only for employees the viewer may see (access scope applies).
        $codes = Employee::query()->whereIn('user_id', $pivots->pluck('user_id')->unique()->all())->pluck('employee_code', 'user_id');

        return $this->details = $pivots->groupBy('user_id')->map(fn ($rows, $userId) => [
            'code' => $codes[$userId] ?? null,
            'companies' => $rows->contains(fn ($r) => $r->company_id === null) ? 'All companies' : $rows->map(fn ($r) => $companies[$r->company_id] ?? '—')->sort()->implode(', '),
            'granted_by' => $rows->map(fn ($r) => $r->source === 'rule' ? 'Rule: '.($rules[$r->membership_rule_id] ?? 'no longer in force') : 'By hand')->unique()->sort()->implode(' · '),
        ])->all();
    }

    private function attempt(callable $work, string $success): void
    {
        try {
            $work();
            $this->details = null;
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
