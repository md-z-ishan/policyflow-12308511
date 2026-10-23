<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockPolicy;
use App\Services\CallbackProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MockInsurerController extends Controller
{
    public function __construct(
        private readonly CallbackProcessor $callbackProcessor
    ) {}

    /**
     * POST /api/mock-insurer/callback
     * Receive a simulated provider event and process idempotently.
     */
    public function callback(Request $request): JsonResponse
    {
        $signature = $request->header('X-Mock-Signature');
        return $this->callbackProcessor->process($request->all(), $signature);
    }
    /**
     * POST /api/mock-insurer/submit
     * Deterministic mock insurer submit handler.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_reference' => ['required', 'string'],
            'scenario' => ['nullable', 'string'],
            'amount' => ['nullable', 'string'],
            'attempt' => ['nullable', 'integer'],
        ]);

        $clientRef = $validated['client_reference'];
        $scenario = $validated['scenario'] ?? 'SUCCESS';
        $attempt = (int) ($validated['attempt'] ?? 1);

        // Provider-side idempotency: if policy exists for this client_reference, return it
        $existing = MockPolicy::where('client_reference', $clientRef)->first();
        if ($existing) {
            return response()->json([
                'provider_reference' => $existing->provider_reference,
                'policy_number' => $existing->policy_number,
                'status' => $existing->status,
            ], 200);
        }

        // Scenario: TEMPORARY_FAILURE
        // Fails with 503 on attempt 1; recovers and issues policy on attempt >= 2
        if ($scenario === 'TEMPORARY_FAILURE') {
            if ($attempt < 2) {
                return response()->json([
                    'retriable' => true,
                    'message' => 'Simulated temporary insurer gateway failure',
                ], 503);
            }
            // If attempt >= 2, fall through to issue the policy
        }

        // Scenario: TIMEOUT_AFTER_ISSUE
        // Creates policy internally, but fails the HTTP response with 504 Gateway Timeout
        if ($scenario === 'TIMEOUT_AFTER_ISSUE') {
            $policy = $this->createMockPolicy($clientRef, $scenario, 'ISSUED');
            return response()->json([
                'message' => 'Simulated gateway timeout after policy creation',
                'provider_reference' => $policy->provider_reference,
            ], 504);
        }

        // Scenario: DELAYED_CALLBACK or DUPLICATE_CALLBACK
        // Returns 202 Accepted with PENDING status; finalized asynchronously via callback
        if (in_array($scenario, ['DELAYED_CALLBACK', 'DUPLICATE_CALLBACK'], true)) {
            $policy = $this->createMockPolicy($clientRef, $scenario, 'PENDING');
            return response()->json([
                'provider_reference' => $policy->provider_reference,
                'policy_number' => null,
                'status' => 'PENDING',
            ], 202);
        }

        // Scenario: SUCCESS (default)
        $policy = $this->createMockPolicy($clientRef, $scenario, 'ISSUED');
        return response()->json([
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'status' => 'ISSUED',
        ], 200);
    }

    /**
     * GET /api/mock-insurer/status/{client_reference}
     * Reconciliation status inquiry.
     */
    public function status(string $clientReference): JsonResponse
    {
        $policy = MockPolicy::where('client_reference', $clientReference)->first();

        if (!$policy) {
            return response()->json([
                'found' => false,
                'provider_reference' => null,
                'policy_number' => null,
                'status' => null,
            ], 200);
        }

        return response()->json([
            'found' => true,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'status' => $policy->status,
        ], 200);
    }

    /**
     * POST /api/mock-insurer/dev/fire-callback/{provider_reference}
     * Development route to trigger simulated provider callback(s).
     */
    public function fireCallback(string $providerReference): JsonResponse
    {
        if (!app()->environment('local', 'testing')) {
            abort(403, 'Development route only available in local or testing environment.');
        }

        $policy = MockPolicy::where('provider_reference', $providerReference)->firstOrFail();

        $eventId = 'evt_' . Str::random(12);
        $policyNumber = $policy->policy_number ?: ('POL-' . strtoupper(Str::random(10)));

        $payload = [
            'event_id' => $eventId,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policyNumber,
            'occurred_at' => now()->toIso8601String(),
        ];

        // If policy was pending, update it to ISSUED
        if ($policy->status === 'PENDING') {
            $policy->status = 'ISSUED';
            $policy->policy_number = $policyNumber;
            $policy->save();
        }

        $callbackUrl = url('/api/mock-insurer/callback');
        $secret = config('services.mock_insurer.signature_secret');

        $deliveries = [];
        $repetitions = ($policy->scenario === 'DUPLICATE_CALLBACK') ? 3 : 1;

        for ($i = 1; $i <= $repetitions; $i++) {
            $response = Http::withHeaders([
                'X-Mock-Signature' => $secret,
            ])->post($callbackUrl, $payload);

            $deliveries[] = [
                'delivery_attempt' => $i,
                'status' => $response->status(),
                'response' => $response->json(),
            ];
        }

        return response()->json([
            'message' => "Callback fired {$repetitions} time(s).",
            'payload' => $payload,
            'deliveries' => $deliveries,
        ], 200);
    }

    private function createMockPolicy(string $clientRef, string $scenario, string $status): MockPolicy
    {
        $providerRef = 'PR-' . strtoupper(Str::random(12));
        $policyNumber = 'POL-' . strtoupper(Str::random(10));

        return MockPolicy::create([
            'client_reference' => $clientRef,
            'provider_reference' => $providerRef,
            'policy_number' => $policyNumber,
            'scenario' => $scenario,
            'status' => $status,
        ]);
    }
}
