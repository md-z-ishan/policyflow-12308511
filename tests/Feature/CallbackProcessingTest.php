<?php

namespace Tests\Feature;

use App\Enums\PolicyState;
use App\Enums\ProviderEventOutcome;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallbackProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function createPendingRequest(string $providerRef): PolicyRequest
    {
        return PolicyRequest::create([
            'uuid' => (string) Str::uuid(),
            'client_request_id' => 'CR-' . Str::random(8),
            'agent_id' => 'AGT-001',
            'customer_name' => 'Bob Test',
            'date_of_birth' => '1992-02-02',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium' => '60.00',
            'premium_breakdown' => ['ok' => true],
            'state' => PolicyState::PENDING_CONFIRMATION,
            'provider_reference' => $providerRef,
        ]);
    }

    public function test_duplicate_callback_is_applied_only_once(): void
    {
        $providerRef = 'PR-ASYNC-' . Str::random(8);
        $request = $this->createPendingRequest($providerRef);

        $payload = [
            'event_id' => 'evt_idempotent_test_001',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => 'POL-ASYNC-555',
            'occurred_at' => now()->toIso8601String(),
        ];

        // 1st delivery -> 200 Applied
        $res1 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res1->assertStatus(200)
            ->assertJsonPath('status', 'applied');

        $this->assertSame(PolicyState::ISSUED, $request->fresh()->state);
        $this->assertSame('POL-ASYNC-555', $request->fresh()->policy_number);

        // 2nd delivery (identical event_id) -> 200 Duplicate Ignored
        $res2 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res2->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_ignored');

        // 3rd delivery (identical event_id) -> 200 Duplicate Ignored
        $res3 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res3->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_ignored');

        // Verify only 1 provider_event record exists in DB
        $this->assertDatabaseCount('provider_events', 1);

        $event = ProviderEvent::where('provider_event_id', 'evt_idempotent_test_001')->first();
        $this->assertSame(ProviderEventOutcome::APPLIED, $event->outcome);

        // Verify state history only has exactly one ISSUED transition
        $issuedTransitions = $request->fresh()->stateHistory->where('to_state', PolicyState::ISSUED->value);
        $this->assertCount(1, $issuedTransitions);
    }

    public function test_callback_cannot_overwrite_issued_state(): void
    {
        $providerRef = 'PR-TERMINAL-' . Str::random(8);
        $request = $this->createPendingRequest($providerRef);
        $request->state = PolicyState::ISSUED;
        $request->policy_number = 'POL-SECURE-111';
        $request->save();

        // Late arriving FAILED callback
        $failedPayload = [
            'event_id' => 'evt_late_failure_999',
            'event_type' => 'POLICY_FAILED',
            'provider_reference' => $providerRef,
            'occurred_at' => now()->toIso8601String(),
        ];

        $res = $this->postJson('/api/mock-insurer/callback', $failedPayload);
        $res->assertStatus(200)
            ->assertJsonPath('status', 'terminal_state_preserved');

        // Policy state must remain ISSUED
        $fresh = $request->fresh();
        $this->assertSame(PolicyState::ISSUED, $fresh->state);
        $this->assertSame('POL-SECURE-111', $fresh->policy_number);

        // Provider event logged with REJECTED_INVALID_TRANSITION
        $event = ProviderEvent::where('provider_event_id', 'evt_late_failure_999')->first();
        $this->assertSame(ProviderEventOutcome::REJECTED_INVALID_TRANSITION, $event->outcome);
    }
}
