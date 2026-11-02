<?php

namespace App\Filament\Resources\BusinessPartners;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\RoleGrants;
use App\Domain\Partners\Models\BusinessPartner;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\BusinessPartners;
use App\Domain\People\Support\PhoneNumbers;
use App\Filament\Resources\BusinessPartners\Pages\ManageBusinessPartners;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PartnerForms;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * HROne parity WO-15 (matrix 23 §23.8, §23.9 #21; HROne "Business partners", `addexternal_1069`): people outside the
 * workforce who may sign in — customers, background-verification agents, vendors' staff, system consultants. The register
 * shows name, e-mail, mobile, type, sign-in and status (as a table or as cards), filtered by type, status, sign-in and
 * vendor; ⋮ Edit, Invite to sign in / Send a new invitation, Disable login, Enable login, Open sign-in. "Add business
 * partner" is a step wizard (Name → Personal details → Type and vendor → Contact → Review) that opens directly with
 * ?action=create. Every write goes through App\Domain\Partners\Services\BusinessPartners; nothing is deleted.
 * `partner.view` to see, `partner.manage` to add, edit and act (with user.create / user.update for the sign-in).
 */
class BusinessPartnerResource extends Resource
{
    protected static ?string $model = BusinessPartner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Business partners';

    protected static ?string $modelLabel = 'business partner';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Name')->columns(2)->schema([
                Select::make('salutation')->options(fn (?BusinessPartner $record) => self::options('salutations', $record?->getAttributes()['salutation'] ?? null))->native(false),
                TextInput::make('first_name')->required()->maxLength(120),
                TextInput::make('middle_name')->maxLength(120),
                TextInput::make('last_name')->maxLength(120),
                TextInput::make('code')->label('Business partner code')->maxLength(32)->regex(BusinessPartners::CODE_PATTERN)
                    ->required(fn (string $operation) => $operation === 'edit')
                    ->placeholder(fn (string $operation) => $operation === 'create' ? rescue(fn () => app(BusinessPartners::class)->previewCode(), null, false) : null)
                    ->helperText(fn (string $operation) => 'Only a-z, A-Z, 0-9 and _.'.($operation === 'create' ? ' Leave blank for the next number of the series (Setup → Number series).' : '')),
            ]),
            Section::make('Personal details')->columns(3)->schema([
                DatePicker::make('date_of_birth')->native(false)->maxDate(now()->subDay()),
                Select::make('gender')->options(fn () => (array) config('peopleos.people.genders', []))->native(false),
                TextInput::make('nationality')->maxLength(64),
            ]),
            Section::make('Type and vendor')->columns(3)->schema([
                Select::make('partner_type')->label('Partner type')->options(fn (?BusinessPartner $record) => self::options('partner_types', $record?->getAttributes()['partner_type'] ?? null))->required()->native(false),
                Select::make('vendor_id')->label('Vendor')->searchable()->placeholder('None')
                    ->options(fn (?BusinessPartner $record) => Vendor::query()
                        ->where(fn ($q) => $q->where('status', 'active')->when($record?->getAttributes()['vendor_id'] ?? null, fn ($q, $id) => $q->orWhere('id', $id)))
                        ->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('status')->options(BusinessPartner::STATUSES)->default('active')->required()->native(false)
                    ->helperText('An inactive partner cannot sign in: making a partner inactive disables their login.'),
            ]),
            Section::make('Contact')->columns(2)->schema([
                Select::make('isd_code')->label('ISD code')->options(fn () => PhoneNumbers::codes())->default('91')->searchable()->placeholder('None (kept as typed)'),
                TextInput::make('mobile')->tel()->maxLength(32),
                TextInput::make('personal_email')->label('Personal e-mail')->email()->maxLength(255),
                TextInput::make('official_email')->label('Official e-mail')->email()->maxLength(255)
                    ->helperText('An invitation to sign in goes to the official e-mail, else to the personal one.'),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'vendor']))
            ->columns([
                TextColumn::make('first_name')->label('Name')->weight('bold')
                    ->state(fn (BusinessPartner $record, $livewire) => $record->displayName(self::labelsFrom($livewire, 'salutations')))
                    ->description(fn (BusinessPartner $record) => '#'.$record->code)
                    ->searchable(query: self::search())->sortable(['first_name', 'last_name']),
                TextColumn::make('email')->label('E-mail')->state(fn (BusinessPartner $record) => $record->official_email ?: $record->personal_email)
                    ->description(fn (BusinessPartner $record) => $record->official_email && $record->personal_email ? $record->personal_email : null)->placeholder('—'),
                TextColumn::make('mobile')->state(fn (BusinessPartner $record) => $record->mobileDisplay())->placeholder('—'),
                TextColumn::make('partner_type')->label('Type')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state, $livewire) => self::labelsFrom($livewire, 'partner_types')[$state] ?? $state),
                TextColumn::make('vendor.name')->label('Vendor')->placeholder('—')->toggleable(),
                TextColumn::make('sign_in')->label('Sign-in')->badge()->state(fn (BusinessPartner $record) => BusinessPartner::SIGN_IN[$record->signInState()])
                    ->color(fn (BusinessPartner $record) => self::signInColor($record->signInState())),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => BusinessPartner::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Added on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(self::filters())
            ->defaultSort('first_name')
            ->recordActions([ActionGroup::make(self::recordActions())]);
    }

    /** @return list<SelectFilter> type, status, sign-in, vendor */
    public static function filters(): array
    {
        return [
            SelectFilter::make('partner_type')->label('Type')->options(fn () => app(OptionLists::class)->labels('partner_types')),
            SelectFilter::make('status')->options(BusinessPartner::STATUSES),
            SelectFilter::make('sign_in')->label('Sign-in')->options(BusinessPartner::SIGN_IN)
                ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'none' => $query->where(fn ($q) => $q->whereNull('user_id')),
                    'invited' => $query->whereHas('user', fn ($q) => $q->where('status', UserStatus::Invited->value)),
                    'active' => $query->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active->value)),
                    'disabled' => $query->whereHas('user', fn ($q) => $q->where('status', UserStatus::Suspended->value)),
                    default => $query,
                }),
            SelectFilter::make('vendor_id')->label('Vendor')->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
        ];
    }

    /** Name, code or e-mail. */
    public static function search(): Closure
    {
        return fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
            ->where('first_name', 'like', "%{$search}%")->orWhere('middle_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('code', 'like', "%{$search}%")->orWhere('official_email', 'like', "%{$search}%")->orWhere('personal_email', 'like', "%{$search}%"));
    }

    /** @return list<Action> the ⋮ menu of a partner */
    public static function recordActions(): array
    {
        return [
            EditAction::make()->using(fn (BusinessPartner $record, array $data, Action $action) => self::save($action, $record, $data)),
            Action::make('invite')
                ->label(fn (BusinessPartner $record) => $record->signInState() === 'invited' ? 'Send a new invitation' : 'Invite to sign in')
                ->icon(Heroicon::OutlinedEnvelope)
                ->visible(fn (BusinessPartner $record) => self::may($record) && $record->isActive()
                    && ($record->signInState() === 'invited' || ($record->signInState() === 'none' && auth()->user()?->hasPermission('user.create'))))
                ->modalHeading(fn (BusinessPartner $record) => $record->signInState() === 'invited' ? 'Send a new invitation' : 'Invite to sign in')
                ->modalDescription(fn (BusinessPartner $record) => 'An e-mail with a temporary password goes to '.($record->signInEmail() ?? 'the partner').' only; '
                    .'they choose their own password when they first sign in. A business partner has no employee record and sees nothing except what the roles given to them allow.')
                ->schema(fn (BusinessPartner $record) => $record->signInState() === 'none' && auth()->user()?->hasPermission('user.assign_roles') ? [
                    Select::make('roles')->label('Roles')->multiple()->native(false)->options(fn () => self::assignableRoles())
                        ->helperText('None by default. Give only what the partner needs; roles can also be changed later on Users → Edit.'),
                ] : [])
                ->modalSubmitActionLabel('Send invitation')
                ->action(function (BusinessPartner $record, array $data, Action $action): void {
                    $user = PartnerForms::run($action, fn () => app(BusinessPartners::class)->invite($record, auth()->user(), array_map('intval', (array) ($data['roles'] ?? []))));
                    Notification::make()->success()->title('Invitation sent to '.$user->email)->send();
                }),
            Action::make('disableLogin')
                ->label('Disable login')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn (BusinessPartner $record) => self::may($record) && in_array($record->signInState(), ['invited', 'active'], true))
                ->requiresConfirmation()
                ->modalDescription('The partner can no longer sign in: their sessions end now and an unused temporary password stops working. Their roles stay as they are.')
                ->schema([Textarea::make('reason')->label('Reason')->rows(2)->maxLength(200)])
                ->action(function (BusinessPartner $record, array $data, Action $action): void {
                    PartnerForms::run($action, fn () => app(BusinessPartners::class)->disableLogin($record, auth()->user(), $data['reason'] ?? null));
                    Notification::make()->success()->title('Login disabled')->send();
                }),
            Action::make('enableLogin')
                ->label('Enable login')
                ->icon(Heroicon::OutlinedLockOpen)
                ->visible(fn (BusinessPartner $record) => self::may($record) && $record->signInState() === 'disabled')
                ->requiresConfirmation()
                ->modalDescription('The partner can sign in again with the password they chose. A partner who never chose one needs a new invitation.')
                ->action(function (BusinessPartner $record, Action $action): void {
                    $status = PartnerForms::run($action, fn () => app(BusinessPartners::class)->enableLogin($record, auth()->user()));
                    Notification::make()->success()->title($status === UserStatus::Active ? 'Login enabled' : 'Login enabled: send them a new invitation to sign in')->send();
                }),
            Action::make('openSignIn')
                ->label('Open sign-in')
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('gray')
                ->url(fn (BusinessPartner $record) => UserResource::getUrl('edit', ['record' => $record->user_id]))
                ->visible(fn (BusinessPartner $record) => $record->user_id !== null && $record->user instanceof User && auth()->user()?->can('update', $record->user)),
        ];
    }

    /** Create or update through the service. */
    public static function save(Action $action, ?BusinessPartner $record, array $data): ?BusinessPartner
    {
        return PartnerForms::run($action, fn () => $record === null
            ? app(BusinessPartners::class)->create($data, auth()->user())
            : app(BusinessPartners::class)->update($record, $data, auth()->user()));
    }

    /** @return array<string, string> an option list's current options, plus the record's own value when hidden since */
    public static function options(string $list, ?string $current): array
    {
        $options = app(OptionLists::class)->options($list);
        if ($current !== null && ! isset($options[$current])) {
            $options[$current] = app(OptionLists::class)->label($list, $current);
        }

        return $options;
    }

    /** @return array<int, string> the active roles the signed-in person may give (RoleGrants) */
    public static function assignableRoles(): array
    {
        $actor = auth()->user();
        $grants = app(RoleGrants::class);

        return Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role) => ! $role->isInactive() && $grants->canAssign($actor, $role))
            ->pluck('name', 'id')->all();
    }

    /** Labels of an option list, read once per request by the register page (or here, outside it). */
    private static function labelsFrom(mixed $livewire, string $list): array
    {
        return is_object($livewire) && method_exists($livewire, 'optionLabels') ? $livewire->optionLabels($list) : app(OptionLists::class)->labels($list);
    }

    private static function may(BusinessPartner $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }

    public static function signInColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'invited' => 'info',
            'disabled' => 'danger',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return ['index' => ManageBusinessPartners::route('/')];
    }
}
