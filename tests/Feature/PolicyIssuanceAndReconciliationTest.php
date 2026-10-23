<?php

namespace Tests\Feature;

use App\Enums\PolicyState;
use App\Models\MockPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PolicyIssuanceAndReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedRequest(array $overrides = []): PolicyRequest
    {
        $payload = array_merge([
            'client_request_id' => 'REQ-' . uniqid(),
            'agent_id' => 'AGT-01',
            'customer_name' => 'Alice Test',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ], $overrides);

        $res = $this->postJson('/api/policy-requests', $payload);
        $res->assertStatus(201);

        return PolicyRequest::where('uuid', $res->json('data.id'))->firstOrFail();
    }

    public function test_successful_provider_response_produces_exactly_one_policy_number(): void
    {
        Http::fake([
            '*/api/mock-insurer/submit' => Http::response([
                'provider_reference' => 'PR-SUCCESS-123',
                'policy_number' => 'POL-UNIQUE-999',
                'status' => 'ISSUED',
            ], 200),
        ]);

        $request = $this->createValidatedRequest();

        // First submit -> 200 ISSUED
        $res1 = $this->postJson("/api/policy-requests/{$request->uuid}/submit");
        $res1->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'POL-UNIQUE-999')
            ->assertJsonPath('data.attempt_count', 1);

        // Second submit -> idempotent 200 ISSUED, no additional attempt created
        $res2 = $this->postJson("/api/policy-requests/{$request->uuid}/submit");
        $res2->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1);

        $this->assertDatabaseCount('issuance_attempts', 1);
        $this->assertDatabaseHas('policy_requests', [
            'uuid' => $request->uuid,
            'policy_number' => 'POL-UNIQUE-999',
            'state' => PolicyState::ISSUED->value,
        ]);
    }

    public function test_repeated_submit_does_not_call_provider_again(): void
    {
        Http::fake([
            '*/api/mock-insurer/submit' => Http::response([
                'provider_reference' => 'PR-REPEAT-100',
                'policy_number' => 'POL-REPEAT-100',
                'status' => 'ISSUED',
            ], 200),
        ]);

        $request = $this->createValidatedRequest();

        // 1st submission
        $this->postJson("/api/policy-requests/{$request->uuid}/submit")->assertStatus(200);

        // 2nd repeated submission
        $this->postJson("/api/policy-requests/{$request->uuid}/submit")->assertStatus(200);

        // Assert external HTTP call was executed EXACTLY once
        Http::assertSentCount(1);
    }

    public function test_timeout_after_success_goes_to_needs_reconciliation_and_does_not_reissue(): void
    {
        $request = $this->createValidatedRequest();

        // Simulate timeout on external call
        Http::fake([
            '*/api/mock-insurer/submit' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Simulated cURL error 28: Operation timed out');
            },
            '*/api/mock-insurer/status/*' => Http::response([
                'found' => true,
                'provider_reference' => 'PR-RECON-777',
                'policy_number' => 'POL-RECON-777',
                'status' => 'ISSUED',
            ], 200),
        ]);

        // 1. Submit times out
        $response = $this->postJson("/api/policy-requests/{$request->uuid}/submit");
        $response->assertStatus(200)
            ->assertJsonPath('data.state', 'NEEDS_RECONCILIATION');

        $this->assertSame(PolicyState::NEEDS_RECONCILIATION, $request->fresh()->state);

        // 2. Reconcile discovers policy was indeed issued by the provider
        $this->artisan('policyflow:reconcile')->assertSuccessful();

        $fresh = $request->fresh();
        $this->assertSame(PolicyState::ISSUED, $fresh->state);
        $this->assertSame('POL-RECON-777', $fresh->policy_number);
        $this->assertSame('PR-RECON-777', $fresh->provider_reference);
    }

    public function test_temporary_failure_then_retry_keeps_original_premium_and_creates_one_policy(): void
    {
        $request = $this->createValidatedRequest();
        $originalPremium = (string) $request->premium;

        Http::fake([
            '*/api/mock-insurer/submit' => Http::sequence()
                // 1st attempt: 503 gateway failure
                ->push(['retriable' => true, 'message' => 'Gateway temporary overload'], 503)
                // 2nd attempt: 200 success
                ->push(['provider_reference' => 'PR-RETRY-888', 'policy_number' => 'POL-RETRY-888', 'status' => 'ISSUED'], 200),
        ]);

        // Attempt 1 -> 503 returns to VALIDATED
        $res1 = $this->postJson("/api/policy-requests/{$request->uuid}/submit");
        $res1->assertStatus(200)
            ->assertJsonPath('data.state', 'VALIDATED')
            ->assertJsonPath('data.attempt_count', 1);

        $this->assertSame(PolicyState::VALIDATED, $request->fresh()->state);
        $this->assertSame($originalPremium, (string) $request->fresh()->premium);

        // Attempt 2 -> successfully issued
        $res2 = $this->postJson("/api/policy-requests/{$request->uuid}/submit");
        $res2->assertStatus(200)
            ->assertJsonPath('data.state', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'POL-RETRY-888')
            ->assertJsonPath('data.attempt_count', 2);

        $this->assertSame(PolicyState::ISSUED, $request->fresh()->state);
        $this->assertSame($originalPremium, (string) $request->fresh()->premium);
    }

    public function test_policy_number_is_unique_in_database(): void
    {
        $req1 = $this->createValidatedRequest();
        $req1->policy_number = 'POL-SHARED-123';
        $req1->save();

        $req2 = $this->createValidatedRequest();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $req2->policy_number = 'POL-SHARED-123';
        $req2->save();
    }
}
