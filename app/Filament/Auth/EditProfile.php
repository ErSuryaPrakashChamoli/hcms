<?php

namespace App\Filament\Auth;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PasswordPolicy;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use SensitiveParameter;

/**
 * SaaS.2: the person's own account security: change their password (current password required, tenant
 * password policy applied, other sessions signed out by the session password check) and set up, remove or
 * renew their authenticator and recovery codes. Name and e-mail stay administrator-managed identity data.
 */
class EditProfile extends BaseEditProfile
{
    private bool $passwordChanged = false;

    public function getTitle(): string|Htmlable
    {
        return 'Account security';
    }

    public static function getLabel(): string
    {
        return 'Account security';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getPasswordFormComponent();

        return $field->label('New password')->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
            $problems = app(PasswordPolicy::class)->problemsFor($this->user(), (string) $value);
            if ($problems !== []) {
                $fail(implode(' ', $problems));
            }
        });
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getCurrentPasswordFormComponent();

        return $field->visible(fn (Get $get): bool => filled($get('password')));
    }

    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $this->passwordChanged = array_key_exists('password', $data);

        return array_intersect_key($data, ['password' => true]);
    }

    protected function afterSave(): void
    {
        if ($this->passwordChanged) {
            $user = $this->user();
            app(AuditRecorder::class)->record(AuditAction::PasswordChanged, 'identity', $user, [], null, tenantId: $user->tenant_id, actor: $user, metadata: ['method' => 'profile']);
        }
    }

    private function user(): User
    {
        /** @var User */
        return $this->getUser();
    }
}
