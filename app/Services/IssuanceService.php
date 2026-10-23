<?php

namespace App\Services;

use App\Enums\AttemptOutcome;
use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Resources\PolicyRequestResource;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Support\Facades\DB;

class IssuanceService
{
    public function __construct(
        private readonly PolicyStateMachine $stateMachine,
        private readonly MockInsurerClient $client
    ) {}

    /**
     * Submit policy request for issuance.
     * Uses pessimistic locking before HTTP call to mark the request SUBMITTED in-flight,
     * releases the lock during the external call, and locks again to persist the result.
     */
    public function submit(string $uuid): PolicyRequestResource
    {
        $shouldCallProvider = false;
        $attemptNo = 0;

        // Step 1: Concurrency check and in-flight marking inside transaction
        $request = DB::transaction(function () use ($uuid, &$shouldCallProvider, &$attemptNo) {
            $row = PolicyRequest::where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            // Already issued: idempotent immediate return, no provider call
            if ($row->state === PolicyState::ISSUED) {
                return $row;
            }

            // Already in flight or awaiting async confirmation: do not trigger duplicate provider call
            if (in_array($row->state, [PolicyState::SUBMITTED, PolicyState::PENDING_CONFIRMATION], true)) {
                return $row;
            }

            // If state requires reconciliation, reconcile instead of creating a duplicate submission
            if ($row->state === PolicyState::NEEDS_RECONCILIATION) {
                return $row; // Will reconcile below
            }

            // Cannot submit failed or unvalidated requests
            if ($row->state === PolicyState::FAILED) {
                throw new InvalidStateTransitionException(
                    $row->state->value,
                    PolicyState::SUBMITTED->value,
                    "Policy request has failed and cannot be submitted."
                );
            }

            if ($row->state !== PolicyState::VALIDATED) {
                throw new InvalidStateTransitionException(
                    $row->state?->value ?? 'NONE',
                    PolicyState::SUBMITTED->value,
                    "Policy request must be in VALIDATED state to submit."
                );
            }

            // Increment attempt count and mark in-flight
            $attemptNo = $row->attempt_count + 1;
            $row->attempt_count = $attemptNo;
            $row->save();

            // Record pending attempt
            IssuanceAttempt::create([
                'policy_request_id' => $row->id,
                'attempt_no' => $attemptNo,
                'idempotency_key' => "{$row->uuid}-{$attemptNo}",
                'outcome' => AttemptOutcome::PENDING,
                'started_at' => now(),
            ]);

            $this->stateMachine->transition(
                request: $row,
                toState: PolicyState::SUBMITTED,
                reason: "Submitting attempt #{$attemptNo} to external insurer",
                meta: ['attempt_no' => $attemptNo]
            );

            $shouldCallProvider = true;
            return $row;
        });

        // If in NEEDS_RECONCILIATION, execute reconciliation instead
        if ($request->state === PolicyState::NEEDS_RECONCILIATION) {
            return new PolicyRequestResource($this->reconcile($request));
        }

        // If already completed or in-flight, return safely without another provider call
        if (!$shouldCallProvider) {
            return new PolicyRequestResource($request);
        }

        // Step 2: Call provider outside the database transaction
        $providerResponse = $this->client->submit($request, $attemptNo);

        // Step 3: Re-lock row and process provider outcome
        $updated = DB::transaction(function () use ($uuid, $attemptNo, $providerResponse) {
            $row = PolicyRequest::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $attempt = IssuanceAttempt::where('policy_request_id', $row->id)
                ->where('attempt_no', $attemptNo)
                ->first();

            $status = $providerResponse['status'];
            $body = $providerResponse['body'];
            $isTimeout = $providerResponse['is_timeout'] ?? false;

            // Scenario 1: Immediate Success (200)
            if ($status === 200) {
                $row->provider_reference = $body['provider_reference'] ?? $row->provider_reference;
                $row->policy_number = $body['policy_number'] ?? $row->policy_number;
                $row->last_provider_response = $body;
                $row->last_error = null;
                $row->save();

                $attempt?->update([
                    'outcome' => AttemptOutcome::SUCCESS,
                    'http_status' => 200,
                    'response_body' => $body,
                    'finished_at' => now(),
                ]);

                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::ISSUED,
                    reason: 'Provider confirmed policy issuance',
                    meta: ['policy_number' => $row->policy_number]
                );

                return $row;
            }

            // Scenario 2: Pending Async Confirmation (202)
            if ($status === 202) {
                $row->provider_reference = $body['provider_reference'] ?? $row->provider_reference;
                $row->last_provider_response = $body;
                $row->save();

                $attempt?->update([
                    'outcome' => AttemptOutcome::PENDING,
                    'http_status' => 202,
                    'response_body' => $body,
                    'finished_at' => now(),
                ]);

                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::PENDING_CONFIRMATION,
                    reason: 'Provider accepted submission; awaiting asynchronous callback',
                    meta: ['provider_reference' => $row->provider_reference]
                );

                return $row;
            }

            // Scenario 3: Retriable Temporary Failure (503)
            if ($status === 503) {
                $row->last_error = $providerResponse['error_message'] ?? 'Temporary provider gateway failure';
                $row->last_provider_response = $body;
                $row->save();

                $attempt?->update([
                    'outcome' => AttemptOutcome::TEMPORARY_FAILURE,
                    'http_status' => 503,
                    'response_body' => $body,
                    'error_message' => $row->last_error,
                    'finished_at' => now(),
                ]);

                // Reset back to VALIDATED to allow safe retry, preserving original premium
                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::VALIDATED,
                    reason: 'Temporary failure encountered; safe to retry',
                    meta: ['attempt_no' => $attemptNo]
                );

                return $row;
            }

            // Scenario 4: Timeout or Connection Failure (504)
            if ($isTimeout || $status === 504) {
                $row->last_error = $providerResponse['error_message'] ?? 'Provider request timed out';
                $row->save();

                $attempt?->update([
                    'outcome' => AttemptOutcome::TIMEOUT,
                    'http_status' => 504,
                    'error_message' => $row->last_error,
                    'finished_at' => now(),
                ]);

                // Transition to NEEDS_RECONCILIATION; NEVER blindly re-issue
                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::NEEDS_RECONCILIATION,
                    reason: 'Timeout occurred during issuance; reconciliation required before retry',
                    meta: ['attempt_no' => $attemptNo]
                );

                return $row;
            }

            // Scenario 5: Non-retriable failure (4xx or 500)
            $row->last_error = $providerResponse['error_message'] ?? "Provider rejected with HTTP {$status}";
            $row->last_provider_response = $body;
            $row->save();

            $attempt?->update([
                'outcome' => AttemptOutcome::ERROR,
                'http_status' => $status,
                'response_body' => $body,
                'error_message' => $row->last_error,
                'finished_at' => now(),
            ]);

            $this->stateMachine->transition(
                request: $row,
                toState: PolicyState::FAILED,
                reason: "Provider rejected request with status {$status}",
                meta: ['http_status' => $status]
            );

            return $row;
        });

        return new PolicyRequestResource($updated);
    }

    /**
     * Reconcile a request with provider status inquiry.
     */
    public function reconcile(PolicyRequest $request): PolicyRequest
    {
        $statusInfo = $this->client->checkStatus($request->uuid);

        return DB::transaction(function () use ($request, $statusInfo) {
            $row = PolicyRequest::where('id', $request->id)->lockForUpdate()->firstOrFail();

            // If lookup failed, remain in current state
            if (isset($statusInfo['lookup_error'])) {
                $row->last_error = "Reconciliation check failed: " . $statusInfo['lookup_error'];
                $row->save();
                return $row;
            }

            $isFound = $statusInfo['found'] ?? false;
            $providerStatus = $statusInfo['status'] ?? null;

            if ($isFound && $providerStatus === 'ISSUED') {
                $row->provider_reference = $statusInfo['provider_reference'] ?? $row->provider_reference;
                $row->policy_number = $statusInfo['policy_number'] ?? $row->policy_number;
                $row->save();

                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::ISSUED,
                    reason: 'Reconciliation confirmed policy was issued by provider',
                    meta: ['policy_number' => $row->policy_number]
                );

                return $row;
            }

            if ($isFound && $providerStatus === 'PENDING') {
                $row->provider_reference = $statusInfo['provider_reference'] ?? $row->provider_reference;
                $row->save();

                if ($row->state !== PolicyState::PENDING_CONFIRMATION) {
                    $this->stateMachine->transition(
                        request: $row,
                        toState: PolicyState::PENDING_CONFIRMATION,
                        reason: 'Reconciliation confirmed policy is pending provider confirmation',
                        meta: ['provider_reference' => $row->provider_reference]
                    );
                }

                return $row;
            }

            if (!$isFound) {
                // Provider confirmed no policy exists; safe to retry
                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::VALIDATED,
                    reason: 'Reconciliation confirmed no policy exists at provider; safe to retry'
                );

                return $row;
            }

            return $row;
        });
    }
}
