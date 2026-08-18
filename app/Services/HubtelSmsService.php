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
                // What Hubtel actually charged, and in how many billed segments.
                // Absent on some responses, hence the nulls — a campaign records
                // an unknown cost as unknown rather than as free.
                'rate' => $data['rate'] ?? null,
                'units' => $data['units'] ?? null,
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
                'rate' => $data['rate'] ?? null,
                'units' => $data['units'] ?? null,
            ];
        }

        /*
         * The shape `batch/simple/send` actually answers with.
         *
         * Not `messageIds` — that is what the branch above expects and it is
         * what the (now-retired) documentation described. Captured live from
         * the beta account on 2026-08-07:
         *
         *   HTTP 201
         *   {"batchId":"2d417523-…","status":0,
         *    "data":[{"recipient":"233…","content":"…","messageId":"9f6a82a9-…"}]}
         *
         * Until this branch existed, every accepted batch fell through to the
         * throw below and was recorded as a failure for every recipient — a
         * campaign that reached everybody reporting that it reached nobody. The
         * exact mirror of the false-pass bug the body-status check fixed, and
         * the more confusing of the two: the messages arrive, and the console
         * says they did not.
         *
         * `data` must be an array for this to be an acceptance. A rejected batch
         * carries a batchId with no data and falls through to statusDescription,
         * and an empty data array leaves messageIds empty, which sendBatch()
         * already treats as a rejection.
         */
        if (isset($data['batchId']) && is_array($data['data'] ?? null)) {
            return [
                'messageIds' => array_values(array_filter(
                    array_column($data['data'], 'messageId')
                )),
                'batchId' => $data['batchId'],
                'status' => $data['status'] ?? null,
                'responseCode' => $data['responseCode'] ?? $data['status'] ?? null,
                // Not present on this endpoint today. Read anyway, at both
                // levels, so the day Hubtel starts returning it the actual cost
                // starts being measured without a code change.
                'rate' => $data['rate'] ?? $data['data'][0]['rate'] ?? null,
                'units' => $data['units'] ?? $data['data'][0]['units'] ?? null,
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
    private function record(?string $to, bool $succeeded, ?string $error = null, ?string $messageId = null, ?string $notification = null, bool $isCampaign = false, ?int $campaignId = null): void
    {
        try {
            SmsDeliveryAttempt::create([
                'notification' => $notification,
                'is_campaign' => $isCampaign,
                'campaign_id' => $campaignId,
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
     * @param  string|null  $notification  Notification class recorded against the attempts
     * @param  bool  $isCampaign  Marketing traffic, kept out of the health signal — see SmsDeliveryAttempt::scopeTransactional()
     * @param  int|null  $campaignId  Stamped on each attempt so the delivery-status poll can find them again
     * @return array Array with messageIds, status, responseCode, rate and units
     *
     * @throws \RuntimeException When configuration is invalid
     * @throws \InvalidArgumentException When any phone number format is invalid
     * @throws \Exception When API request fails
     */
    public function sendBatch(array $recipients, string $message, ?string $notification = null, bool $isCampaign = false, ?int $campaignId = null): array
    {
        // An empty batch is always a caller bug. Hubtel answers it with a
        // success-shaped body carrying a failure status, which is exactly the
        // response this method is least able to interpret — so refuse it here
        // rather than spend a request finding out.
        if ($recipients === []) {
            throw new \InvalidArgumentException('Cannot send a batch SMS with no recipients');
        }

        try {
            // Validate configuration
            $this->validateConfiguration();

            // Validate all phone numbers in recipients array
            foreach ($recipients as $phone) {
                $this->validatePhoneNumber($phone);
            }
        } catch (\Throwable $e) {
            $this->recordBatch($recipients, false, $e->getMessage(), $notification, $isCampaign, $campaignId);

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
                $this->recordBatch($recipients, false, $body['statusDescription'] ?? $response->body(), $notification, $isCampaign, $campaignId);

                throw new \Exception('Failed to send batch SMS: '.$response->body());
            }

            // Parse and return response with messageIds array
            $result = $this->parseResponse($response);

            // Hubtel reports business-level rejections — an unfunded account, a
            // blocked sender — in the body while still answering 2xx on the
            // wire. sendSingle() checks the body for exactly this; without the
            // same check here every recipient of a rejected batch is recorded
            // as delivered, and a campaign that reached nobody reports a
            // flawless delivery rate. "Payment required on account" — the
            // rejection that took SMS down for three weeks in July — arrives
            // this way.
            $messageIds = $result['messageIds'] ?? [];
            $status = (int) ($result['status'] ?? 0);

            if ($status >= 100 || $messageIds === []) {
                $body = $response->json() ?? [];
                $errorMessage = $body['statusDescription'] ?? "Batch rejected by provider (status {$status})";

                \Illuminate\Support\Facades\Log::error('Hubtel batch SMS rejected in response body', [
                    'endpoint' => "{$this->baseUrl}/batch/simple/send",
                    'status_code' => $response->status(),
                    'recipient_count' => count($recipients),
                    'response' => $this->sanitizeForLogging($body),
                ]);

                $this->recordBatch($recipients, false, $errorMessage, $notification, $isCampaign, $campaignId);

                throw new \Exception('Failed to send batch SMS: '.$errorMessage);
            }

            $this->recordBatch($recipients, true, null, $notification, $isCampaign, $campaignId);

            // Log success with recipient count and sanitized data
            \Illuminate\Support\Facades\Log::info('Batch SMS sent successfully', [
                'messageIds_count' => count($messageIds),
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

            $this->recordBatch($recipients, false, 'Failed to connect to Hubtel SMS API', $notification, $isCampaign, $campaignId);

            throw new \Exception('Failed to connect to Hubtel SMS API');
        } catch (\Throwable $e) {
            // parseResponse rejects a malformed or error-shaped body from here,
            // and previously escaped unrecorded — leaving a failed batch absent
            // from the health signal entirely, counted as neither sent nor
            // failed. The branches above have already recorded; anything else
            // reaching this point has not.
            if (! str_starts_with($e->getMessage(), 'Failed to send batch SMS: ')) {
                // Log the body verbatim. When Hubtel answers in a shape we do
                // not recognise, the recorded failure says only "Missing
                // messageId or messageIds" — which is what we expected, not what
                // arrived. Without the body there is nothing to diagnose from,
                // and the batch response shape had to be rediscovered by probing
                // the live endpoint. Never let that happen twice.
                \Illuminate\Support\Facades\Log::error('Hubtel batch SMS response could not be parsed', [
                    'endpoint' => "{$this->baseUrl}/batch/simple/send",
                    'status_code' => isset($response) ? $response->status() : null,
                    'recipient_count' => count($recipients),
                    'error' => $e->getMessage(),
                    'response' => isset($response)
                        ? $this->sanitizeForLogging($response->json() ?? ['raw' => $response->body()])
                        : null,
                ]);

                $this->recordBatch($recipients, false, $e->getMessage(), $notification, $isCampaign, $campaignId);
            }

            throw $e;
        }
    }

    /**
     * What actually happened to a batch, and what it cost.
     *
     * `GET /v1/messages/batch/{batchId}` — the only endpoint on this account that
     * returns a real `rate`. The send response carries none, and the per-message
     * lookup 404s because the message ids a send returns are not the ones the
     * query accepts. Both verified against the live account 2026-08-07.
     *
     * Returns one entry per message:
     *   ['messageId' => …, 'status' => 'Delivered', 'rate' => 0.0243, 'to' => '233…']
     *
     * Read-only and free. Never throws — a failed poll should leave the previous
     * figures alone, not replace a known cost with a wrong one.
     *
     * @return array<int, array<string, mixed>>|null Null when the batch cannot be read
     */
    public function batchStatus(string $batchId): ?array
    {
        try {
            $this->validateConfiguration();

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
            ])->get("{$this->baseUrl}/batch/{$batchId}");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json()['data'] ?? null;

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not read Hubtel batch status', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * One attempt row per recipient, so a batch of 50 counts as 50 in the
     * failure rate rather than as a single event.
     *
     * @param  array<int, string>  $recipients
     */
    private function recordBatch(array $recipients, bool $succeeded, ?string $error = null, ?string $notification = null, bool $isCampaign = false, ?int $campaignId = null): void
    {
        foreach ($recipients as $phone) {
            $this->record((string) $phone, $succeeded, $error, null, $notification, $isCampaign, $campaignId);
        }
    }
}
