<?php

namespace Tests\Unit;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine();
    }

    private function createSampleRequest(PolicyState $state = PolicyState::RECEIVED): PolicyRequest
    {
        return PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'CR-' . Str::random(8),
            'agent_id' => 'AGT-' . Str::random(8),
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => Destination::DOMESTIC,
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => PlanCode::BASIC,
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium' => '60.00',
            'premium_breakdown' => ['sample' => true],
            'state' => $state,
        ]);
    }

    public function test_valid_forward_transitions_and_history_logging(): void
    {
        $request = $this->createSampleRequest(PolicyState::RECEIVED);

        DB::transaction(function () use ($request) {
            $this->stateMachine->transition($request, PolicyState::VALIDATED, 'Validation passed');
        });

        $this->assertSame(PolicyState::VALIDATED, $request->fresh()->state);
        $this->assertDatabaseHas('policy_state_history', [
            'policy_request_id' => $request->id,
            'from_state' => PolicyState::RECEIVED->value,
            'to_state' => PolicyState::VALIDATED->value,
            'reason' => 'Validation passed',
        ]);

        DB::transaction(function () use ($request) {
            $this->stateMachine->transition($request, PolicyState::SUBMITTED, 'Submitting to provider');
        });

        $this->assertSame(PolicyState::SUBMITTED, $request->fresh()->state);

        DB::transaction(function () use ($request) {
            $this->stateMachine->transition($request, PolicyState::ISSUED, 'Provider confirmed');
        });

        $this->assertSame(PolicyState::ISSUED, $request->fresh()->state);
        $this->assertCount(3, $request->fresh()->stateHistory);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $request = $this->createSampleRequest(PolicyState::ISSUED);

        $this->expectException(InvalidStateTransitionException::class);

        DB::transaction(function () use ($request) {
            // Attempting invalid regression: ISSUED -> SUBMITTED
            $this->stateMachine->transition($request, PolicyState::SUBMITTED, 'Illegal regression');
        });
    }

    public function test_terminal_state_cannot_be_overwritten(): void
    {
        $request = $this->createSampleRequest(PolicyState::FAILED);

        $this->expectException(InvalidStateTransitionException::class);

        DB::transaction(function () use ($request) {
            $this->stateMachine->transition($request, PolicyState::VALIDATED, 'Illegal attempt to revive failed');
        });
    }
}
