<?php

namespace App\Filament\Pages;

use App\Domain\Enterprise\Services\SecurityPolicy;
use App\Domain\Platform\Services\SettingsRepository;
use App\Filament\Support\FormWizard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

/**
 * Tenant security & retention policy (§82, §110): IP allowlist, MFA, passwords, sessions, retention windows.
 * HROne setup parity: password expiry reminders, strict-password rules, recent-password reuse and disable login by status.
 * Followed as a wizard (FormWizard): one section per step, saved from Review.
 */
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

    private const KEYS = ['security.ip_allowlist', 'security.mfa_required', 'security.session_idle_minutes', 'security.password_min_length', 'security.password_expiry_days',
        'security.password_expiry_warning_days', 'security.password_require_symbol', 'security.password_block_personal', 'security.password_history_count', 'security.disable_login_states',
        'retention.ai_interactions_days', 'retention.notification_deliveries_days', 'retention.report_runs_days', 'retention.api_request_logs_days', 'warehouse.export_enabled', 'warehouse.retention_days'];

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
        $schema->statePath('data')->components($this->sections());

        return $schema->components([
            FormWizard::wizard(FormWizard::steps($schema, FormWizard::EDIT, 'Security policy'), FormWizard::EDIT, 'page')
                ->submitAction(Action::make('save')->label('Save policy')->action('save'))
                ->alpineSubmitHandler('$wire.save()'),
        ]);
    }

    /** @return list<Section> */
    private function sections(): array
    {
        return [
            Section::make('Access')->columns(2)->schema([
                Textarea::make('security__ip_allowlist')->label('IP allowlist')->rows(3)->helperText('Comma or newline separated: exact IPs, 10.0.* wildcards or CIDR (10.0.0.0/8). Empty = no restriction. Platform admins are never blocked.')->columnSpanFull(),
                Toggle::make('security__mfa_required')->label('Require authenticator-app MFA for every tenant login'),
                TextInput::make('security__session_idle_minutes')->label('Sign out after idle minutes')->numeric()->minValue(0)->helperText('0 = never'),
            ]),
            Section::make('Passwords')->columns(2)->schema([
                TextInput::make('security__password_min_length')->label('Minimum length')->numeric()->minValue(8)->maxValue(64),
                TextInput::make('security__password_expiry_days')->label('Expire after days')->numeric()->minValue(0)->maxValue(365)
                    ->helperText('0 = never. An expired password is changed at the next sign-in (single sign-on sessions are not affected).'),
                TextInput::make('security__password_expiry_warning_days')->label('Remind before expiry (days)')->numeric()->minValue(0)->maxValue(60)->helperText('0 = no reminder'),
                TextInput::make('security__password_history_count')->label('Recently used passwords that cannot be reused')->numeric()->minValue(0)->maxValue(24)->helperText('0 = no restriction'),
                Toggle::make('security__password_require_symbol')->label('Strict password: also require a symbol'),
                Toggle::make('security__password_block_personal')->label('Strict password: refuse the person’s name, e-mail or employee code'),
            ]),
            // HROne setup parity (Security → Disable login).
            Section::make('Disable login')->schema([
                Select::make('security__disable_login_states')->label('Employees in these statuses cannot sign in')->multiple()
                    ->options(SecurityPolicy::loginDisableOptions())
                    ->helperText('Sign-in is turned off when an employee enters a status and back on when they leave it (only when this rule turned it off). Completing an exit keeps its own rule.'),
            ]),
            Section::make('Retention')->description('Days each kind of record is kept. Audit events are never purged.')->columns(3)->schema([
                TextInput::make('retention__ai_interactions_days')->label('AI interactions')->numeric()->minValue(0),
                TextInput::make('retention__notification_deliveries_days')->label('Notification deliveries')->numeric()->minValue(0),
                TextInput::make('retention__report_runs_days')->label('Report runs & exports')->numeric()->minValue(0),
                // HROne parity CA-11: method, path, status, duration, IP and user agent of each API call (never bodies).
                TextInput::make('retention__api_request_logs_days')->label('API call logs')->numeric()->minValue(0),
            ]),
            // SaaS.8: the daily data-warehouse feed is opt-in, and its snapshots are kept only for the window below.
            Section::make('Data warehouse feed')->columns(2)->schema([
                Toggle::make('warehouse__export_enabled')->label('Export datasets to the warehouse disk every day')
                    ->helperText('Off: nothing is exported. Non-sensitive fields only.'),
                TextInput::make('warehouse__retention_days')->label('Keep daily snapshots for (days)')->numeric()->minValue(0)->helperText('0 = keep'),
            ]),
        ];
    }

    public function save(): void
    {
        $settings = app(SettingsRepository::class);
        $state = $this->form->getState();
        foreach (self::KEYS as $key) {
            $value = $state[str_replace('.', '__', $key)] ?? null;
            $settings->set($key, match (true) {
                is_array($value) => array_values(array_map('strval', $value)),
                is_numeric($value) => (int) $value,
                is_bool($value) => $value,
                default => (string) $value,
            }, 'Security policy update');
        }
        Notification::make()->success()->title('Security policy saved')->send();
    }
}
