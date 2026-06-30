<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HubtelPaymentService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $merchantAccountNumber;

    protected string $baseUrl;

    protected string $statusCheckUrl;

    protected ?string $rmpClientId;

    protected ?string $rmpClientSecret;

    protected string $rmpBaseUrl;

    public function __construct()
    {
        $this->clientId = config('services.hubtel.payment_client_id');
        $this->clientSecret = config('services.hubtel.payment_client_secret');
        $this->merchantAccountNumber = config('services.hubtel.merchant_account_number');
        $this->baseUrl = config('services.hubtel.base_url', 'https://payproxyapi.hubtel.com');
        $this->statusCheckUrl = config('services.hubtel.status_check_url', 'https://api-txnstatus.hubtel.com');
        $this->rmpClientId = config('services.hubtel.rmp_client_id');
        $this->rmpClientSecret = config('services.hubtel.rmp_client_secret');
        $this->rmpBaseUrl = config('services.hubtel.rmp_base_url', 'https://rmp.hubtel.com');
    }

    /**
     * Validate that Hubtel credentials are configured
     *
     * @throws RuntimeException
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->merchantAccountNumber)) {
            throw new RuntimeException(
                'Hubtel payment gateway is not properly configured. '.
                'Please set HUBTEL_CLIENT_ID, HUBTEL_CLIENT_SECRET, and HUBTEL_MERCHANT_ACCOUNT_NUMBER in your environment.'
            );
        }
    }

    /**
     * Build Basic Auth header value
     *
     * @return string Base64 encoded credentials
     */
    protected function getAuthHeader(): string
    {
        return 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret);
    }

    /**
     * Map Hubtel response code to descriptive error message
     *
     * @param  string  $responseCode  Hubtel response code
     * @return string Human-readable error message
     */
    protected function mapResponseCodeToMessage(string $responseCode): string
    {
        return match ($responseCode) {
            '0000' => 'Payment successful',
            '0005' => 'Payment processor error or network issue. Please try again.',
            '2001' => 'Transaction failed. Please try again or use a different payment method.',
            '4000' => 'Invalid payment data. Please check your information and try again.',
            '4070' => 'Payment amount issue. Please contact support.',
            default => 'An unexpected error occurred. Please try again or contact support.',
        };
    }

    /**
     * Map Hubtel status to application payment status
     *
     * @param  string  $hubtelStatus  Status from Hubtel (Success, Paid, Unpaid, Refunded)
     * @param  string  $responseCode  Response code from Hubtel
     * @return string Application payment status
     */
    protected function mapHubtelStatusToPaymentStatus(string $hubtelStatus, string $responseCode): string
    {
        // Failed response code overrides status
        if ($responseCode !== '0000') {
            return 'failed';
        }

        return match (strtolower($hubtelStatus)) {
            'success', 'paid' => 'completed',
            'unpaid' => 'pending',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Amount to charge / record for the restaurant: goods only (order total minus
     * the third-party delivery fee, which the rider collects on delivery). Works
     * for Order models and the lightweight stubs used by checkout-session flows;
     * a stub missing delivery_fee degrades safely to the full total.
     */
    protected function chargeable(object $order): float
    {
        return round((float) $order->total_amount - (float) ($order->delivery_fee ?? 0), 2);
    }

    /**
     * Execute HTTP request with retry logic
     *
     * @param  callable  $request  The HTTP request to execute
     * @param  int  $maxRetries  Maximum retry attempts
     *
     * @throws \Exception When all retries are exhausted
     */
    protected function executeWithRetry(callable $request, int $maxRetries = 3): \Illuminate\Http\Client\Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $request();
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $maxRetries) {
                    // Exponential backoff: 1s, 2s, 4s
                    $delay = pow(2, $attempt - 1);
                    sleep($delay);

                    Log::warning('Hubtel API request failed, retrying', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay' => $delay,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::error('Hubtel API request failed after all retries', [
            'attempts' => $attempt,
            'error' => $lastException->getMessage(),
        ]);

        throw new \Exception('Failed to connect to Hubtel payment gateway. Please try again later.');
    }

    /**
     * Sanitize data for logging by masking sensitive information
     *
     * @param  array  $data  Data to sanitize
     * @return array Sanitized data
     */
    protected function sanitizeForLogging(array $data): array
    {
        $sanitized = $data;

        // Remove sensitive fields
        unset($sanitized['client_secret']);

        // Mask phone numbers (show first 3 and last 2 digits)
        if (isset($sanitized['customer_phone'])) {
            $phone = $sanitized['customer_phone'];
            if (strlen($phone) > 5) {
                $sanitized['customer_phone'] = substr($phone, 0, 3).'****'.substr($phone, -2);
            }
        }

        if (isset($sanitized['payeeMobileNumber'])) {
            $phone = $sanitized['payeeMobileNumber'];
            if (strlen($phone) > 5) {
                $sanitized['payeeMobileNumber'] = substr($phone, 0, 3).'****'.substr($phone, -2);
            }
        }

        if (isset($sanitized['CustomerPhoneNumber'])) {
            $phone = $sanitized['CustomerPhoneNumber'];
            if (strlen($phone) > 5) {
                $sanitized['CustomerPhoneNumber'] = substr($phone, 0, 3).'****'.substr($phone, -2);
            }
        }

        // Mask emails (show first 3 chars and domain)
        if (isset($sanitized['customer_email'])) {
            $email = $sanitized['customer_email'];
            $parts = explode('@', $email);
            if (count($parts) === 2) {
                $sanitized['customer_email'] = substr($parts[0], 0, 3).'***@'.$parts[1];
            }
        }

        if (isset($sanitized['payeeEmail'])) {
            $email = $sanitized['payeeEmail'];
            $parts = explode('@', $email);
            if (count($parts) === 2) {
                $sanitized['payeeEmail'] = substr($parts[0], 0, 3).'***@'.$parts[1];
            }
        }

        return $sanitized;
    }

    /**
     * Initialize a payment transaction with Hubtel
     *
     * @param  array  $data  Payment initialization data
     * @return array Normalized response with checkout URLs
     *
     * @throws \Exception When initialization fails
     */
    public function initializeTransaction(array $data): array
    {
        $this->validateConfiguration();

        $order = $data['order'];

        // Build Hubtel API request payload. Charge the GOODS amount only — the
        // third-party delivery fee is collected by the rider on delivery, never
        // through our payment rails.
        $payload = [
            'totalAmount' => $this->chargeable($order),
            'description' => $data['description'],
            'callbackUrl' => route('payments.hubtel.callback'),
            'returnUrl' => $data['return_url'] ?? config('app.frontend_url')."/orders/{$order->order_number}/payment/success",
            'cancellationUrl' => $data['cancellation_url'] ?? config('app.frontend_url')."/orders/{$order->order_number}/payment/cancelled",
            'merchantAccountNumber' => $this->merchantAccountNumber,
            'clientReference' => $order->order_number,
        ];

        // Include customer details - use provided data or fall back to order contact info for guest customers
        if (! empty($data['customer_name'])) {
            $payload['payeeName'] = $data['customer_name'];
        } elseif (! empty($order->contact_name)) {
            $payload['payeeName'] = $order->contact_name;
        }

        if (! empty($data['customer_phone'])) {
            $payload['payeeMobileNumber'] = $data['customer_phone'];
        } elseif (! empty($order->contact_phone)) {
            $payload['payeeMobileNumber'] = $order->contact_phone;
        }

        if (! empty($data['customer_email'])) {
            $payload['payeeEmail'] = $data['customer_email'];
        }

        // Log payment initiation with sanitized data
        Log::info('Hubtel payment initiation started', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount' => $this->chargeable($order),
            'client_reference' => $payload['clientReference'],
            'payload' => $this->sanitizeForLogging($payload),
            'Authorization' => $this->getAuthHeader(),
        ]);

        // Send POST request to Hubtel initiate endpoint
        $response = Http::withHeaders([
            'Authorization' => $this->getAuthHeader(),
        ])->post($this->baseUrl.'/items/initiate', $payload);

        if (! $response->successful()) {
            $responseCode = $response->json('ResponseCode', 'unknown');
            $message = $this->mapResponseCodeToMessage($responseCode);

            Log::error('Hubtel payment initiation failed', [
                'order_id' => $order->id,
                'endpoint' => $this->baseUrl.'/items/initiate',
                'response_code' => $responseCode,
                'message' => $message,
                'status_code' => $response->status(),
            ]);

            throw new \Exception($message);
        }

        $responseData = $response->json('data', []);

        // Only create a Payment record when called with a real Order (legacy flow).
        // Checkout session flows must NOT create Payment here — the Order (and its
        // Payment) is created later by OrderCreationService when payment is confirmed.
        $payment = null;
        $createPaymentRecord = ($data['create_payment_record'] ?? false) && isset($order->id) && is_int($order->id);

        if ($createPaymentRecord) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'payment_method' => 'mobile_money',
                'payment_status' => 'pending',
                'amount' => $this->chargeable($order),
                'transaction_id' => $responseData['checkoutId'] ?? null,
                'payment_gateway_response' => $responseData,
            ]);
        }

        Log::info('Hubtel payment initiated', [
            'order_id' => $order->id ?? null,
            'order_number' => $order->order_number,
            'amount' => $this->chargeable($order),
            'checkout_id' => $responseData['checkoutId'] ?? null,
            'payment_record_created' => $createPaymentRecord,
        ]);

        // Return normalized response
        return [
            'payment' => $payment,
            'checkoutUrl' => $responseData['checkoutUrl'] ?? null,
            'checkoutDirectUrl' => $responseData['checkoutDirectUrl'] ?? null,
            'checkoutId' => $responseData['checkoutId'] ?? null,
            'clientReference' => $responseData['clientReference'] ?? $payload['clientReference'],
        ];
    }

    /**
     * Handle payment callback from Hubtel
     *
     * @param  array  $payload  The callback payload from Hubtel
     *
     * @throws \Exception When callback processing fails
     */
    public function handleCallback(array $payload): void
    {
        $this->validateConfiguration();

        try {
            // Parse callback JSON payload
            $responseCode = $payload['ResponseCode'] ?? null;
            $status = $payload['Status'] ?? null;
            $data = $payload['Data'] ?? [];

            if (empty($responseCode) || empty($status) || empty($data)) {
                Log::error('Hubtel callback missing required fields', [
                    'payload' => $this->sanitizeForLogging($payload),
                ]);
                throw new \Exception('Invalid callback payload');
            }

            // Extract payment details from Data object
            $checkoutId = $data['CheckoutId'] ?? null;
            $salesInvoiceId = $data['SalesInvoiceId'] ?? null;
            $clientReference = $data['ClientReference'] ?? null;
            $amount = $data['Amount'] ?? null;
            $customerPhoneNumber = $data['CustomerPhoneNumber'] ?? null;
            $paymentDetails = $data['PaymentDetails'] ?? [];

            if (empty($clientReference)) {
                Log::error('Hubtel callback missing clientReference', [
                    'payload' => $this->sanitizeForLogging($payload),
                ]);
                throw new \Exception('Missing clientReference in callback');
            }

            // Map PaymentType to payment_method
            $paymentType = $paymentDetails['PaymentType'] ?? null;
            $paymentMethod = match (strtolower($paymentType ?? '')) {
                'mobilemoney' => 'mobile_money',
                'card' => 'card',
                'wallet' => 'wallet',
                'ghqr' => 'ghqr',
                'cash' => 'cash',
                default => 'mobile_money', // Default fallback
            };

            $paymentStatus = $this->mapHubtelStatusToPaymentStatus($status, $responseCode);

            // ── New flow: Try CheckoutSession first ──
            // Look up by exact session_token, checkoutId, or by prefix match
            // (handles legacy truncated clientReference where UUID was cut to 32 chars)
            $checkoutSession = \App\Models\CheckoutSession::where('session_token', $clientReference)
                ->orWhere('hubtel_transaction_id', $checkoutId)
                ->when(strlen($clientReference) < 36, function ($q) use ($clientReference) {
                    $q->orWhere('session_token', 'like', $clientReference.'%');
                })
                ->first();

            if ($checkoutSession) {
                $checkoutSession->update([
                    'payment_gateway_response' => $payload,
                    'payment_method' => $paymentMethod,
                ]);

                // Guard: do not convert expired/abandoned sessions
                if (in_array($checkoutSession->status, ['expired', 'abandoned'])) {
                    Log::warning('Hubtel callback: ignoring completed payment for expired/abandoned session', [
                        'session_id' => $checkoutSession->id,
                        'session_status' => $checkoutSession->status,
                        'client_reference' => $clientReference,
                    ]);

                    return;
                }

                if ($paymentStatus === 'completed' && ! $checkoutSession->order_id) {
                    $order = $checkoutSession->convertToOrder();
                    \App\Events\OrderBroadcastEvent::dispatch($order, 'created');

                    Log::info('Hubtel callback: checkout session converted to order', [
                        'session_id' => $checkoutSession->id,
                        'order_id' => $order->id,
                        'client_reference' => $clientReference,
                    ]);
                } elseif ($paymentStatus === 'failed') {
                    $failureMessage = match ($responseCode) {
                        '5007' => 'Payment was declined by the provider.',
                        '4010' => 'Transaction was cancelled by the customer.',
                        default => 'Payment failed. Please try again or use a different payment method.',
                    };
                    $checkoutSession->update([
                        'status' => 'failed',
                        'failure_reason' => $failureMessage,
                    ]);

                    Log::info('Hubtel callback: checkout session payment failed', [
                        'session_id' => $checkoutSession->id,
                        'client_reference' => $clientReference,
                        'response_code' => $responseCode,
                    ]);
                }

                return;
            }

            // ── Legacy flow: Find Payment by order_number ──
            $payment = Payment::whereHas('order', function ($query) use ($clientReference) {
                $query->where('order_number', $clientReference);
            })->first();

            if (! $payment) {
                Log::error('Payment not found for clientReference', ['clientReference' => $clientReference]);
                throw new \Exception('Payment not found');
            }

            // Prepare update data
            $updateData = [
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'payment_gateway_response' => $payload,
                'paid_at' => $paymentStatus === 'completed' ? now() : $payment->paid_at,
            ];

            // Handle refund status
            if ($paymentStatus === 'refunded') {
                $updateData['refunded_at'] = now();
                if (! empty($data['RefundReason'])) {
                    $updateData['refund_reason'] = $data['RefundReason'];
                }
            }

            // Update Payment record
            $payment->update($updateData);

            Log::info('Hubtel callback received (legacy)', [
                'response_code' => $responseCode,
                'status' => $status,
                'checkout_id' => $checkoutId,
                'client_reference' => $clientReference,
                'payment_status' => $paymentStatus,
            ]);

            // When payment completes, broadcast so all listeners update live.
            if ($paymentStatus === 'completed') {
                $order = $payment->order;
                if ($order) {
                    $order->load(['branch', 'customer.user', 'items.menuItem', 'payments']);
                    \App\Events\OrderBroadcastEvent::dispatch($order, 'updated');
                }
            }
        } catch (\Exception $e) {
            Log::error('Hubtel callback processing failed', [
                'error' => $e->getMessage(),
                'payload' => $this->sanitizeForLogging($payload),
            ]);
            throw $e;
        }
    }

    /**
     * Detect the Hubtel MoMo channel from a Ghana phone number prefix.
     *
     * @param  string  $phone  Phone number in local (0XXXXXXXXX) or international (233XXXXXXXXX) format
     * @return string Hubtel channel name: mtn-gh, vodafone-gh, or tigo-gh
     *
     * @throws \InvalidArgumentException When the prefix cannot be mapped
     */
    public function detectMomoChannel(string $phone): string
    {
        // Normalise to local format starting with 0
        $normalised = $phone;
        if (str_starts_with($phone, '233') && strlen($phone) === 12) {
            $normalised = '0'.substr($phone, 3);
        } elseif (str_starts_with($phone, '+233') && strlen($phone) === 13) {
            $normalised = '0'.substr($phone, 4);
        }

        $prefix = substr($normalised, 0, 3);

        return match ($prefix) {
            '024', '054', '055', '059', '025', '053', '026', '056' => 'mtn-gh',
            '020', '050' => 'vodafone-gh',
            '027', '057', '067' => 'tigo-gh',
            default => throw new \InvalidArgumentException(
                "Cannot determine mobile network for number prefix '{$prefix}'. ".
                'Please ensure the customer number is a valid Ghana mobile money number.'
            ),
        };
    }

    /**
     * Validate that Hubtel RMP credentials are configured
     *
     * @throws RuntimeException
     */
    protected function validateRmpConfiguration(): void
    {
        if (empty($this->rmpClientId) || empty($this->rmpClientSecret) || empty($this->merchantAccountNumber)) {
            throw new RuntimeException(
                'Hubtel Direct Receive Money is not properly configured. '.
                'Please set HUBTEL_RMP_CLIENT_ID, HUBTEL_RMP_CLIENT_SECRET, and HUBTEL_MERCHANT_ACCOUNT_NUMBER.'
            );
        }
    }

    /**
     * Build Basic Auth header for the RMP API
     */
    protected function getRmpAuthHeader(): string
    {
        return 'Basic '.base64_encode($this->rmpClientId.':'.$this->rmpClientSecret);
    }

    /**
     * Initiate a Direct Receive Money request via Hubtel RMP.
     * Used for POS mobile money payments — sends a USSD prompt to the customer's phone.
     *
     * @param  array{order: \App\Models\Order, customer_phone: string, customer_name?: string, description: string}  $data
     * @return array Normalised response with transaction_id and status
     *
     * @throws \Exception When initiation fails
     */
    public function initializeReceiveMoney(array $data): array
    {
        $this->validateRmpConfiguration();

        $order = $data['order'];
        $phone = $data['customer_phone'];

        // Detect the correct Hubtel channel from phone prefix
        $channel = $this->detectMomoChannel($phone);

        // Normalise phone to international format (233XXXXXXXXX)
        $msisdn = $phone;
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $msisdn = '233'.substr($phone, 1);
        }

        $payload = [
            'CustomerName' => $data['customer_name'] ?? $order->contact_name,
            'CustomerMsisdn' => $msisdn,
            'Channel' => $channel,
            // Goods only — delivery fee is collected by the rider, not via Hubtel.
            'Amount' => $this->chargeable($order),
            'PrimaryCallbackUrl' => route('payments.hubtel.rmp.callback'),
            'Description' => $data['description'],
            'ClientReference' => $order->order_number,
        ];

        Log::info('Hubtel RMP receive money initiated', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount' => $this->chargeable($order),
            'channel' => $channel,
            'client_reference' => $payload['ClientReference'],
        ]);

        $url = "{$this->rmpBaseUrl}/merchantaccount/merchants/{$this->merchantAccountNumber}/receive/mobilemoney";

        $response = $this->executeWithRetry(fn () => Http::withHeaders([
            'Authorization' => $this->getRmpAuthHeader(),
        ])->post($url, $payload));

        if (! $response->successful()) {
            $responseCode = $response->json('ResponseCode', 'unknown');
            $message = $this->mapRmpResponseCodeToMessage($responseCode);

            Log::error('Hubtel RMP initiation failed', [
                'order_id' => $order->id,
                'response_code' => $responseCode,
                'status_code' => $response->status(),
            ]);

            throw new \Exception($message);
        }

        $responseData = $response->json('Data', []);
        $responseCode = $response->json('ResponseCode', '');
        $message = $response->json('Message', '');

        // Do NOT create a Payment record here. The checkout session tracks Hubtel
        // metadata (transaction_id, gateway_response). The real Payment is created
        // by OrderCreationService::createFromCheckoutSession() after Hubtel confirms.

        Log::info('Hubtel RMP payment pending', [
            'order_id' => $order->id ?? null,
            'order_number' => $order->order_number,
            'transaction_id' => $responseData['TransactionId'] ?? null,
            'channel' => $channel,
            'message' => $message,
        ]);

        return [
            'transactionId' => $responseData['TransactionId'] ?? null,
            'channel' => $channel,
            'message' => $message,
            'responseCode' => $responseCode,
        ];
    }

    /**
     * Verify whether a Ghana mobile money number is registered and retrieve the account name.
     *
     * Uses the Hubtel Verification API (rnv.hubtel.com).
     *
     * @param  string  $phone  Phone number in local (0XXXXXXXXX) or international (+?233XXXXXXXXX) format
     * @return array{isRegistered: bool, name: string|null, status: string|null, profile: string|null}
     */
    public function verifyMomoNumber(string $phone): array
    {
        $channel = $this->detectMomoChannel($phone);

        // Normalise to local format (0XXXXXXXXX) for the verification API
        $localPhone = $phone;
        if (str_starts_with($phone, '+233') && strlen($phone) === 13) {
            $localPhone = '0'.substr($phone, 4);
        } elseif (str_starts_with($phone, '233') && strlen($phone) === 12) {
            $localPhone = '0'.substr($phone, 3);
        }

        $rnvBaseUrl = config('services.hubtel.rnv_base_url', 'https://rnv.hubtel.com');
        $url = "{$rnvBaseUrl}/v2/merchantaccount/merchants/{$this->merchantAccountNumber}/mobilemoney/verify";

        Log::info('Hubtel MoMo verification initiated', [
            'phone' => substr($localPhone, 0, 3).'****'.substr($localPhone, -2),
            'channel' => $channel,
        ]);

        $response = Http::withHeaders([
            'Authorization' => $this->getRmpAuthHeader(),
        ])->get($url, [
            'channel' => $channel,
            'customerMsisdn' => $localPhone,
        ]);

        if (! $response->successful() || $response->json('responseCode') !== '0000') {
            Log::warning('Hubtel MoMo verification failed or not registered', [
                'phone' => substr($localPhone, 0, 3).'****'.substr($localPhone, -2),
                'status_code' => $response->status(),
                'response_code' => $response->json('responseCode'),
            ]);

            return [
                'isRegistered' => false,
                'name' => null,
                'status' => null,
                'profile' => null,
            ];
        }

        $data = $response->json('data', []);

        Log::info('Hubtel MoMo verification successful', [
            'phone' => substr($localPhone, 0, 3).'****'.substr($localPhone, -2),
            'channel' => $channel,
            'is_registered' => $data['isRegistered'] ?? false,
        ]);

        return [
            'isRegistered' => $data['isRegistered'] ?? false,
            'name' => $data['name'] ?? null,
            'status' => $data['status'] ?? null,
            'profile' => $data['profile'] ?? null,
        ];
    }

    /**
     * Handle a Direct Receive Money callback from Hubtel RMP.
     *
     * @param  array  $payload  The callback payload from Hubtel
     *
     * @throws \Exception When callback processing fails
     */
    public function handleRmpCallback(array $payload): void
    {
        try {
            $responseCode = $payload['ResponseCode'] ?? null;
            $data = $payload['Data'] ?? [];

            if (empty($responseCode) || empty($data)) {
                Log::error('Hubtel RMP callback missing required fields', [
                    'payload' => $this->sanitizeForLogging($payload),
                ]);
                throw new \Exception('Invalid RMP callback payload');
            }

            $clientReference = $data['ClientReference'] ?? null;
            $transactionId = $data['TransactionId'] ?? null;
            $amount = $data['Amount'] ?? null;

            if (empty($clientReference)) {
                Log::error('Hubtel RMP callback missing ClientReference', [
                    'payload' => $this->sanitizeForLogging($payload),
                ]);
                throw new \Exception('Missing ClientReference in RMP callback');
            }

            // RMP success = "0000", failure = "2001" and others
            $paymentStatus = $responseCode === '0000' ? 'completed' : 'failed';

            // ── New flow: Try CheckoutSession first ──
            // Look up by exact session_token, transactionId, or by prefix match
            // (handles legacy truncated clientReference where UUID was cut to 32 chars)
            $checkoutSession = \App\Models\CheckoutSession::where('session_token', $clientReference)
                ->orWhere('hubtel_transaction_id', $transactionId)
                ->when(strlen($clientReference) < 36, function ($q) use ($clientReference) {
                    $q->orWhere('session_token', 'like', $clientReference.'%');
                })
                ->first();

            if ($checkoutSession) {
                $checkoutSession->update([
                    'payment_gateway_response' => array_merge(
                        $checkoutSession->payment_gateway_response ?? [],
                        ['rmp_callback' => $payload]
                    ),
                ]);

                // Guard: do not convert expired/abandoned sessions
                if (in_array($checkoutSession->status, ['expired', 'abandoned'])) {
                    Log::warning('Hubtel RMP callback: ignoring completed payment for expired/abandoned session', [
                        'session_id' => $checkoutSession->id,
                        'session_status' => $checkoutSession->status,
                        'client_reference' => $clientReference,
                    ]);

                    return;
                }

                if ($paymentStatus === 'completed' && ! $checkoutSession->order_id) {
                    $order = $checkoutSession->convertToOrder();
                    \App\Events\OrderBroadcastEvent::dispatch($order, 'created');

                    Log::info('Hubtel RMP callback: checkout session converted to order', [
                        'session_id' => $checkoutSession->id,
                        'order_id' => $order->id,
                        'client_reference' => $clientReference,
                    ]);
                } elseif ($paymentStatus === 'failed') {
                    $checkoutSession->update([
                        'status' => 'failed',
                        'failure_reason' => $this->mapRmpResponseCodeToMessage($responseCode),
                    ]);

                    Log::info('Hubtel RMP callback: checkout session payment failed', [
                        'session_id' => $checkoutSession->id,
                        'client_reference' => $clientReference,
                        'response_code' => $responseCode,
                    ]);
                }

                return;
            }

            // ── Legacy flow: Find payment by order_number or transaction ID ──
            $payment = Payment::whereHas('order', function ($query) use ($clientReference) {
                $query->where('order_number', $clientReference);
            })->first();

            if (! $payment && $transactionId) {
                $payment = Payment::where('transaction_id', $transactionId)->first();
            }

            if (! $payment) {
                Log::error('Hubtel RMP callback: payment not found', [
                    'client_reference' => $clientReference,
                    'transaction_id' => $transactionId,
                ]);
                throw new \Exception('Payment not found for RMP callback');
            }

            $updateData = [
                'payment_status' => $paymentStatus,
                'payment_gateway_response' => array_merge(
                    $payment->payment_gateway_response ?? [],
                    ['rmp_callback' => $payload]
                ),
                'paid_at' => $paymentStatus === 'completed' ? now() : $payment->paid_at,
            ];

            if ($transactionId && ! $payment->transaction_id) {
                $updateData['transaction_id'] = $transactionId;
            }

            $payment->update($updateData);

            Log::info('Hubtel RMP callback processed (legacy)', [
                'response_code' => $responseCode,
                'client_reference' => $clientReference,
                'transaction_id' => $transactionId,
                'payment_status' => $paymentStatus,
                'amount' => $amount,
            ]);

            if ($paymentStatus === 'completed') {
                $order = $payment->order;
                if ($order) {
                    $order->load(['branch', 'customer.user', 'items.menuItem', 'payments']);
                    \App\Events\OrderBroadcastEvent::dispatch($order, 'updated');
                }
            }
        } catch (\Exception $e) {
            Log::error('Hubtel RMP callback processing failed', [
                'error' => $e->getMessage(),
                'payload' => $this->sanitizeForLogging($payload),
            ]);
            throw $e;
        }
    }

    /**
     * Map Hubtel RMP response codes to user-friendly messages
     */
    protected function mapRmpResponseCodeToMessage(string $responseCode): string
    {
        return match ($responseCode) {
            '0000' => 'Payment received successfully',
            '0001' => 'Payment pending — waiting for customer approval',
            '2001' => 'Payment failed. Customer may have insufficient funds, entered the wrong PIN, or the request timed out.',
            '4000' => 'Invalid payment data. Please check the phone number and try again.',
            '4070' => 'Payment amount issue. Please contact support.',
            '4101' => 'This account is not configured to receive mobile money. Contact support.',
            '4103' => 'Permission denied for this mobile network channel.',
            default => 'An unexpected error occurred. Please try again or contact support.',
        };
    }

    /**
     * Verify a transaction status via Hubtel Status Check API
     *
     * @param  string  $clientReference  The order number used as client reference
     * @return array Normalized transaction status
     *
     * @throws \Exception When verification fails
     */
    public function verifyTransaction(string $clientReference): array
    {
        $this->validateConfiguration();

        try {
            Log::info('Payment verification started', [
                'client_reference' => $clientReference,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Send GET request to Status Check API
            $url = "{$this->statusCheckUrl}/transactions/{$this->merchantAccountNumber}/status";

            $response = Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
            ])->get($url, [
                'clientReference' => $clientReference,
            ]);

            if (! $response->successful()) {
                Log::error('Hubtel status check failed', [
                    'client_reference' => $clientReference,
                    'endpoint' => $url,
                    'status_code' => $response->status(),
                    'response' => $response->json(),
                ]);
                throw new \Exception('Failed to verify transaction status');
            }

            $data = $response->json();

            // Extract transaction details
            $transactionId = $data['transactionId'] ?? null;
            $externalTransactionId = $data['externalTransactionId'] ?? null;
            $amount = $data['amount'] ?? null;
            $charges = $data['charges'] ?? null;
            $hubtelStatus = $data['status'] ?? null;

            if (empty($hubtelStatus)) {
                throw new \Exception('Invalid status check response');
            }

            // Find Payment record by clientReference
            $payment = Payment::whereHas('order', function ($query) use ($clientReference) {
                $query->where('order_number', $clientReference);
            })->first();

            if (! $payment) {
                Log::error('Payment not found for verification', ['clientReference' => $clientReference]);
                throw new \Exception('Payment not found');
            }

            // Map Hubtel status to payment_status
            $paymentStatus = $this->mapHubtelStatusToPaymentStatus($hubtelStatus, '0000');

            // Update Payment record if status changed
            if ($payment->payment_status !== $paymentStatus) {
                $updateData = [
                    'payment_status' => $paymentStatus,
                    'payment_gateway_response' => array_merge(
                        $payment->payment_gateway_response ?? [],
                        ['status_check' => $data]
                    ),
                    'paid_at' => $paymentStatus === 'completed' ? now() : $payment->paid_at,
                ];

                // Handle refund status
                if ($paymentStatus === 'refunded') {
                    $updateData['refunded_at'] = now();
                    // Extract refund reason if provided in status check response
                    if (! empty($data['refundReason'])) {
                        $updateData['refund_reason'] = $data['refundReason'];
                    }
                }

                $payment->update($updateData);

                Log::info('Payment status updated via verification', [
                    'client_reference' => $clientReference,
                    'old_status' => $payment->payment_status,
                    'new_status' => $paymentStatus,
                ]);
            }

            Log::info('Payment verification completed', [
                'client_reference' => $clientReference,
                'transaction_id' => $transactionId,
                'status' => $hubtelStatus,
                'payment_status' => $paymentStatus,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Return normalized transaction status
            return [
                'payment' => $payment->fresh(),
                'transactionId' => $transactionId,
                'externalTransactionId' => $externalTransactionId,
                'amount' => (float) $amount,
                'charges' => (float) $charges,
                'status' => $hubtelStatus,
                'paymentStatus' => $paymentStatus,
            ];
        } catch (\Exception $e) {
            Log::error('Payment verification failed', [
                'client_reference' => $clientReference,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
