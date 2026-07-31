<?php

namespace App\Services;

use App\Enums\SmsFailureReason;
use App\Models\SmsDeliveryAttempt;

class HubtelSmsService
{
    protected ?string $clientId;

    protected ?string $clientSecret;

    protected ?string $senderId;

    protected string $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.hubtel.client_id');
        $this->clientSecret = config('services.hubtel.client_secret');
        $this->senderId = config('services.hubtel.sender_id', 'CediBites');
        $this->baseUrl = config('services.hubtel.sms_base_url', 'https://sms.hubtel.com/v1/messages');
    }

    /**
     * Validate that required configuration values are present.
     *
     * @throws \RuntimeException
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \RuntimeException('Hubtel SMS is not properly configured');
        }
    }

    /**
     * Build the Basic Authentication header value.
     */
    protected function getAuthHeader(): string
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");

        return "Basic {$credentials}";
    }

    /**
     * Validate that a phone number matches Ghana format (233XXXXXXXXX).
     *
     * @throws \InvalidArgumentException
     */
    protected function validatePhoneNumber(string $phone): void
    {
        if (strlen($phone) !== 12 || ! str_starts_with($phone, '233') || ! ctype_digit($phone)) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }
    }

    /**
     * Sanitize sensitive data for logging.
     * Masks phone numbers and removes clientSecret.
     */
    protected function sanitizeForLogging(array $data): array
    {
        return array_map(function ($value) {
            // Handle nested arrays recursively
            if (is_array($value)) {
                return $this->sanitizeForLogging($value);
            }

            // Handle phone numbers - mask middle digits
            if (is_string($value) && strlen($value) === 12 && str_starts_with($value, '233') && ctype_digit($value)) {
                return substr($value, 0, 3).'****'.substr($value, -2);
            }

            // Remove clientSecret
            if ($value === $this->clientSecret) {
                return '[REDACTED]';
            }

            return $value;
        }, array_filter($data, function ($key) {
            // Remove keys named 'clientSecret' or 'client_secret'
            return ! in_array(strtolower($key), ['clientsecret', 'client_secret']);
        }, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Parse Hubtel API response and extract required fields.
     *
     * @param  \Illuminate\Http\Client\Response  $response
     *
     * @throws \Exception
     */
    protected function parseResponse($response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new \Exception('Invalid API response format: Response is not an array');
        }

        // Check for single SMS response (messageId)
        if (isset($data['messageId'])) {
            // For successful responses, we expect status and responseCode
            // But if messageId is null, it might be an error response
            if ($data['messageId'] === null && isset($data['statusDescription'])) {
                throw new \Exception('SMS API Error: '.$data['statusDescription']);
            }

            return [
                'messageId' => $data['messageId'],
                'status' => $data['status'] ?? null,
                'responseCode' => $data['responseCode'] ?? $data['status'] ?? null,
            ];
        }

        // Check for batch SMS response (messageIds)
        if (isset($data['messageIds'])) {
            if (! is_array($data['messageIds'])) {
                throw new \Exception('Invalid API response format: messageIds is not an array');
            }

            return [
                'messageIds' => $data['messageIds'],
                'status' => $data['status'] ?? null,
                'responseCode' => $data['responseCode'] ?? $data['status'] ?? null,
            ];
        }

        // Check if it's an error response with statusDescription
        if (isset($data['statusDescription'])) {
            throw new \Exception('SMS API Error: '.$data['statusDescription']);
        }

        // Neither messageId nor messageIds found
        throw new \Exception('Invalid API response format: Missing messageId or messageIds');
    }

    /**
     * Record the outcome of one send attempt.
     *
     * Every path out of a send routes through here, so SmsHealthService can
     * measure a failure *rate* rather than just count failures. Recording must
     * never be the reason a message fails to go out, hence the blanket catch:
     * losing a health datapoint is survivable, losing the SMS is not.
     */
    private function record(?string $to, bool $succeeded, ?string $error = null, ?string $messageId = null, ?string $notification = null): void
    {
        try {
            SmsDeliveryAttempt::create([
                'notification' => $notification,
                'recipient' => $to,
                'succeeded' => $succeeded,
                'failure_reason' => $succeeded ? null : SmsFailureReason::classify($error)->value,
                'error_message' => $succeeded ? null : mb_substr((string) $error, 0, 500),
                'message_id' => $messageId,
            ]);
        } catch (\Throwable) {
            // Table missing (pre-migration) or DB hiccup — not worth failing a send over.
        }
    }

    /**
     * Send a single SMS message to one recipient.
     *
     * @param  string  $to  Recipient phone number in format 233XXXXXXXXX
     * @param  string  $message  SMS message content
     * @param  string|null  $notification  Notification class recorded against the attempt
     * @return array Array with messageId, status, and responseCode
     *
     * @throws \RuntimeException When configuration is invalid
     * @throws \InvalidArgumentException When phone number format is invalid
     * @throws \Exception When API request fails
     */
    public function sendSingle(string $to, string $message, ?string $notification = null): array
    {
        try {
            // Validate configuration
            $this->validateConfiguration();

            // Validate phone number
            $this->validatePhoneNumber($to);
        } catch (\Throwable $e) {
            $this->record($to, false, $e->getMessage(), null, $notification);

            throw $e;
        }

        // Build request payload
        $payload = [
            'From' => $this->senderId,
            'To' => $to,
            'Content' => $message,
        ];

        try {
            // POST to {baseUrl}/send with Basic Auth
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
            ])->post("{$this->baseUrl}/send", $payload);

            // Parse response first to check for API-level errors
            $result = $this->parseResponse($response);

            // Check if the response indicates an error (messageId is null or status indicates failure)
            if (empty($result['messageId']) || ($result['status'] ?? 0) >= 100) {
                $responseData = $response->json() ?? [];
                $errorMessage = $responseData['statusDescription'] ?? 'Unknown error';

                \Illuminate\Support\Facades\Log::error('Hubtel SMS API request failed', [
                    'endpoint' => "{$this->baseUrl}/send",
                    'status_code' => $response->status(),
                    'response' => $this->sanitizeForLogging($responseData),
                ]);

                $this->record($to, false, $errorMessage, null, $notification);

                throw new \Exception("Failed to send SMS: {$errorMessage}");
            }

            // Log success with sanitized data
            \Illuminate\Support\Facades\Log::info('SMS sent successfully', [
                'messageId' => $result['messageId'],
                'recipient_count' => 1,
                'to' => $this->sanitizeForLogging(['phone' => $to])['phone'],
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->record($to, true, null, (string) $result['messageId'], $notification);

            return $result;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Handle connection errors with logging
            \Illuminate\Support\Facades\Log::error('Failed to connect to Hubtel SMS API', [
                'endpoint' => "{$this->baseUrl}/send",
                'error' => $e->getMessage(),
            ]);

            $this->record($to, false, 'Failed to connect to Hubtel SMS API', null, $notification);

            throw new \Exception('Failed to connect to Hubtel SMS API');
        } catch (\Throwable $e) {
            // parseResponse rejects a malformed or error-shaped body from here.
            // The explicit-failure branch above already recorded; anything else
            // reaching this point has not been.
            if (! str_starts_with($e->getMessage(), 'Failed to send SMS: ')) {
                $this->record($to, false, $e->getMessage(), null, $notification);
            }

            throw $e;
        }
    }

    /**
     * Send the same SMS message to multiple recipients.
     *
     * @param  array  $recipients  Array of phone numbers in format 233XXXXXXXXX
     * @param  string  $message  SMS message content
     * @return array Array with messageIds, status, and responseCode
     *
     * @throws \RuntimeException When configuration is invalid
     * @throws \InvalidArgumentException When any phone number format is invalid
     * @throws \Exception When API request fails
     */
    public function sendBatch(array $recipients, string $message, ?string $notification = null): array
    {
        try {
            // Validate configuration
            $this->validateConfiguration();

            // Validate all phone numbers in recipients array
            foreach ($recipients as $phone) {
                $this->validatePhoneNumber($phone);
            }
        } catch (\Throwable $e) {
            $this->recordBatch($recipients, false, $e->getMessage(), $notification);

            throw $e;
        }

        // Build request payload
        $payload = [
            'From' => $this->senderId,
            'Recipients' => $recipients,
            'Content' => $message,
        ];

        try {
            // POST to {baseUrl}/batch/simple/send with Basic Auth
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
            ])->post("{$this->baseUrl}/batch/simple/send", $payload);

            // Handle non-successful responses
            if (! $response->successful()) {
                \Illuminate\Support\Facades\Log::error('Hubtel SMS API request failed', [
                    'endpoint' => "{$this->baseUrl}/batch/simple/send",
                    'status_code' => $response->status(),
                    'response' => $this->sanitizeForLogging($response->json() ?? []),
                ]);

                $body = $response->json() ?? [];
                $this->recordBatch($recipients, false, $body['statusDescription'] ?? $response->body(), $notification);

                throw new \Exception('Failed to send batch SMS: '.$response->body());
            }

            // Parse and return response with messageIds array
            $result = $this->parseResponse($response);

            $this->recordBatch($recipients, true, null, $notification);

            // Log success with recipient count and sanitized data
            \Illuminate\Support\Facades\Log::info('Batch SMS sent successfully', [
                'messageIds_count' => count($result['messageIds']),
                'recipient_count' => count($recipients),
                'recipients' => $this->sanitizeForLogging(['phones' => $recipients])['phones'],
                'timestamp' => now()->toIso8601String(),
            ]);

            return $result;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Handle connection errors with logging
            \Illuminate\Support\Facades\Log::error('Failed to connect to Hubtel SMS API', [
                'endpoint' => "{$this->baseUrl}/batch/simple/send",
                'error' => $e->getMessage(),
            ]);

            $this->recordBatch($recipients, false, 'Failed to connect to Hubtel SMS API', $notification);

            throw new \Exception('Failed to connect to Hubtel SMS API');
        }
    }

    /**
     * One attempt row per recipient, so a batch of 50 counts as 50 in the
     * failure rate rather than as a single event.
     *
     * @param  array<int, string>  $recipients
     */
    private function recordBatch(array $recipients, bool $succeeded, ?string $error = null, ?string $notification = null): void
    {
        foreach ($recipients as $phone) {
            $this->record((string) $phone, $succeeded, $error, null, $notification);
        }
    }
}
