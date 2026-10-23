<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyState;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\IssuanceService;
use App\Services\PolicyStateMachine;
use App\Services\PremiumCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function __construct(
        private readonly PremiumCalculator $calculator,
        private readonly PolicyStateMachine $stateMachine,
        private readonly IssuanceService $issuanceService
    ) {}

    /**
     * POST /api/policy-requests
     * Create a new policy request or safely return existing request on duplicate submission.
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // 1. Compute premium first (pure business logic, isolated from DB)
        $pricing = $this->calculator->calculate(
            planCode: $validated['plan_code'],
            dateOfBirth: $validated['date_of_birth'],
            tripStart: $validated['trip_start'],
            tripEnd: $validated['trip_end'],
            destination: $validated['destination'],
            sumInsured: $validated['sum_insured']
        );

        $uuid = (string) Str::uuid();

        try {
            // 2. Atomic creation attempt inside database transaction
            $policyRequest = DB::transaction(function () use ($validated, $pricing, $uuid) {
                $row = PolicyRequest::create([
                    'uuid' => $uuid,
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $validated['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => $validated['plan_code'],
                    'sum_insured' => $validated['sum_insured'],
                    'travel_days' => $pricing->travel_days,
                    'premium' => $pricing->premium,
                    'premium_breakdown' => $pricing->breakdown,
                    'state' => PolicyState::RECEIVED,
                    'mock_scenario' => $validated['mock_scenario'] ?? null,
                ]);

                // Initial audit trail entry
                PolicyStateHistory::create([
                    'policy_request_id' => $row->id,
                    'from_state' => null,
                    'to_state' => PolicyState::RECEIVED->value,
                    'reason' => 'Request received and persisted',
                    'created_at' => now(),
                ]);

                // Transition to VALIDATED since all intake validation rules succeeded
                $this->stateMachine->transition(
                    request: $row,
                    toState: PolicyState::VALIDATED,
                    reason: 'Intake validation succeeded'
                );

                return $row;
            });

            return (new PolicyRequestResource($policyRequest))
                ->withMeta(['idempotent_replay' => false])
                ->response()
                ->setStatusCode(201);

        } catch (UniqueConstraintViolationException | QueryException $e) {
            // 3. Catch unique constraint violation on uq_agent_client_request
            $isDuplicate = $e instanceof UniqueConstraintViolationException
                || str_contains($e->getMessage(), 'uq_agent_client_request')
                || str_contains($e->getMessage(), '1062 Duplicate entry')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed');

            if (!$isDuplicate) {
                throw $e;
            }

            // Fetch existing record by compound unique key
            $existing = PolicyRequest::where('agent_id', $validated['agent_id'])
                ->where('client_request_id', $validated['client_request_id'])
                ->firstOrFail();

            // Verify if payload matched
            $payloadMatches = ($existing->customer_name === $validated['customer_name'])
                && ($existing->destination->value === $validated['destination'])
                && ($existing->plan_code->value === $validated['plan_code'])
                && ((int) $existing->sum_insured === (int) $validated['sum_insured']);

            return (new PolicyRequestResource($existing))
                ->withMeta([
                    'idempotent_replay' => true,
                    'payload_matches' => $payloadMatches,
                ])
                ->response()
                ->setStatusCode(200);
        }
    }

    /**
     * GET /api/policy-requests/{id}
     * Retrieve status, premium, references, audit trail and issuance attempts.
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::where('uuid', $id)
            ->with(['stateHistory', 'attempts'])
            ->firstOrFail();

        return (new PolicyRequestResource($policyRequest))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * GET /api/policy-requests?status=ISSUED&per_page=15
     * List requests with status filter and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::enum(PolicyState::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PolicyRequest::query()->orderBy('id', 'desc');

        if (!empty($validated['status'])) {
            $query->where('state', $validated['status']);
        }

        $perPage = $validated['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return PolicyRequestResource::collection($paginated)->response();
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Submit request to mock insurer with concurrency safeguards.
     */
    public function submit(string $id): JsonResponse
    {
        $resource = $this->issuanceService->submit($id);
        return $resource->response()->setStatusCode(200);
    }
}
