<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MultiFactor;
use App\Domain\Identity\Services\PasswordResets;
use App\Domain\Identity\Services\SessionSecurity;
use App\Domain\Identity\Services\UserInvitations;
use App\Filament\Resources\Users\Concerns\SavesAccessScope;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use RuntimeException;

class EditUser extends PeopleEditRecord
{
    use GovernedEdit, SavesAccessScope;

    protected static string $resource = UserResource::class;

    private ?string $emailBefore = null;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['access_scope'] = $this->currentAccessScope($this->getRecord());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->emailBefore = $this->getRecord()->getOriginal('email');

        return $this->extractAccessScope($data);
    }

    protected function afterSave(): void
    {
        $this->persistAccessScope($this->getRecord(), $this->data['audit_reason'] ?? null);

        // SaaS.2: a new address is unproven. An invited user is re-invited at the new address (the old link
        // stops working); an active user is asked to verify the new address at their next request.
        $user = $this->getRecord();
        if ($this->emailBefore !== null && $this->emailBefore !== $user->email) {
            if ($user->status === UserStatus::Invited) {
                app(UserInvitations::class)->issue($user, auth()->user(), 'E-mail address changed');
            } else {
                $user->sendEmailVerificationNotification();
            }
        }
    }

    protected function getHeaderActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500);
        $can = fn (string $ability) => fn (User $record) => auth()->user()->can($ability, $record);

        return [
            ActionGroup::make([
                Action::make('resendInvitation')
                    ->label('Send a new invitation')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (User $record) => $record->status === UserStatus::Invited)
                    ->authorize($can('update'))
                    ->requiresConfirmation()
                    ->modalDescription('The previous invitation link stops working.')
                    ->action(fn (User $record) => $this->attempt(fn () => app(UserInvitations::class)->issue($record, auth()->user(), 'Invitation resent'), 'A new invitation is on its way.')),
                Action::make('revokeInvitation')
                    ->label('Revoke invitation')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record) => $record->status === UserStatus::Invited && app(UserInvitations::class)->latestFor($record)?->isPending())
                    ->authorize($can('update'))
                    ->schema([$reason()])
                    ->action(fn (User $record, array $data) => $this->attempt(fn () => app(UserInvitations::class)->revokePendingFor($record, auth()->user(), $data['reason']), 'Invitation revoked.')),
                Action::make('sendPasswordReset')
                    ->label('Send password reset link')
                    ->icon('heroicon-o-key')
                    ->visible(fn (User $record) => $record->status === UserStatus::Active)
                    ->authorize($can('update'))
                    ->requiresConfirmation()
                    ->modalDescription('The person receives a link to choose a new password. You never see it.')
                    ->action(fn (User $record) => $this->attempt(fn () => app(PasswordResets::class)->request($record->email), 'If the account can sign in, a reset link is on its way.')),
                Action::make('resetMfa')
                    ->label('Reset multi-factor authentication')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('danger')
                    ->visible(fn (User $record) => $record->hasMfaEnabled() && auth()->user()->isNot($record))
                    ->authorize(fn (User $record) => auth()->user()->can('security.manage') && auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('For a lost or replaced device. The authenticator and recovery codes are removed and the person is signed out everywhere; they set up a new authenticator at their next sign-in if your organisation requires one.')
                    ->schema([$reason()])
                    ->action(fn (User $record, array $data) => $this->attempt(fn () => app(MultiFactor::class)->reset($record, $data['reason'], auth()->user()), 'Multi-factor authentication reset.')),
                Action::make('signOutEverywhere')
                    ->label('Sign out everywhere')
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->visible(fn (User $record) => $record->status !== UserStatus::Invited && auth()->user()->isNot($record))
                    ->authorize($can('update'))
                    ->requiresConfirmation()
                    ->schema([$reason()])
                    ->action(fn (User $record, array $data) => $this->attempt(fn () => app(SessionSecurity::class)->revokeUser($record, $data['reason'], auth()->user()), 'Signed out on every device.')),
            ])->label('Account')->icon('heroicon-o-shield-check')->button(),
            DeleteAction::make(),
        ];
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
