<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Domain\Audit\Services\AuditIntegrityVerifier;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify integrity')
                ->icon('heroicon-o-shield-check')
                ->visible(fn () => auth()->user()->can('audit.verify'))
                ->action(function (AuditIntegrityVerifier $verifier, TenantContext $tenants) {
                    $result = $verifier->verify($tenants->id());

                    $result['valid']
                        ? Notification::make()->success()->title("Chain intact: {$result['checked']} events verified")->send()
                        : Notification::make()->danger()->title('Chain broken')->body("Event {$result['broken_event_id']}: {$result['reason']}")->persistent()->send();
                }),
        ];
    }
}
