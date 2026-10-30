<?php

namespace App\Filament\Resources\Roles\RelationManagers;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\RoleMembershipRule;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Identity\Services\RoleMembershipRules;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HROne parity SV-7 (Automation → Role, "Select role organization wise"): the rules that decide who holds this role, by
 * organisation unit or named employees. Writing a rule follows the delegation ceiling for rules (RoleGrants), every change
 * is audited with its reason, and the grants follow at once (RoleMembershipRules → RoleMembershipSync). Grants given by
 * hand (Members) are never changed by a rule.
 */
class MembershipRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'membershipRules';

    protected static ?string $title = 'Membership rules';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('role.view') ?? false) && (auth()->user()?->can('user.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => app(RoleGrants::class)->ruleLocked($this->role())
                ? 'No membership rule may grant this role: give it to people one by one (Members).'
                : 'People who match a rule (employed, with an account that can sign in) hold this role while they match; grants given by hand are not affected.')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('conditions')->label('Who')->wrap()
                    ->state(fn (RoleMembershipRule $record) => RoleMembershipRules::problems($record->conditions, $record->match) === []
                        ? RuleConditionsSchema::describe((array) $record->conditions, (string) $record->match) : 'Not valid: matches nobody'),
                TextColumn::make('holders')->label('Holders by this rule')
                    ->state(fn (RoleMembershipRule $record) => DB::table('role_user')->where('membership_rule_id', $record->getKey())->where('source', 'rule')->count()),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('last_synced_at')->label('Applied')->since()->placeholder('—'),
            ])
            ->headerActions([
                $this->ruleAction('addRule', 'Add rule')->icon('heroicon-o-plus')
                    ->visible(fn () => $this->mayWrite()),
            ])
            ->recordActions([
                $this->ruleAction('editRule', 'Edit')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => $this->mayWrite())
                    ->fillForm(fn (RoleMembershipRule $record) => ['name' => $record->name, 'description' => $record->description,
                        'match' => $record->match, 'status' => $record->status, 'conditions' => (array) $record->conditions]),
                Action::make('toggleRule')->label(fn (RoleMembershipRule $record) => $record->isActive() ? 'Switch off' : 'Switch on')
                    ->icon('heroicon-o-power')->color(fn (RoleMembershipRule $record) => $record->isActive() ? 'danger' : 'success')
                    ->visible(fn () => auth()->user()->can('user.assign_roles'))
                    ->modalDescription(fn (RoleMembershipRule $record) => $record->isActive()
                        ? 'Everyone who holds the role only through this rule loses it now. Audited.'
                        : 'Everyone who matches gets the role now. Audited.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (RoleMembershipRule $record, array $data) => $this->attempt(
                        fn () => app(RoleMembershipRules::class)->setStatus($record, $record->isActive() ? 'inactive' : 'active', auth()->user(), $data['reason']),
                        $record->isActive() ? 'Rule switched off.' : 'Rule switched on.')),
                Action::make('deleteRule')->label('Delete')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn () => auth()->user()->can('user.assign_roles'))
                    ->requiresConfirmation()
                    ->modalDescription('Everyone who holds the role only through this rule loses it. Audited.')
                    ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                    ->action(fn (RoleMembershipRule $record, array $data) => $this->attempt(
                        fn () => app(RoleMembershipRules::class)->delete($record, auth()->user(), $data['reason']), 'Rule deleted.')),
            ]);
    }

    private function ruleAction(string $name, string $label): Action
    {
        return Action::make($name)->label($label)
            ->modalDescription('Who holds this role by rule: the people matching the conditions today, while they match.')
            ->steps([
                Step::make('Rule')->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    Textarea::make('description')->maxLength(1000),
                    Select::make('status')->options(RoleMembershipRule::STATUSES)->default('active')->required()->native(false),
                ]),
                Step::make('Who')->description('Organisation units (company, department, designation, grade, level, location …) or named employees')->schema([
                    Radio::make('match')->options(['all' => 'Every condition matches', 'any' => 'Any condition matches'])->default('all')->required()->inline(),
                    RuleConditionsSchema::repeater(),
                ]),
                Step::make('Review')->schema([
                    Text::make(fn (Get $get) => 'Matches '.app(RoleMembershipRules::class)->preview(RoleMembershipRules::normalise(array_values((array) $get('conditions'))), (string) ($get('match') ?? 'all'))
                        .' people today. They hold the role while they match; grants given by hand are not affected.'),
                    AuditReasonField::make()->required(),
                ]),
            ])
            ->action(function (array $data, ?Model $record = null): void {
                $reason = (string) AuditReasonField::extract($data);
                $data['conditions'] = array_values((array) ($data['conditions'] ?? []));
                $rule = $record instanceof RoleMembershipRule ? $record : null;
                $this->attempt(fn () => app(RoleMembershipRules::class)->save($this->role(), $rule, $data, auth()->user(), $reason), 'Rule saved and applied.');
            });
    }

    private function mayWrite(): bool
    {
        return auth()->user()->can('user.assign_roles') && ! app(RoleGrants::class)->ruleLocked($this->role())
            && app(RoleGrants::class)->canAssignByRule(auth()->user(), $this->role());
    }

    private function role(): Role
    {
        /** @var Role */
        return $this->getOwnerRecord();
    }

    private function attempt(callable $work, string $success): void
    {
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
