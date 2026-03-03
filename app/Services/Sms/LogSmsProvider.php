<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

class LogSmsProvider implements \App\Services\Sms\SmsProvider
{
    public function send(string $toE164, string $message, ?string $senderId = null): void
    {
        Log::info("SMS (stub) to={$toE164} sender={$senderId} msg={$message}");
    }
}