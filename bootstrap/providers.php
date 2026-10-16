<?php

use App\Providers\AppServiceProvider;

return [
    App\Providers\PaymentServiceProvider::class,
    App\Providers\DocumentServiceProvider::class,
    AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
];
