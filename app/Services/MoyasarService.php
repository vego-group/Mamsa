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
     * Charge using a Moyasar card token (PCI-compliant — card never touches our server).
     * Token is obtained by the frontend via Moyasar.js using the publishable key.
     *
     * status = 'paid'      → payment succeeded immediately
     * status = 'initiated' → 3DS required, open source.transaction_url
     * status = 'failed'    → card declined, check source.message
     */
    public function chargeWithToken(string $token, array $params): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/payments", [
                'amount'       => $params['amount_halalas'],
                'currency'     => 'SAR',
                'description'  => $params['description'],
                'callback_url' => $params['callback_url'],
                'source'       => [
                    'type'  => 'token',
                    'token' => $token,
                ],
                'metadata' => $params['metadata'] ?? [],
            ]);

        if ($response->status() >= 500) {
            Log::error('Moyasar server error', ['status' => $response->status(), 'body' => $response->json()]);
            throw new \RuntimeException('خدمة الدفع غير متاحة حالياً، حاول مرة أخرى');
        }

        return $response->json();
    }

    /**
     * Step 1 of Apple Pay: validate merchant with Apple via Moyasar.
     * Called when Apple Pay triggers onvalidatemerchant(event).
     * Frontend sends event.validationURL → we forward to Moyasar → return merchantSession.
     */
    public function validateApplePayMerchant(string $validationUrl): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/applepay/initiate", [
                'validation_url' => $validationUrl,
            ]);

        if (! $response->successful()) {
            Log::error('Apple Pay merchant validation failed', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);
            throw new \RuntimeException('فشل التحقق من Apple Pay: ' . $response->json('message', 'خطأ غير معروف'));
        }

        return $response->json();
    }

    /**
     * Step 2 of Apple Pay: charge using the Apple Pay payment token.
     * Frontend sends the PKPaymentToken.paymentData (JSON object from Apple).
     */
    public function chargeWithApplePay(array $applePayToken, array $params): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/payments", [
                'amount'       => $params['amount_halalas'],
                'currency'     => 'SAR',
                'description'  => $params['description'],
                'callback_url' => $params['callback_url'],
                'source'       => [
                    'type'  => 'applepay',
                    'token' => $applePayToken,   // raw PKPaymentToken.paymentData object
                ],
                'metadata' => $params['metadata'] ?? [],
            ]);

        if ($response->status() >= 500) {
            Log::error('Apple Pay charge failed', ['status' => $response->status(), 'body' => $response->json()]);
            throw new \RuntimeException('خدمة الدفع غير متاحة حالياً، حاول مرة أخرى');
        }

        return $response->json();
    }

    /**
     * Charge using raw card details (use only when token flow is not possible).
     */
    public function charge(array $params): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->post("{$this->baseUrl}/payments", [
                'amount'       => $params['amount_halalas'],
                'currency'     => 'SAR',
                'description'  => $params['description'],
                'callback_url' => $params['callback_url'],
                'source'       => [
                    'type'   => 'creditcard',
                    'name'   => $params['name'],
                    'number' => $params['number'],
                    'cvc'    => $params['cvc'],
                    'month'  => $params['month'],
                    'year'   => $params['year'],
                    '3ds'    => true,
                ],
                'metadata' => $params['metadata'] ?? [],
            ]);

        if ($response->status() >= 500) {
            Log::error('Moyasar server error', ['status' => $response->status(), 'body' => $response->json()]);
            throw new \RuntimeException('خدمة الدفع غير متاحة حالياً، حاول مرة أخرى');
        }

        return $response->json();
    }

    /**
     * Create a Moyasar payment (returns payment object with hosted URL).
     */
    public function createPayment(array $params): array
    {
        return $this->charge($params);
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
