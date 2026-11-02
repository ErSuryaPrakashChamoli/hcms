<?php

namespace App\Filament\Resources\BusinessPartners\Pages;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Partners\Models\BusinessPartner;
use App\Filament\Resources\BusinessPartners\BusinessPartnerResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

/**
 * HROne parity WO-15 (matrix 23 §23.8 #1–2, §23.9 #21): the business partners register. As a table or as cards (HROne's
 * partner cards: name, e-mail, mobile, type, sign-in, status) with `?layout=cards`; preset views, saved views and the
 * "Business partners" report (WO-1 register tools); "Add business partner" opens directly with ?action=create.
 */
class ManageBusinessPartners extends PeopleManageRecords
{
    use HasRegisterTools;

    protected static string $resource = BusinessPartnerResource::class;

    /** table (default) | cards */
    #[Url(as: 'layout')]
    public string $posLayout = 'table';

    /** @var array<string, array<string, string>> option list labels, read once per request */
    private array $labelMemo = [];

    public static function registerKey(): string
    {
        return 'business_partners';
    }

    public static function presetViews(): array
    {
        return [
            'active' => ['label' => 'Active partners', 'filters' => ['status' => ['value' => 'active']]],
            'not_invited' => ['label' => 'Not invited yet', 'filters' => ['status' => ['value' => 'active'], 'sign_in' => ['value' => 'none']]],
            'signing_in' => ['label' => 'Signing in', 'filters' => ['sign_in' => ['value' => 'active']]],
            'login_disabled' => ['label' => 'Login disabled', 'filters' => ['sign_in' => ['value' => 'disabled']]],
            'inactive' => ['label' => 'Inactive partners', 'filters' => ['status' => ['value' => 'inactive']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['business_partners'];
    }

    /** @return array<string, string> code => label of an option list (hidden options included, for older records) */
    public function optionLabels(string $list): array
    {
        return $this->labelMemo[$list] ??= app(OptionLists::class)->labels($list);
    }

    /** The register's filters as report conditions on the `partners` dataset. */
    public function registerReportFilters(): array
    {
        $out = [];
        foreach (['status' => 'status_code', 'partner_type' => 'partner_type_code', 'sign_in' => 'sign_in_code'] as $filter => $field) {
            $value = $this->tableFilters[$filter]['value'] ?? null;
            if (filled($value)) {
                $out[] = ['field' => $field, 'operator' => 'equals', 'value' => (string) $value];
            }
        }

        return $out;
    }

    /** Cards (HROne's partner cards) or the table. */
    public function table(Table $table): Table
    {
        $table = parent::table($table);
        if ($this->posLayout !== 'cards') {
            return $table;
        }

        return $table->contentGrid(['md' => 2, 'xl' => 3])->columns([
            Stack::make([
                TextColumn::make('first_name')->label('Name')->weight('bold')
                    ->state(fn (BusinessPartner $record) => $record->displayName($this->optionLabels('salutations')))
                    ->description(fn (BusinessPartner $record) => '#'.$record->code)
                    ->searchable(query: BusinessPartnerResource::search()),
                TextColumn::make('card_email')->label('E-mail')->icon(Heroicon::OutlinedEnvelope)->color('gray')
                    ->state(fn (BusinessPartner $record) => $record->official_email ?: $record->personal_email)->placeholder('No e-mail'),
                TextColumn::make('card_mobile')->label('Mobile')->icon(Heroicon::OutlinedPhone)->color('gray')
                    ->state(fn (BusinessPartner $record) => $record->mobileDisplay())->placeholder('No mobile'),
                TextColumn::make('partner_type')->label('Type')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => $this->optionLabels('partner_types')[$state] ?? $state),
                TextColumn::make('card_sign_in')->label('Sign-in')->badge()
                    ->state(fn (BusinessPartner $record) => BusinessPartner::SIGN_IN[$record->signInState()])
                    ->color(fn (BusinessPartner $record) => BusinessPartnerResource::signInColor($record->signInState())),
            ])->space(1),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->registerToolActions(),
            Action::make('posLayout')
                ->label(fn () => $this->posLayout === 'cards' ? 'Table' : 'Cards')
                ->icon(fn () => $this->posLayout === 'cards' ? Heroicon::OutlinedTableCells : Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(fn () => static::getResource()::getUrl('index', ['layout' => $this->posLayout === 'cards' ? 'table' : 'cards'])),
            CreateAction::make()->label('Add business partner')
                ->using(fn (array $data, Action $action) => BusinessPartnerResource::save($action, null, $data)),
        ];
    }
}
