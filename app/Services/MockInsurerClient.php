<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MockInsurerClient
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.mock_insurer.base_url', 'http://127.0.0.1:8000/api/mock-insurer');
        $this->timeout = (int) config('services.mock_insurer.timeout', 3);
    }

    /**
     * Submit policy issuance to the mock provider.
     * Note: PII is strictly excluded from payload (only client_reference, scenario, amount, attempt).
     */
    public function submit(PolicyRequest $request, int $attemptNo): array
    {
        $payload = [
            'client_reference' => $request->uuid,
            'scenario' => $request->mock_scenario?->value ?? 'SUCCESS',
            'amount' => (string) $request->premium,
            'attempt' => $attemptNo,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/submit", $payload);

            return [
                'status' => $response->status(),
                'body' => $response->json() ?? [],
                'is_timeout' => false,
                'is_connection_error' => false,
                'error_message' => $response->failed() ? ($response->json('message') ?? 'Provider error') : null,
            ];

        } catch (ConnectionException $e) {
            Log::warning("Mock insurer connection timed out for policy request [{$request->uuid}]");
            return [
                'status' => 504,
                'body' => null,
                'is_timeout' => true,
                'is_connection_error' => true,
                'error_message' => 'Connection to insurer timed out: ' . $e->getMessage(),
            ];
        } catch (Exception $e) {
            Log::error("Mock insurer HTTP request exception: " . $e->getMessage());
            return [
                'status' => 500,
                'body' => null,
                'is_timeout' => false,
                'is_connection_error' => true,
                'error_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reconcile status with the provider for a given client_reference.
     */
    public function checkStatus(string $clientReference): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/status/{$clientReference}");

            if ($response->successful()) {
                return $response->json();
            }

            return [
                'found' => false,
                'provider_reference' => null,
                'policy_number' => null,
                'status' => null,
                'lookup_error' => 'Provider status endpoint returned status ' . $response->status(),
            ];
        } catch (Exception $e) {
            Log::error("Status lookup failed for [{$clientReference}]: " . $e->getMessage());
            return [
                'found' => false,
                'provider_reference' => null,
                'policy_number' => null,
                'status' => null,
                'lookup_error' => $e->getMessage(),
            ];
        }
    }
}
