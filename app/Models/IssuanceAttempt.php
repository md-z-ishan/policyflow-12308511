<?php

namespace App\Models;

use App\Enums\AttemptOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_no',
        'idempotency_key',
        'outcome',
        'http_status',
        'response_body',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'attempt_no' => 'integer',
        'http_status' => 'integer',
        'outcome' => AttemptOutcome::class,
        'response_body' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
