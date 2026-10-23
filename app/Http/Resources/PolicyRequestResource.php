<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PolicyRequestResource extends JsonResource
{
    private array $customMeta = [];

    public function withMeta(array $meta): self
    {
        $this->customMeta = array_merge($this->customMeta, $meta);
        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->uuid,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'state' => $this->state?->value ?? $this->state,
            'premium' => (string) $this->premium,
            'travel_days' => $this->travel_days,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => $this->attempt_count,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        // Include audit trail and attempts if explicitly loaded or for show details
        if ($this->relationLoaded('stateHistory')) {
            $data['audit_trail'] = $this->stateHistory->map(fn($h) => [
                'id' => $h->id,
                'from_state' => $h->from_state,
                'to_state' => $h->to_state,
                'reason' => $h->reason,
                'meta' => $h->meta,
                'created_at' => $h->created_at?->toISOString(),
            ])->values();
        }

        if ($this->relationLoaded('attempts')) {
            $data['attempts'] = $this->attempts->map(fn($a) => [
                'attempt_no' => $a->attempt_no,
                'idempotency_key' => $a->idempotency_key,
                'outcome' => $a->outcome?->value ?? $a->outcome,
                'http_status' => $a->http_status,
                'response_body' => $a->response_body,
                'error_message' => $a->error_message,
                'started_at' => $a->started_at?->toISOString(),
                'finished_at' => $a->finished_at?->toISOString(),
            ])->values();
        }

        return $data;
    }

    public function with(Request $request): array
    {
        return [
            'meta' => array_merge([
                'idempotent_replay' => false,
            ], $this->customMeta),
        ];
    }
}
