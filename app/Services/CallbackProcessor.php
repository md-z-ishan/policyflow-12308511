<?php

namespace App\Services;

use App\Enums\PolicyState;
use App\Enums\ProviderEventOutcome;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CallbackProcessor
{
    public function __construct(
        private readonly PolicyStateMachine $stateMachine
    ) {}

    /**
     * Process an incoming provider callback idempotently.
     */
    public function process(array $payload, ?string $signature = null): JsonResponse
    {
        $eventId = $payload['event_id'] ?? null;
        $providerRef = $payload['provider_reference'] ?? null;
        $eventType = $payload['event_type'] ?? null;
        $policyNumber = $payload['policy_number'] ?? null;

        if (empty($eventId) || empty($providerRef) || empty($eventType)) {
            return response()->json([
                'message' => 'Invalid callback payload: missing required fields.',
            ], 422);
        }

        // Optional signature verification check
        $expectedSignature = config('services.mock_insurer.signature_secret');
        if ($signature !== null && $signature !== $expectedSignature) {
            return response()->json([
                'message' => 'Invalid signature.',
            ], 401);
        }

        // Step 1: Attempt to atomically record provider event
        try {
            return DB::transaction(function () use ($eventId, $providerRef, $eventType, $policyNumber, $payload) {
                // Insert unique provider event
                $event = ProviderEvent::create([
                    'provider_event_id' => $eventId,
                    'provider_reference' => $providerRef,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'outcome' => ProviderEventOutcome::APPLIED,
                ]);

                // Find policy request by provider_reference
                $request = PolicyRequest::where('provider_reference', $providerRef)
                    ->lockForUpdate()
                    ->first();

                if (!$request) {
                    $event->update([
                        'outcome' => ProviderEventOutcome::UNKNOWN_REQUEST,
                        'processed_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 'unknown_request',
                        'message' => 'No policy request found matching provider_reference.',
                    ], 202);
                }

                $event->update(['policy_request_id' => $request->id]);

                // Check terminal states: ISSUED or FAILED cannot be altered by late callbacks
                if ($request->state->isTerminal()) {
                    $event->update([
                        'outcome' => ProviderEventOutcome::REJECTED_INVALID_TRANSITION,
                        'processed_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 'terminal_state_preserved',
                        'message' => "Request is already in terminal state [{$request->state->value}]; callback ignored.",
                    ], 200);
                }

                // Process POLICY_ISSUED
                if ($eventType === 'POLICY_ISSUED') {
                    if ($policyNumber) {
                        $request->policy_number = $policyNumber;
                    }
                    $request->last_provider_response = $payload;
                    $request->save();

                    $this->stateMachine->transition(
                        request: $request,
                        toState: PolicyState::ISSUED,
                        reason: 'Callback confirmed policy issuance',
                        meta: ['event_id' => $eventId, 'policy_number' => $request->policy_number]
                    );

                    $event->update([
                        'outcome' => ProviderEventOutcome::APPLIED,
                        'processed_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 'applied',
                        'message' => 'Policy issued successfully via callback.',
                    ], 200);
                }

                // Process POLICY_FAILED
                if ($eventType === 'POLICY_FAILED') {
                    $request->last_error = $payload['error_message'] ?? 'Provider reported policy failure';
                    $request->last_provider_response = $payload;
                    $request->save();

                    $this->stateMachine->transition(
                        request: $request,
                        toState: PolicyState::FAILED,
                        reason: 'Callback reported policy issuance failure',
                        meta: ['event_id' => $eventId]
                    );

                    $event->update([
                        'outcome' => ProviderEventOutcome::APPLIED,
                        'processed_at' => now(),
                    ]);

                    return response()->json([
                        'status' => 'applied',
                        'message' => 'Policy marked as failed via callback.',
                    ], 200);
                }

                return response()->json([
                    'status' => 'unrecognized_event',
                ], 422);
            });

        } catch (UniqueConstraintViolationException | QueryException $e) {
            // Unique violation on provider_event_id: Duplicate event delivered
            $isDuplicate = $e instanceof UniqueConstraintViolationException
                || str_contains($e->getMessage(), 'provider_event_id')
                || str_contains($e->getMessage(), '1062 Duplicate entry')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            if ($isDuplicate) {
                // Idempotent success response; nothing in business state is altered
                return response()->json([
                    'status' => 'duplicate_ignored',
                    'message' => 'Duplicate provider event detected; processing skipped.',
                ], 200);
            }

            throw $e;
        }
    }
}
