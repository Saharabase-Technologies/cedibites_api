<?php

namespace App\Http\Resources\Feedback;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A correlated backend log line for the triage detail view. */
class RequestLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'method' => $this->method,
            'path' => $this->path,
            'status_code' => $this->status_code,
            'duration_ms' => $this->duration_ms,
            'level' => $this->level,
            'message' => $this->message,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
