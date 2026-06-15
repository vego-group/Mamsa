<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FgcSmsProvider implements SmsProvider
{
    private const AUTH_URL = 'https://cnc.fgc.sa/authenticate';
    private const SEND_URL = 'https://cnc.fgc.sa/sendSmsNotifications';

    // OTP / transactional messages → messageTypeId = 1
    private const MSG_TYPE_SERVICE = 1;

    public function __construct(
        private string $username,
        private string $password,
        private string $senderName,
    ) {}

    public function send(string $toE164, string $message, ?string $senderId = null): void
    {
        // FGC token is valid for ~1 minute — fetch a fresh one per send.
        $token  = $this->authenticate();
        $msisdn = $this->toFgcFormat($toE164);
        $header = $senderId ?? $this->senderName;

        $response = Http::withHeaders([
            'Authorization' => $token,
            'Content-Type'  => 'application/json',
        ])->post(self::SEND_URL, [
            'data1' => [
                'msisdn'        => $msisdn,
                'text'          => $message,
                'header'        => $header,
                'messageTypeId' => self::MSG_TYPE_SERVICE,
            ],
        ]);

        if (! $response->successful()) {
            Log::error('FGC SMS send HTTP error', [
                'to'     => $toE164,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return;
        }

        $body = $response->json();

        // E001 = success; any other key = error code.
        if (! array_key_exists('E001', $body ?? [])) {
            $errorCode = array_key_first($body ?? []);
            Log::error('FGC SMS error code', [
                'to'   => $toE164,
                'code' => $errorCode,
                'desc' => $body[$errorCode] ?? null,
            ]);
        }
    }

    /**
     * Authenticate and return a fresh JWT token.
     * Token expires in ~1 minute per FGC docs — must be called before each send.
     */
    private function authenticate(): string
    {
        $response = Http::post(self::AUTH_URL, [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if (! $response->successful() || blank($response->json('token'))) {
            Log::error('FGC SMS authentication failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new \RuntimeException('FGC SMS: authentication failed');
        }

        return $response->json('token');
    }

    /**
     * Convert E.164 (+966XXXXXXXXX) to FGC format (966XXXXXXXXX — no plus sign).
     */
    private function toFgcFormat(string $e164): string
    {
        return ltrim($e164, '+');
    }
}
