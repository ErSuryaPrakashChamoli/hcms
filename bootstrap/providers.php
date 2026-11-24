<?php

use App\Providers\AppServiceProvider;
use App\Providers\ConfigurationApplicabilityServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\ParityServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ParityServiceProvider::class,
    ConfigurationApplicabilityServiceProvider::class,
];
