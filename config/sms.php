<?php

return [
    'driver'    => env('SMS_DRIVER', 'log'),
    'sender_id' => env('SMS_SENDER_ID', 'Mamsa'),

    'fgc' => [
        'username'    => env('FGC_SMS_USERNAME'),
        'password'    => env('FGC_SMS_PASSWORD'),
        'sender_name' => env('FGC_SMS_SENDER', env('SMS_SENDER_ID', 'Mamsa')),
    ],

    'taqnyat' => [
        'api_key'   => env('TAQNYAT_API_KEY'),
        'sender_id' => env('TAQNYAT_SENDER_ID', env('SMS_SENDER_ID', 'Mamsa')),
    ],
];
