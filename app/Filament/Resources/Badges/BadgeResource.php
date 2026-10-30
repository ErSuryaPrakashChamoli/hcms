<?php

namespace App\Filament\Resources\Badges;

use App\Domain\Engagement\Models\MissionStatement;
use App\Domain\Identity\Models\Role;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Recognition\Models\Badge;
use App\Domain\Recognition\Services\Recognitions;
use App\Filament\Resources\Badges\Pages\ManageBadges;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity HP-9c (Engage → Badges, Custom badges): HROne's system badges (allowed or not, restyled) and the
 * organisation's custom badges, whose form follows HROne's steps: Appearance · Badge applicability · Rules (nature,
 * receiving frequency, reason and attachment, certificate) · Reward applicability (HP-9d: points with the badge, shown
 * on the social wall or not).
 */
class BadgeResource extends Resource
{
    protected static ?string $model = Badge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Badges';

    protected static ?int $navigationSort = 61;

    /** @return array<int, string> active letter templates a badge certificate may use (badge certificates and appreciation letters) */
    public static function certificateTemplates(): array
    {
        return LetterTemplate::query()->where('status', 'active')->whereIn('type', ['badge_certificate', 'appreciation'])->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> the core values of the active mission statements */
    public static function coreValues(): array
    {
        return MissionStatement::query()->where('status', 'active')->get()->flatMap(fn (MissionStatement $m) => array_column((array) $m->core_values, 'title'))
            ->filter()->unique()->sort()->mapWithKeys(fn (string $title) => [$title => $title])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $custom = fn (?Badge $record) => ! ($record?->is_system ?? false);

        return $schema->components([
            Section::make('Appearance')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(80),
                TextInput::make('code')->required()->maxLength(40)->alphaDash()
                    ->disabled(fn (?Badge $record) => $record?->is_system ?? false)->dehydrated()
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                Select::make('icon')->options(Badge::ICONS)->default('heroicon-o-star')->required()->native(false),
                Select::make('tone')->label('Colour')->options(Badge::TONES)->default('indigo')->required()->native(false),
                Textarea::make('description')->rows(2)->maxLength(500)->columnSpanFull(),
                Select::make('core_value')->label('Core value it stands for')->options(fn () => self::coreValues())->searchable()->placeholder('None'),
                Select::make('status')->label('Badge allowed')->options(Badge::STATUSES)->default('active')->required()->native(false),
            ]),
            Section::make('Badge applicability')->description('Who may give and receive this badge. Empty = everyone who may give badges / any colleague.')->visible($custom)->schema([
                Select::make('giver_roles')->label('Roles that may give it')->multiple()->options(fn () => Role::query()->orderBy('name')->pluck('name', 'slug')->all())->placeholder('Any role'),
                RuleConditionsSchema::repeater()->statePath('givers')->label('Givers must match')->defaultItems(0)->minItems(0),
                RuleConditionsSchema::repeater()->statePath('receivers')->label('Receivers must match')->defaultItems(0)->minItems(0),
            ]),
            Section::make('Rules')->columns(2)->visible($custom)->schema([
                Radio::make('visibility')->label('Nature of badge')->options(Badge::VISIBILITY)->default('public')->required()->columnSpanFull(),
                Toggle::make('restrict')->label('Do you want to restrict the frequency of receiving this badge for employees?')->live()->dehydrated()->columnSpanFull()
                    ->afterStateHydrated(fn (Toggle $component, ?Badge $record) => $component->state(($record?->receive_limit ?? 0) > 0)),
                TextInput::make('receive_limit')->label('At most (times)')->numeric()->integer()->minValue(1)->maxValue(100)->default(1)
                    ->visible(fn (Get $get) => (bool) $get('restrict'))->required(fn (Get $get) => (bool) $get('restrict')),
                Select::make('receive_period')->label('Per')->options(Badge::PERIODS)->default('month')->native(false)->visible(fn (Get $get) => (bool) $get('restrict')),
                Select::make('receive_period_basis')->label('Counted over')->options(Badge::BASES)->default('calendar')->native(false)
                    ->visible(fn (Get $get) => (bool) $get('restrict') && $get('receive_period') !== 'ever'),
                Toggle::make('reason_required')->label('Is reason mandatory while giving this badge?'),
                Toggle::make('attachment_required')->label('Is attachment mandatory while giving this badge?'),
                Select::make('certificate_template_id')->label('Map certificate for this badge')->options(fn () => self::certificateTemplates())->placeholder('No certificate (or the default in Setup → Engage)')
                    ->helperText('A letter template of type Badge certificate or Appreciation. It can use {{ badge.name }}, {{ recognition.date }}, {{ recognition.given_by }} and {{ recognition.reason }}.')->columnSpanFull(),
            ]),
            // HROne setup parity HP-9d (Custom badges → Reward applicability).
            Section::make('Reward applicability')->columns(2)->visible($custom)
                ->description('Points come from the reward points budget while reward points are on (Setup → Engage).')->schema([
                    Toggle::make('linked_reward')->label('Do you want to link this badge with reward points?')->live()->dehydrated()->columnSpanFull()
                        ->afterStateHydrated(fn (Toggle $component, ?Badge $record) => $component->state(($record?->reward_points ?? 0) > 0)),
                    TextInput::make('reward_points')->label('Reward amount (points)')->numeric()->integer()->minValue(1)->maxValue(100000)->default(10)
                        ->visible(fn (Get $get) => (bool) $get('linked_reward'))->required(fn (Get $get) => (bool) $get('linked_reward')),
                    Toggle::make('show_points_on_wall')->label('Do you want to show the reward amount on the social wall?')->visible(fn (Get $get) => (bool) $get('linked_reward')),
                ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    /** The receiving limit is kept only when "restrict" is on. @param  array<string, mixed>  $data */
    public static function normalise(array $data): array
    {
        if (array_key_exists('restrict', $data) && ! $data['restrict']) {
            $data['receive_limit'] = 0;
        }
        // HP-9d: the points are kept only when the badge is linked with reward points.
        if (array_key_exists('linked_reward', $data) && ! $data['linked_reward']) {
            $data['reward_points'] = 0;
            $data['show_points_on_wall'] = false;
        }
        unset($data['restrict'], $data['linked_reward']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->icon(fn (Badge $record) => $record->icon)->description(fn (Badge $record) => $record->description),
                TextColumn::make('is_system')->label('Type')->badge()->color('gray')->formatStateUsing(fn ($state) => $state ? 'Standard' : 'Custom'),
                TextColumn::make('visibility')->badge()->color(fn (string $state) => $state === 'public' ? 'info' : 'gray')->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('receive_limit')->label('Receiving limit')->state(fn (Badge $record) => $record->receive_limit > 0
                    ? $record->receive_limit.' per '.($record->receive_period === 'ever' ? 'person, ever' : ($record->receive_period_basis === 'rolling' ? 'rolling ' : '').$record->receive_period) : 'None'),
                IconColumn::make('reason_required')->label('Reason')->boolean()->toggleable(),
                IconColumn::make('attachment_required')->label('Attachment')->boolean()->toggleable(),
                TextColumn::make('certificateTemplate.name')->label('Certificate')->placeholder('—')->toggleable(),
                TextColumn::make('reward_points')->label('Reward points')->formatStateUsing(fn (int $state) => $state > 0 ? (string) $state : '—')->toggleable(),
                TextColumn::make('recognitions_count')->counts('recognitions')->label('Given'),
                TextColumn::make('status')->label('Allowed')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray')->formatStateUsing(fn (string $state) => $state === 'active' ? 'Yes' : 'No'),
            ])
            ->description(fn () => 'Badge quota: '.(($per = app(Recognitions::class)->perMonth()) === 0 ? 'no limit' : "{$per} badges per person per month").'. Change it in Setup → Engage.')
            ->filters([SelectFilter::make('status')->label('Allowed')->options(['active' => 'Yes', 'inactive' => 'No'])])
            ->defaultSort('sort_order')
            ->recordActions([
                Action::make('toggle')->label(fn (Badge $record) => $record->isActive() ? 'Do not allow' : 'Allow')
                    ->icon(fn (Badge $record) => $record->isActive() ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)->color('gray')
                    ->visible(fn () => auth()->user()->can('recognition.manage'))
                    ->action(function (Badge $record) {
                        $record->withAuditReason('Badge '.($record->isActive() ? 'not allowed' : 'allowed'))->update(['status' => $record->isActive() ? 'inactive' : 'active']);
                        Notification::make()->success()->title($record->name.($record->isActive() ? ' is allowed' : ' is not allowed'))->send();
                    }),
                EditAction::make()->using(function (Badge $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update(self::normalise($data));

                    return $record;
                }),
                DeleteAction::make()->visible(fn (Badge $record) => ! $record->is_system),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBadges::route('/')];
    }
}
