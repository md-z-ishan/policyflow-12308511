<?php

namespace App\Services;

use App\Enums\PolicyState;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

/**
 * PolicyStateMachine
 *
 * Single source of truth for all policy request state transitions.
 * Enforces explicit transition rules, immutability of terminal states,
 * and maintains an append-only audit trail in policy_state_history.
 */
class PolicyStateMachine
{
    /**
     * Transition map defining strictly allowed (from -> [to]) state paths.
     */
    private const ALLOWED_TRANSITIONS = [
        PolicyState::RECEIVED->value => [
            PolicyState::VALIDATED->value,
            PolicyState::FAILED->value,
        ],
        PolicyState::VALIDATED->value => [
            PolicyState::SUBMITTED->value,
            PolicyState::FAILED->value,
        ],
        PolicyState::SUBMITTED->value => [
            PolicyState::PENDING_CONFIRMATION->value,
            PolicyState::ISSUED->value,
            PolicyState::FAILED->value,
            PolicyState::NEEDS_RECONCILIATION->value,
            PolicyState::VALIDATED->value, // For retriable temporary failure
        ],
        PolicyState::PENDING_CONFIRMATION->value => [
            PolicyState::ISSUED->value,
            PolicyState::FAILED->value,
            PolicyState::NEEDS_RECONCILIATION->value,
        ],
        PolicyState::NEEDS_RECONCILIATION->value => [
            PolicyState::ISSUED->value,
            PolicyState::FAILED->value,
            PolicyState::PENDING_CONFIRMATION->value,
            PolicyState::VALIDATED->value, // Provider confirmed no policy exists; safe to retry
        ],
        // Terminal states: no outgoing transitions permitted
        PolicyState::ISSUED->value => [],
        PolicyState::FAILED->value => [],
    ];

    /**
     * Transition a PolicyRequest to a new state.
     * Must be called within an active database transaction.
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $request,
        PolicyState $toState,
        string $reason,
        array $meta = []
    ): PolicyRequest {
        if (!DB::transactionLevel()) {
            throw new \RuntimeException("State machine transitions must execute within a database transaction.");
        }

        $fromState = $request->state;

        // Terminal states check: ISSUED and FAILED can never regress or be overwritten
        if ($fromState !== null && $fromState->isTerminal()) {
            throw new InvalidStateTransitionException(
                $fromState->value,
                $toState->value,
                "State [{$fromState->value}] is terminal and cannot transition to any other state."
            );
        }

        // Validate allowed transition
        $fromValue = $fromState?->value;
        $allowedTargets = $fromValue !== null ? (self::ALLOWED_TRANSITIONS[$fromValue] ?? []) : [];

        if (!in_array($toState->value, $allowedTargets, true)) {
            throw new InvalidStateTransitionException(
                $fromValue ?? 'NONE',
                $toState->value,
                "Transition not allowed by state machine rules."
            );
        }

        // Update model state
        $request->state = $toState;
        $request->save();

        // Write immutable audit trail
        PolicyStateHistory::create([
            'policy_request_id' => $request->id,
            'from_state' => $fromValue,
            'to_state' => $toState->value,
            'reason' => $reason,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);

        return $request;
    }
}
