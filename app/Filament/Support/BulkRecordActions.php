<?php

namespace App\Filament\Support;

use App\Domain\Experience\Contracts\BulkOperation;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Filament\Resources\BulkRuns\BulkRunResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * HROne parity WO-1: bulk actions for any Filament register ("filter, select, act").
 *
 * - selected(): the table's bulk actions — on the rows ticked, or on every record when Filament's "Select all" is used.
 *   Put the group in the table's toolbarActions().
 * - allMatching(): a header action group acting on every record that matches the register's current filters, lens and
 *   search (a filter or a search must be applied first, so a whole register is never acted on by accident).
 *
 * Both ask the operation's inputs once, ask the person to type the number of records when there are more than
 * `peopleos.bulk.confirm_over`, and start a BulkRuns run (queued; one result per record, a notification at the end).
 * They are offered only to people holding bulk.run and the operation's permission; every record is still authorised
 * on its own when the run executes. See docs/architecture/hrone-parity.md "WO-1 / WO-2" for how a register opts in.
 */
final class BulkRecordActions
{
    /** @param  list<string>  $operationKeys */
    public static function selected(string $register, array $operationKeys): BulkActionGroup
    {
        $actions = [];
        foreach (self::operations($operationKeys) as $operation) {
            $actions[] = BulkAction::make(self::name('bulk', $operation))
                ->label($operation->label())
                ->icon($operation->icon())
                ->color($operation->color())
                ->visible(fn () => self::allowed($operation))
                ->fetchSelectedRecords(false)
                ->deselectRecordsAfterCompletion()
                ->modalHeading(fn (BulkAction $action) => $operation->label().' · '.self::countLabel(count($action->getSelectedRecords())))
                ->modalSubmitActionLabel('Start')
                ->schema(fn (BulkAction $action) => self::form($operation, count($action->getSelectedRecords())))
                ->action(fn (BulkAction $action, array $data) => self::start($register, $operation, collect($action->getSelectedRecords())->all(), $data, 'selected'));
        }

        return BulkActionGroup::make($actions)->label('Bulk actions')->icon(Heroicon::OutlinedBolt);
    }

    /** @param  list<string>  $operationKeys */
    public static function allMatching(string $register, array $operationKeys): ActionGroup
    {
        $actions = [];
        foreach (self::operations($operationKeys) as $operation) {
            $actions[] = Action::make(self::name('all', $operation))
                ->label($operation->label())
                ->icon($operation->icon())
                ->color($operation->color())
                ->visible(fn () => self::allowed($operation))
                ->modalHeading(fn ($livewire) => $operation->label().' · all '.self::countLabel(self::matchingCount($livewire)).' matching the filters')
                ->modalSubmitActionLabel('Start')
                ->schema(fn ($livewire) => self::isNarrowed($livewire)
                    ? self::form($operation, self::matchingCount($livewire), true)
                    : [Text::make('Apply a filter or a search first: a bulk action on all matching records needs the register narrowed, so a whole register is never acted on by accident.')])
                ->action(function ($livewire, array $data) use ($register, $operation) {
                    if (! self::isNarrowed($livewire)) {
                        Notification::make()->warning()->title('Apply a filter or a search first')->body('A bulk action on all matching records needs the register narrowed first, so a whole register is never acted on by accident.')->send();

                        return;
                    }
                    $query = $livewire->getFilteredTableQuery();
                    $ids = $query->toBase()->limit(BulkRuns::maxRecords() + 1)->pluck($query->getModel()->getQualifiedKeyName())->all();
                    self::start($register, $operation, $ids, $data, 'all_matching');
                });
        }

        return ActionGroup::make($actions)->label('Act on all matching')->icon(Heroicon::OutlinedBolt)->button()->color('gray');
    }

    /** Is the register narrowed by a filter, a status lens or a search? */
    public static function isNarrowed(object $livewire): bool
    {
        $filters = collect($livewire->tableFilters ?? [])->flatten()->filter(fn ($v) => filled($v) && $v !== false)->isNotEmpty();

        return $filters || filled($livewire->tableSearch ?? null) || (property_exists($livewire, 'posLens') && $livewire->posLens !== null);
    }

    public static function matchingCount(object $livewire): int
    {
        return (int) $livewire->getFilteredTableQuery()->count();
    }

    /** @return list<mixed> */
    private static function form(BulkOperation $operation, int $count, bool $allMatching = false): array
    {
        $over = BulkRuns::needsTypedCount($count);

        return [
            Text::make(($allMatching ? 'Every record matching the register\'s filters and search right now: ' : '').self::countLabel($count).'. '.$operation->description())->columnSpanFull(),
            ...$operation->schema(),
            TextInput::make('confirm_count')
                ->label('Type '.$count.' to confirm')
                ->helperText('You are about to act on '.$count.' records (more than '.BulkRuns::confirmOver().'). Records you may not act on are listed in the result, not changed.')
                ->required()->in([(string) $count])->validationMessages(['in' => 'Type exactly '.$count.'.'])
                ->visible($over),
        ];
    }

    /** @param  list<int|string>  $ids */
    private static function start(string $register, BulkOperation $operation, array $ids, array $data, string $selection): void
    {
        $confirmed = isset($data['confirm_count']) && is_numeric($data['confirm_count']) ? (int) $data['confirm_count'] : null;
        unset($data['confirm_count']);
        try {
            $run = app(BulkRuns::class)->start(auth()->user(), $register, $operation->key(), array_values($ids), $data, $selection, $confirmed);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Bulk run not started')->body($e->getMessage())->send();

            return;
        }
        $run->refresh();
        $open = Action::make('open')->label('See each record')->url(BulkRunResource::getUrl('view', ['record' => $run]));
        $notification = $run->isFinished()
            ? Notification::make()->title($operation->label().': '.$run->summary())->body($run->refused + $run->failed > 0 ? 'Some records were not done; the run lists each one with the reason.' : 'Done for every record.')
            : Notification::make()->title($operation->label().' queued for '.self::countLabel($run->total))->body('You will be notified with the result.');
        $notification->status($run->status === 'failed' ? 'danger' : ($run->refused + $run->failed > 0 ? 'warning' : 'success'))->actions([$open])->send();
    }

    /** @return list<BulkOperation> */
    private static function operations(array $keys): array
    {
        $registry = app(BulkOperations::class);

        return array_values(array_filter(array_map(fn (string $key) => $registry->find($key), $keys)));
    }

    private static function allowed(BulkOperation $operation): bool
    {
        $user = auth()->user();

        return $user !== null && app(BulkOperations::class)->allows($user, $operation);
    }

    private static function name(string $prefix, BulkOperation $operation): string
    {
        return $prefix.'_'.preg_replace('/[^a-z0-9]+/', '_', $operation->key());
    }

    private static function countLabel(int $count): string
    {
        return $count === 1 ? '1 record' : $count.' records';
    }
}
