<?php

namespace App\Filament\Resources\BulkRuns\RelationManagers;

use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Services\BulkInvitations;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity WO-16 (§23.9 #14): the delivery list of an invitation / reset-link run — employee code, name, e-mail,
 * e-mail status (sent, accepted, expired, not sent with the reason, failed) and the CC notices with their delivery
 * status, with a CSV download. Built from the run's items, the invitations and the notification deliveries; it never
 * holds or shows a link or token. Shown only on such runs, to whoever may open the run.
 */
class DeliveryListRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Delivery list';

    /** @var array<int, array<string, mixed>>|null rows by item id, built once per request */
    private ?array $rows = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof BulkRun && BulkInvitations::isInvitationRun($ownerRecord) && (auth()->user()?->can('view', $ownerRecord) ?? false);
    }

    public function table(Table $table): Table
    {
        $cell = fn (string $key) => fn (BulkRunItem $record) => ($this->rows()[(int) $record->getKey()][$key] ?? '') ?: null;

        return $table
            ->columns([
                TextColumn::make('code')->label('Employee code')->state($cell('code'))->placeholder('—'),
                TextColumn::make('name')->label('Employee name')->state($cell('name'))->placeholder('—')->wrap(),
                TextColumn::make('email')->label('E-mail')->state($cell('email'))->placeholder('—'),
                TextColumn::make('email_status')->label('E-mail status')->badge()->state($cell('status'))
                    ->color(fn (?string $state) => match ($state) {
                        'Accepted', 'Reset link sent', 'Sent — waiting for acceptance', 'Sent' => 'success',
                        'Not sent', 'Expired', 'Replaced or revoked' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('detail')->label('Reason')->state($cell('detail'))->placeholder('—')->wrap(),
                TextColumn::make('cc')->label('CC notices')->state($cell('cc'))->placeholder('—')->wrap()->toggleable(),
            ])
            ->headerActions([
                Action::make('downloadDeliveryList')->label('Download list')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(function () {
                        /** @var BulkRun $run */
                        $run = $this->getOwnerRecord();
                        $csv = app(BulkInvitations::class)->deliveryCsv($run);

                        return response()->streamDownload(fn () => print ($csv), 'delivery-list-'.$run->getKey().'.csv', ['Content-Type' => 'text/csv']);
                    }),
            ])
            ->defaultSort('id');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->rows === null) {
            /** @var BulkRun $run */
            $run = $this->getOwnerRecord();
            $this->rows = collect(app(BulkInvitations::class)->deliveryRows($run))->keyBy('item_id')->all();
        }

        return $this->rows;
    }
}
