<?php

namespace App\Filament\Support;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Identity\Services\TemporaryPasswords;
use App\Filament\Resources\Users\UserResource;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Sign-in help for one person, on a user's Account menu and on an employee's profile (TemporaryPasswords): e-mail a
 * temporary password (the invitation for an invited user, a fresh start for an active one) or generate one to hand over,
 * shown once to the administrator. Either way the person chooses their own password at the first sign-in. An employee
 * without a sign-in gets one from the work e-mail with the invitation e-mailed (BulkInvitations::invite, as "Invite to sign
 * in"). E-mailing needs the user's update policy (as a reset link); showing one also needs security.manage (as an MFA reset).
 * A page offering "Generate a temporary password" defines temporaryPasswordShownAction() as SignInActions::shown().
 */
final class SignInActions
{
    /**
     * @param  Closure(): ?User  $user  the sign-in the actions are for
     * @return list<Action>
     */
    public static function forUser(Closure $user): array
    {
        $open = fn (): bool => ($u = $user()) !== null && in_array($u->status, [UserStatus::Invited, UserStatus::Active], true);
        $invited = fn (): bool => $user()?->status === UserStatus::Invited;

        return [
            Action::make('emailTemporaryPassword')
                ->label(fn () => $invited() ? 'Send a new invitation' : 'E-mail a temporary password')
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible($open)
                ->authorize(fn () => ($u = $user()) !== null && auth()->user()->can('update', $u))
                ->requiresConfirmation()
                ->modalHeading(fn () => $invited() ? 'Send a new invitation' : 'E-mail a temporary password')
                ->modalDescription(fn () => 'A new temporary password is e-mailed to '.$user()?->email.'. '
                    .($invited() ? 'An earlier invitation stops working. ' : 'Their current password stops working and they are signed out everywhere. ')
                    .'They choose their own password when they sign in. You never see it.')
                ->modalSubmitActionLabel('Send')
                ->action(fn () => self::attempt(fn () => app(TemporaryPasswords::class)->email($user(), auth()->user(), $invited() ? 'Invitation resent' : 'Temporary password e-mailed'),
                    'Temporary password e-mailed to '.$user()?->email.'.')),
            Action::make('generateTemporaryPassword')
                ->label('Generate a temporary password')
                ->icon(Heroicon::OutlinedKey)
                ->visible($open)
                ->authorize(fn () => ($u = $user()) !== null && auth()->user()->can('update', $u) && auth()->user()->can('security.manage'))
                ->requiresConfirmation()
                ->modalDescription(fn () => 'PeopleOS makes a temporary password and shows it to you once, to hand over. '
                    .($invited() ? 'An earlier invitation stops working. ' : 'Their current password stops working and they are signed out everywhere. ')
                    .'They choose their own password when they sign in'.($user()?->hasVerifiedEmail() ? '.' : ', then confirm their e-mail address.'))
                ->modalSubmitActionLabel('Generate')
                ->action(function ($livewire) use ($user) {
                    $target = $user();
                    try {
                        $password = app(TemporaryPasswords::class)->show($target, auth()->user(), 'Temporary password generated');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }
                    $livewire->replaceMountedAction('temporaryPasswordShown', [
                        'password' => $password, 'name' => $target->name, 'email' => $target->email,
                        'until' => $target->temporary_password_until?->toDayDateTimeString(),
                    ]);
                }),
        ];
    }

    /** "Invite to sign in" for an employee without a sign-in: a user from the work e-mail, the employee role, the invitation. */
    public static function invite(Closure $employee): Action
    {
        $refusal = fn (): ?string => ($e = $employee()) === null ? 'No employee.' : app(BulkInvitations::class)->refusal($e, 'new', auth()->user());

        return Action::make('inviteToSignIn')
            ->label('Invite to sign in')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->visible(fn () => ($e = $employee()) !== null && $e->user_id === null && (auth()->user()?->hasPermission('user.create') ?? false))
            ->disabled(fn () => $refusal() !== null)
            ->tooltip($refusal)
            ->requiresConfirmation()
            ->modalHeading('Invite to sign in')
            ->modalDescription(fn () => 'A sign-in is created for '.$employee()?->work_email.' and a temporary password is e-mailed there. They choose their own password when they sign in.')
            ->modalSubmitActionLabel('Send invitation')
            ->action(fn () => self::attempt(fn () => app(BulkInvitations::class)->invite($employee(), auth()->user(),
                ['roles' => app(BulkInvitations::class)->defaultRoleIds(auth()->user())], 'Invitation from the employee profile'), 'Invitation sent to '.$employee()?->work_email.'.'));
    }

    /** Opens the person's user (roles, access scope, the rest of the Account menu). */
    public static function openUser(Closure $user): Action
    {
        return Action::make('openUser')
            ->label('Roles and access')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn () => ($u = $user()) !== null && auth()->user()->can('update', $u))
            ->url(fn () => UserResource::getUrl('edit', ['record' => $user()]));
    }

    /** The generated temporary password, shown once. */
    public static function shown(): Action
    {
        return Action::make('temporaryPasswordShown')
            ->modalHeading(fn (array $arguments) => 'Temporary password for '.($arguments['name'] ?? ''))
            ->modalWidth(Width::Medium)
            ->modalContent(fn (array $arguments) => view('filament.partials.temporary-password', [
                'password' => (string) ($arguments['password'] ?? ''), 'email' => (string) ($arguments['email'] ?? ''),
                'until' => $arguments['until'] ?? null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }

    private static function attempt(callable $work, string $success): void
    {
        try {
            $work();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
