<?php

namespace Tests\Feature;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    public function test_valid_request_returns_201_with_expected_premium_and_validated_state(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('data.agent_id', 'AGENT-17')
            ->assertJsonPath('data.state', 'VALIDATED')
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('data.travel_days', 5)
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->assertDatabaseCount('policy_requests', 1);

        $created = PolicyRequest::first();
        $this->assertSame(PolicyState::VALIDATED, $created->state);
        $this->assertSame('225.00', (string) $created->premium);
    }

    public function test_trip_end_before_trip_start_is_rejected_with_422(): void
    {
        $payload = $this->validPayload([
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-05',
        ]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_unsupported_plan_is_rejected(): void
    {
        $payload = $this->validPayload(['plan_code' => 'GOLD_TIER']);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_unsupported_sum_insured_is_rejected(): void
    {
        $payload = $this->validPayload(['sum_insured' => 999999]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }

    public function test_same_agent_and_client_request_id_returns_200_and_creates_only_one_row(): void
    {
        $payload = $this->validPayload();

        // First intake attempt -> 201 Created
        $response1 = $this->postJson('/api/policy-requests', $payload);
        $response1->assertStatus(201);
        $uuid1 = $response1->json('data.id');

        // Second intake attempt with exact same agent_id and client_request_id -> 200 Replay
        $response2 = $this->postJson('/api/policy-requests', $payload);
        $response2->assertStatus(200)
            ->assertJsonPath('data.id', $uuid1)
            ->assertJsonPath('data.premium', '225.00')
            ->assertJsonPath('meta.idempotent_replay', true);

        // Verify only 1 database row was created and premium was never altered
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertSame('225.00', (string) PolicyRequest::first()->premium);
    }

    public function test_duplicate_intake_race_condition_is_handled(): void
    {
        $payload = $this->validPayload([
            'client_request_id' => 'RACE-001',
            'agent_id' => 'AGENT-RACE',
        ]);

        // First submission creates row
        $res1 = $this->postJson('/api/policy-requests', $payload);
        $res1->assertStatus(201);

        // Repeated submission hits duplicate-key handler and returns 200 safely
        $res2 = $this->postJson('/api/policy-requests', $payload);
        $res2->assertStatus(200)
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.client_request_id', 'RACE-001');

        $this->assertDatabaseCount('policy_requests', 1);
    }

    public function test_list_endpoint_filters_by_status_and_paginates(): void
    {
        // Create 3 requests
        $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'C1']));
        $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'C2']));
        $this->postJson('/api/policy-requests', $this->validPayload(['client_request_id' => 'C3']));

        $response = $this->getJson('/api/policy-requests?status=VALIDATED&per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_show_endpoint_returns_audit_trail_and_attempts(): void
    {
        $res = $this->postJson('/api/policy-requests', $this->validPayload());
        $uuid = $res->json('data.id');

        $show = $this->getJson("/api/policy-requests/{$uuid}");

        $show->assertStatus(200)
            ->assertJsonPath('data.id', $uuid)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'state',
                    'premium',
                    'audit_trail',
                    'attempts',
                ]
            ]);
    }
}
