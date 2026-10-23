<?php

namespace App\Models;

use App\Enums\Destination;
use App\Enums\MockScenario;
use App\Enums\PlanCode;
use App\Enums\PolicyState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'premium',
        'premium_breakdown',
        'state',
        'provider_reference',
        'policy_number',
        'last_provider_response',
        'attempt_count',
        'mock_scenario',
        'last_error',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start' => 'date',
        'trip_end' => 'date',
        'state' => PolicyState::class,
        'plan_code' => PlanCode::class,
        'destination' => Destination::class,
        'mock_scenario' => MockScenario::class,
        'sum_insured' => 'integer',
        'travel_days' => 'integer',
        'attempt_count' => 'integer',
        'premium_breakdown' => 'array',
        'last_provider_response' => 'array',
    ];

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class, 'policy_request_id')->orderBy('id', 'asc');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class, 'policy_request_id')->orderBy('attempt_no', 'asc');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'policy_request_id')->orderBy('id', 'asc');
    }
}
