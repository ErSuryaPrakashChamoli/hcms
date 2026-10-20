<?php

namespace App\Filament\Resources\TalentPools;

use App\Domain\Talent\Models\TalentPool;
use App\Domain\Talent\Models\TalentPoolMembership;
use App\Domain\Talent\Services\TalentPools;
use App\Filament\Resources\TalentPools\Pages\ManageTalentPools;
use App\Filament\Support\TalentActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 9 talent pools. Membership is explicit (added by a person with a reason), effective-dated,
 * audited and confidential; it is never automatic and never visible to the member by default.
 */
class TalentPoolResource extends Resource
{
    protected static ?string $model = TalentPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Talent';

    protected static ?string $navigationLabel = 'Talent pools';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Textarea::make('criteria')->label('Criteria (for the people who nominate — nothing is applied automatically)')->rows(2)->columnSpanFull(),
            Select::make('status')->options(['active' => 'Active', 'retired' => 'Retired'])->default('active')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['memberships as active_members' => fn ($q) => $q->where('status', 'active')]))
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('active_members')->label('Active members'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('members')->label('Members')->icon(Heroicon::OutlinedListBullet)->color('gray')->modalSubmitAction(false)
                    ->schema(fn (TalentPool $record) => $record->memberships()->with('employee.person')->where('status', 'active')->orderBy('effective_from')->get()
                        ->filter(fn (TalentPoolMembership $m) => auth()->user()->can('view', $m))
                        ->map(fn (TalentPoolMembership $m) => TextEntry::make("m{$m->id}")->label(($m->employee?->employee_code ?? '').' · '.($m->employee?->person?->full_name ?? ''))
                            ->state('Since '.$m->effective_from?->toDateString())->helperText($m->reason))->values()->all() ?: [TextEntry::make('none')->hiddenLabel()->state('No active members you may see.')]),
                Action::make('add')->label('Add members')->icon(Heroicon::OutlinedUserPlus)->color('primary')
                    ->visible(fn (TalentPool $record) => $record->status === 'active' && auth()->user()->can('talent.manage'))
                    ->schema([
                        Select::make('employee_ids')->label('Employees')->multiple()->options(fn () => TalentActions::scopedPeopleOptions())->searchable()->required(),
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(fn (TalentPool $record, array $data) => TalentActions::run(fn () => app(TalentPools::class)->addMany($record, array_map('intval', $data['employee_ids']), $data['reason'], auth()->user()),
                        fn ($r) => "{$r['added']} added".($r['skipped'] !== [] ? ', '.count($r['skipped']).' skipped' : ''))),
                Action::make('end')->label('End membership')->icon(Heroicon::OutlinedUserMinus)->color('danger')
                    ->visible(fn (TalentPool $record) => auth()->user()->can('talent.manage'))
                    ->schema(fn (TalentPool $record) => [
                        Select::make('membership_id')->label('Member')->required()->options($record->memberships()->with('employee.person')->where('status', 'active')->get()
                            ->filter(fn (TalentPoolMembership $m) => auth()->user()->can('update', $m))->mapWithKeys(fn ($m) => [$m->id => "{$m->employee?->employee_code} · {$m->employee?->person?->full_name}"])->all()),
                        Textarea::make('reason')->required()->maxLength(500),
                    ])
                    ->action(fn (TalentPool $record, array $data) => TalentActions::run(fn () => app(TalentPools::class)->end($record->memberships()->findOrFail($data['membership_id']), $data['reason'], auth()->user()), 'Membership ended')),
            ])
            ->emptyStateHeading('No talent pools');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTalentPools::route('/')];
    }
}
