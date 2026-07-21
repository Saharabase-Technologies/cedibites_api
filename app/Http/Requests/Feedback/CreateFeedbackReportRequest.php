<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit contract. Accepts plain JSON (file-less) or multipart. In multipart the
 * whole JSON body travels in a single `payload` field; files ride alongside as
 * `audio`, `screenshots[]`, `replay`.
 *
 * Validation is SHAPE-ONLY. The service trims and coerces (I2) — the only hard
 * rejects here are file size / count caps.
 */
class CreateFeedbackReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may submit; the route is behind auth:sanctum.
        return $this->user() !== null;
    }

    /** Unwrap the multipart `payload` envelope into the request body. */
    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $this->merge($decoded);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Content — shape only; the service coerces defaults.
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['nullable', 'string', 'max:32'],
            'route' => ['nullable', 'string', 'max:255'],
            'role_at_report' => ['nullable', 'string', 'max:255'],
            // Diagnostic context — NEVER 'exists'; coerced valid-or-null (C1/I2).
            'branch_id' => ['nullable'],
            'replay_id' => ['nullable', 'string', 'max:255'],

            // Silent-capture arrays — shape only, trimmed to caps in the service.
            'breadcrumbs' => ['nullable', 'array'],
            'console_entries' => ['nullable', 'array'],
            'network_entries' => ['nullable', 'array'],
            'request_ids' => ['nullable', 'array'],
            'screenshot_meta' => ['nullable', 'array'],
            'client_meta' => ['nullable', 'array'],

            // Files — the ONLY hard rejects (size + count caps).
            'audio' => ['nullable', 'file', 'max:10240'],       // 10 MB
            'screenshots' => ['nullable', 'array', 'max:5'],    // ≤ 5 shots
            'screenshots.*' => ['file', 'max:5120'],            // 5 MB each
            'replay' => ['nullable', 'file', 'max:8192'],       // 8 MB
        ];
    }
}
