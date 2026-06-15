<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaqnyatSmsProvider implements SmsProvider
{
    public function __construct(
        private string $apiKey,
        private string $defaultSender,
    ) {}

    public function send(string $toE164, string $message, ?string $senderId = null): void
    {
        $recipient = ltrim($toE164, '+');
        $sender    = $senderId ?? $this->defaultSender;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.taqnyat.sa/v1/messages', [
            'sender'     => $sender,
            'recipients' => [$recipient],
            'body'       => $message,
        ]);

        if (! $response->successful()) {
            Log::error('Taqnyat SMS failed', [
                'to'     => $toE164,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }
    }
}
