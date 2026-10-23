<?php

namespace App\Models;

use App\Enums\ProviderEventOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    protected $fillable = [
        'policy_request_id',
        'provider_event_id',
        'provider_reference',
        'event_type',
        'payload',
        'processed_at',
        'outcome',
    ];

    protected $casts = [
        'payload' => 'array',
        'outcome' => ProviderEventOutcome::class,
        'processed_at' => 'datetime',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
