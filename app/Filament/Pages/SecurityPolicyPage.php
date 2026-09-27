<?php

namespace App\Filament\Pages;

use App\Domain\Platform\Services\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Tenant security & retention policy (§82, §110): IP allowlist, MFA, passwords, sessions, retention windows. */
class SecurityPolicyPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Enterprise';

    protected static ?string $navigationLabel = 'Security policy';

    protected static ?string $title = 'Security & retention policy';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.security-policy';

    /** @var array<string, mixed> */
    public array $data = [];

    private const KEYS = ['security.ip_allowlist', 'security.mfa_required', 'security.session_idle_minutes', 'security.password_min_length', 'security.password_expiry_days', 'retention.ai_interactions_days', 'retention.notification_deliveries_days', 'retention.report_runs_days'];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('security.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = app(SettingsRepository::class);
        $data = [];
        foreach (self::KEYS as $key) {
            $data[str_replace('.', '__', $key)] = $settings->get($key, config("peopleos.settings.{$key}"));
        }
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Access')->columns(2)->schema([
                Textarea::make('security__ip_allowlist')->label('IP allowlist')->rows(3)->helperText('Comma or newline separated: exact IPs, 10.0.* wildcards or CIDR (10.0.0.0/8). Empty = no restriction. Platform admins are never blocked.')->columnSpanFull(),
                Toggle::make('security__mfa_required')->label('Require authenticator-app MFA for every tenant login'),
                TextInput::make('security__session_idle_minutes')->label('Sign out after idle minutes')->numeric()->minValue(0)->helperText('0 = never'),
            ]),
            Section::make('Passwords')->columns(2)->schema([
                TextInput::make('security__password_min_length')->label('Minimum length')->numeric()->minValue(8)->maxValue(64),
                TextInput::make('security__password_expiry_days')->label('Expire after days')->numeric()->minValue(0)->helperText('0 = never'),
            ]),
            Section::make('Retention (days; audit events are never purged)')->columns(3)->schema([
                TextInput::make('retention__ai_interactions_days')->label('AI interactions')->numeric()->minValue(0),
                TextInput::make('retention__notification_deliveries_days')->label('Notification deliveries')->numeric()->minValue(0),
                TextInput::make('retention__report_runs_days')->label('Report runs & exports')->numeric()->minValue(0),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save policy')->action('save')];
    }

    public function save(): void
    {
        $settings = app(SettingsRepository::class);
        $state = $this->form->getState();
        foreach (self::KEYS as $key) {
            $value = $state[str_replace('.', '__', $key)] ?? null;
            $settings->set($key, is_numeric($value) ? (int) $value : (is_bool($value) ? $value : (string) $value), 'Security policy update');
        }
        Notification::make()->success()->title('Security policy saved')->send();
    }
}
