<?php

namespace App\Filament\Resources\RuleNotices;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Compliance\Models\ComplianceRuleNotice;
use App\Domain\Compliance\Services\RuleVerifications;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\RuleNotices\Pages\ListRuleNotices;
use App\Filament\Resources\StatutoryRegistrations\StatutoryRegistrationResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Phase 6: regulatory change notices that block out-of-date rule versions until a new version resolves them. */
class RuleNoticeResource extends Resource
{
    protected static ?string $model = ComplianceRuleNotice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Regulatory notices';

    protected static ?int $navigationSort = 22;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->badge(),
                TextColumn::make('state')->placeholder('All'),
                TextColumn::make('affects_versions')->label('Affects')->formatStateUsing(fn ($state) => 'v'.implode(', v', (array) $state)),
                TextColumn::make('effective_date')->date()->sortable(),
                TextColumn::make('title')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'open' ? 'danger' : 'success'),
                TextColumn::make('resolvedBy.version')->label('Resolved by')->formatStateUsing(fn ($state) => $state ? "v{$state}" : null)->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Resolved'])])
            ->recordActions([
                Action::make('details')->icon('heroicon-m-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('summary'),
                        TextEntry::make('references')->state(fn (ComplianceRuleNotice $record) => collect($record->references)->map(fn ($r) => $r['title'].($r['url'] ? ' — '.$r['url'] : '').(isset($r['sha256']) ? ' — sha256 '.$r['sha256'] : ''))->all())->listWithLineBreaks(),
                        TextEntry::make('retrieved_at')->date(),
                        TextEntry::make('resolution_note')->placeholder('—'),
                    ]),
                Action::make('resolve')->icon('heroicon-m-check')->requiresConfirmation()
                    ->visible(fn (ComplianceRuleNotice $record) => $record->status === 'open' && (bool) auth()->user()?->isPlatformAdmin())
                    ->schema(fn (ComplianceRuleNotice $record) => [
                        Select::make('rule_id')->label('New version that addresses it')->required()
                            ->options(ComplianceRule::query()->where('jurisdiction', $record->jurisdiction)->where('code', $record->code)->whereNotIn('version', (array) $record->affects_versions)
                                ->get()->mapWithKeys(fn ($r) => [$r->id => $r->label().($r->state ? " ({$r->state})" : '').' — '.$r->verification_status])->all()),
                        Textarea::make('note')->required()->rows(2),
                    ])
                    ->action(fn (ComplianceRuleNotice $record, array $data) => StatutoryRegistrationResource::attempt(function () use ($record, $data) {
                        /** @var User $user */
                        $user = auth()->user();
                        app(RuleVerifications::class)->resolveNotice($record, ComplianceRule::query()->findOrFail($data['rule_id']), $user, $data['note']);
                    }, 'Notice resolved')),
            ])
            ->defaultSort('effective_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListRuleNotices::route('/')];
    }
}
