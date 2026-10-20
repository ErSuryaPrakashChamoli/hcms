<?php

namespace App\Filament\Resources\NotificationDeliveries\Pages;

use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Support\Pages\PeopleListRecords;

class ListNotificationDeliveries extends PeopleListRecords
{
    protected static string $resource = NotificationDeliveryResource::class;
}
