<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Server-side source of truth for plan pricing. The client never dictates
     * the amount — we look it up here by the plan key it sends.
     */
    private const PLANS = [
        'standard'  => ['name' => 'Standard Plan',       'amount' => 497.00],
        'fasttrack' => ['name' => 'Fast Track Plan',     'amount' => 797.00],
        'vip'       => ['name' => 'VIP Credit Rebuild',  'amount' => 997.00],
        // Internal $5 live-transaction test. Not linked anywhere on the site;
        // reachable only via /checkout?plan=test. Flows through the exact same
        // charge → enrollment → payment → webhook → dashboard pipeline.
        'test'      => ['name' => 'Test Product ($5)',   'amount' => 5.00],
    ];

    private function isProduction(): bool
    {
        return config('services.authorizenet.environment') === 'production';
    }

    private function apiEndpoint(): string
    {
        return $this->isProduction()
            ? 'https://api.authorize.net/xml/v1/request.api'
            : 'https://apitest.authorize.net/xml/v1/request.api';
    }

    /**
     * Public, non-secret config the browser needs to tokenize the card with
     * Accept.js. The API Login ID and Public Client Key are designed to be
     * exposed client-side; the Transaction Key is NEVER sent to the browser.
     */
    public function config()
    {
        return response()->json([
            'apiLoginID'  => config('services.authorizenet.login_id'),
            'clientKey'   => config('services.authorizenet.public_client_key'),
            'acceptJsUrl' => $this->isProduction()
                ? 'https://js.authorize.net/v3/Accept.js'
                : 'https://jstest.authorize.net/v3/Accept.js',
            'environment' => config('services.authorizenet.environment'),
        ]);
    }

    /**
     * Charge a card. The browser has already tokenized the card via Accept.js,
     * so we only ever receive an opaque, single-use payment nonce here — never
     * the raw card number, expiry or CVV.
     */
    public function charge(Request $request)
    {
        $data = $request->validate([
            'opaqueDataDescriptor' => ['required', 'string'],
            'opaqueDataValue'      => ['required', 'string'],
            'plan'                 => ['required', 'string'],
            'first'                => ['required', 'string', 'max:100'],
            'last'                 => ['required', 'string', 'max:100'],
            'email'                => ['required', 'email', 'max:150'],
            'phone'                => ['nullable', 'string', 'max:40'],
            'address'              => ['nullable', 'string', 'max:200'],
            'city'                 => ['nullable', 'string', 'max:100'],
            'state'                => ['nullable', 'string', 'max:60'],
            'zip'                  => ['nullable', 'string', 'max:20'],
            'agree_terms'          => ['nullable', 'boolean'],
            'agree_privacy'        => ['nullable', 'boolean'],
            'agree_marketing'      => ['nullable', 'boolean'],
        ]);

        $planKey = strtolower($data['plan']);
        if (! isset(self::PLANS[$planKey])) {
            return response()->json(['ok' => false, 'error' => 'Unknown plan selected.'], 422);
        }
        $plan    = self::PLANS[$planKey];
        $amount  = number_format($plan['amount'], 2, '.', '');
        $invoice = substr('INV' . now()->format('ymdHis') . rand(10, 99), 0, 20);

        $login = config('services.authorizenet.login_id');
        $txKey = config('services.authorizenet.transaction_key');
        if (! $login || ! $txKey) {
            return response()->json(['ok' => false, 'error' => 'Payment is not configured.'], 500);
        }

        $payload = [
            'createTransactionRequest' => [
                'merchantAuthentication' => [
                    'name'           => $login,
                    'transactionKey' => $txKey,
                ],
                'refId' => substr('PFC' . now()->format('ymdHis'), 0, 20),
                'transactionRequest' => [
                    'transactionType' => 'authCaptureTransaction',
                    'amount'          => $amount,
                    'payment' => [
                        'opaqueData' => [
                            'dataDescriptor' => $data['opaqueDataDescriptor'],
                            'dataValue'      => $data['opaqueDataValue'],
                        ],
                    ],
                    'order' => [
                        'invoiceNumber' => $invoice,
                        'description'   => $plan['name'] . ' enrollment',
                    ],
                    'billTo' => array_filter([
                        'firstName' => $data['first'],
                        'lastName'  => $data['last'],
                        'address'   => $data['address'] ?? null,
                        'city'      => $data['city'] ?? null,
                        'state'     => $data['state'] ?? null,
                        'zip'       => $data['zip'] ?? null,
                        'country'   => 'USA',
                    ]),
                    'customer' => ['email' => $data['email']],
                ],
            ],
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->apiEndpoint(), $payload);
        } catch (\Throwable $e) {
            Log::error('Authorize.Net request failed', ['message' => $e->getMessage()]);
            return response()->json(['ok' => false, 'error' => 'We could not reach the payment processor. Please try again.'], 502);
        }

        // Authorize.Net's JSON responses are prefixed with a UTF-8 BOM that breaks json_decode.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $response->body());
        $json = json_decode($body, true);

        if (! is_array($json)) {
            Log::error('Authorize.Net unparseable response', ['body' => $body]);
            return response()->json(['ok' => false, 'error' => 'Unexpected response from the payment processor.'], 502);
        }

        $resultCode = data_get($json, 'messages.resultCode');
        $txn        = data_get($json, 'transactionResponse');
        $responseCode = data_get($txn, 'responseCode');

        // Approved
        if ($resultCode === 'Ok' && $responseCode === '1') {
            $accountNumber = (string) data_get($txn, 'accountNumber'); // e.g. XXXX1111
            $enrollment = Enrollment::create([
                'plan'            => $plan['name'],
                'amount'          => $plan['amount'],
                'first_name'      => $data['first'],
                'last_name'       => $data['last'],
                'email'           => $data['email'],
                'phone'           => $data['phone'] ?? null,
                'address'         => $data['address'] ?? null,
                'city'            => $data['city'] ?? null,
                'state'           => $data['state'] ?? null,
                'zip'             => $data['zip'] ?? null,
                'agree_terms'     => (bool) ($data['agree_terms'] ?? false),
                'agree_privacy'   => (bool) ($data['agree_privacy'] ?? false),
                'agree_marketing' => (bool) ($data['agree_marketing'] ?? false),
                'transaction_id'  => (string) data_get($txn, 'transId'),
                'invoice_number'  => $invoice,
                'auth_code'       => (string) data_get($txn, 'authCode'),
                'card_type'       => (string) data_get($txn, 'accountType'),
                'card_last4'      => substr($accountNumber, -4) ?: null,
                'payment_status'  => 'paid',
                'paid_at'         => now(),
                'status'          => 'new',
            ]);

            // Record the money row immediately (the webhook will also arrive and is idempotent).
            \App\Models\Payment::updateOrCreate(
                ['transaction_id' => (string) data_get($txn, 'transId'), 'type' => 'initial'],
                [
                    'enrollment_id'  => $enrollment->id,
                    'invoice_number' => $invoice,
                    'amount'         => $plan['amount'],
                    'status'         => 'captured',
                    'event_type_raw' => 'accept.js.charge',
                    'card_type'      => $enrollment->card_type,
                    'card_last4'     => $enrollment->card_last4,
                    'customer_name'  => trim($data['first'] . ' ' . $data['last']),
                    'customer_email' => $data['email'],
                    'charged_at'     => now(),
                    'raw_payload'    => ['source' => 'accept.js', 'transId' => (string) data_get($txn, 'transId')],
                ]
            );

            return response()->json([
                'ok'            => true,
                'transactionId' => $enrollment->transaction_id,
                'message'       => 'Payment approved.',
            ]);
        }

        // Declined / held / error — surface a clean message, store nothing.
        $error = data_get($txn, 'errors.0.errorText')
            ?? data_get($json, 'messages.message.0.text')
            ?? 'Your card could not be processed. Please check your details and try again.';

        Log::warning('Authorize.Net transaction not approved', [
            'resultCode'   => $resultCode,
            'responseCode' => $responseCode,
            'error'        => $error,
        ]);

        return response()->json(['ok' => false, 'error' => $error], 402);
    }
}
