<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoyasarService
{
    private string $baseUrl = 'https://api.moyasar.com/v1';
    private string $secretKey;
    private string $publishableKey;

    public function __construct()
    {
        $this->secretKey      = config('moyasar.secret_key');
        $this->publishableKey = config('moyasar.publishable_key');
    }

    /**
     * Create a Moyasar payment (returns payment object with hosted URL).
     */
    public function createPayment(array $params): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/payments", [
                'amount'      => $params['amount_halalas'], // amount in halalas (SAR * 100)
                'currency'    => 'SAR',
                'description' => $params['description'],
                'callback_url'=> $params['callback_url'],
                'source'      => [
                    'type'       => 'creditcard',
                    'name'       => $params['name'] ?? '',
                    'number'     => $params['card_number'] ?? '',
                    'cvc'        => $params['card_cvc'] ?? '',
                    'month'      => $params['card_month'] ?? '',
                    'year'       => $params['card_year'] ?? '',
                ],
                'metadata'    => $params['metadata'] ?? [],
            ]);

        if (! $response->successful()) {
            Log::error('Moyasar payment creation failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);
            throw new \RuntimeException('فشل في إنشاء الدفع: '.$response->json('message', 'خطأ غير معروف'));
        }

        return $response->json();
    }

    /**
     * Fetch payment status from Moyasar.
     */
    public function fetchPayment(string $moyasarId): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->get("{$this->baseUrl}/payments/{$moyasarId}");

        if (! $response->successful()) {
            throw new \RuntimeException('فشل في جلب بيانات الدفع');
        }

        return $response->json();
    }

    /**
     * Verify payment callback by re-fetching from Moyasar (no shared secret in basic auth model).
     */
    public function verifyCallback(string $moyasarId, float $expectedAmountSar): bool
    {
        try {
            $payment = $this->fetchPayment($moyasarId);
            $amountHalalas = (int) round($expectedAmountSar * 100);
            return $payment['status'] === 'paid'
                && (int) $payment['amount'] === $amountHalalas
                && $payment['currency'] === 'SAR';
        } catch (\Throwable $e) {
            Log::error('Moyasar callback verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }
}
