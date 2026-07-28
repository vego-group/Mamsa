<?php

use App\Providers\AdminNotificationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SmsServiceProvider;

return [
    AppServiceProvider::class,
    SmsServiceProvider::class,
    AdminNotificationServiceProvider::class,
];
