# PolicyFlow: Fault-Tolerant Insurance Issuance & Reconciliation Engine

> **TrippersShield Campus Engineering Practical Assessment**  
> Candidate: `[Your Name / Candidate ID]`  
> Stack: PHP 8.3+, Laravel 11, MySQL 8 / PostgreSQL (SQLite for testing), REST JSON API.

---

## 1. Overview & Architecture

**PolicyFlow** is a resilient, fault-tolerant insurance policy issuance and reconciliation engine. In distributed insurance systems, external provider APIs are inherently unreliable: networks time out, partner services encounter transient outages, and webhook callbacks may arrive out-of-order or repeatedly.

PolicyFlow guarantees:
1. **Zero Duplicate Issuances**: Guaranteed by database-level compound unique constraints and deterministic idempotency keys.
2. **Deterministic Financial Accuracy**: Strict decimal-safe arithmetic using PHP's `bcmath` (scale 6, half-up rounding to 2 decimals). Money is never represented as floating-point.
3. **Explicit State Transitions**: Managed by a central finite state machine (`PolicyStateMachine`) with an immutable audit log.
4. **Resilient Concurrency Control**: Uses pessimistic locking with in-flight state markers (`SUBMITTED`) and releases database locks *before* executing slow outbound network calls.

### System Architecture Diagram

```
+-----------------------------------------------------------------------------------------+
|                                      AGENTS & CLIENTS                                   |
+-----------------------------------------------------------------------------------------+
       |                                      |                                    |
       | 1. POST /api/policy-requests        | 2. POST /submit                    | 3. GET status
       v                                      v                                    v
+------------------+                  +------------------+                 +--------------+
|  Intake & Price  |                  | Issuance Service |                 | Policy Read  |
|  (bcmath engine) |                  | (Two-Phase Txn)  |                 | & Audit Log  |
+------------------+                  +------------------+                 +--------------+
       |                                      |                                    |
       | Unique constraint:                   | Lock row -> Mark SUBMITTED         |
       | (agent_id, client_request_id)        | -> Release lock -> Call Insurer    |
       v                                      v                                    |
+--------------------------------------------------------+                         |
|                 POLICIES DATABASE                     | <-----------------------+
|  - policy_requests (State, Unique policy_number)       |
|  - issuance_attempts (idempotency: {uuid}-{attempt_no})|
|  - policy_state_history (Immutable audit trail)        |
|  - provider_events (Unique provider_event_id)         |
+--------------------------------------------------------+
       ^                                      |
       |                                      | HTTP Outbound (3s Timeout)
       | Webhook Callback                     v
+-----------------------------+       +------------------------------------+
|  POST /mock-insurer/callback| <---- |     MOCK INSURER (Deterministic)   |
|  (Unique event_id check)    |       |  - Scenarios: SUCCESS, 503, 504    |
+-----------------------------+       |  - GET /mock-insurer/status/{ref}  |
                                      +------------------------------------+
                                                        ^
                                                        |
+-----------------------------------------+             |
| Reconciliation Command (Artisan)        | ------------+
| (Resolves NEEDS_RECONCILIATION & Stale) |
+-----------------------------------------+
```

---

## 2. Prerequisites & Environment

- **PHP**: 8.2 or higher (8.3 recommended) with extensions: `bcmath`, `curl`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql` (or `pdo_sqlite`).
- **Composer**: 2.x
- **Database**: MySQL 8+ or PostgreSQL (SQLite `:memory:` is preconfigured for tests).
- **Git**

---

## 3. Local Setup Instructions

```bash
# 1. Clone repository and navigate into project directory
cd policyflow

# 2. Install Composer dependencies
composer install

# 3. Environment configuration
cp .env.example .env
php artisan key:generate

# 4. Database Setup
# Configure DB credentials in .env (or use SQLite):
# DB_CONNECTION=mysql
# DB_DATABASE=policyflow
# DB_USERNAME=root
# DB_PASSWORD=

# Run migrations
php artisan migrate

# 5. Start local server
php artisan serve
```

Server will run at `http://127.0.0.1:8000`.

---

## 4. Running Automated Tests

Run the complete test suite:

```bash
php artisan test
```

All 15 mandatory and edge-case tests run automatically using an in-memory SQLite database, including:
- Pure decimal `bcmath` verification (canonical ₹225.00 example, age boundaries 17/18, 70/71, 1-day trips).
- Duplicate intake race-condition handling.
- Repeated submit idempotency (`Http::assertSentCount(1)`).
- Timeout-after-success transition to `NEEDS_RECONCILIATION` and automated artisan reconciliation.
- Duplicate callback deduplication and terminal state (`ISSUED`) protection.

---

## 5. Duplicate Prevention & Concurrency Strategy

### Reliability Case 1: Duplicate Intake Handling
- **Database Guarantee**: A compound unique index `uq_agent_client_request` is enforced across `(agent_id, client_request_id)` on the `policy_requests` table.
- **Atomic Creation**:
  1. The premium is computed first via pure function logic.
  2. The application attempts to insert the new record inside a transaction.
  3. If another concurrent request inserts the same key first, the database raises a unique constraint violation (`UniqueConstraintViolationException`).
  4. The exception is caught, the existing row is retrieved, and returned with HTTP **200 OK** and metadata `idempotent_replay: true`. No duplicate record is created, and the original snapshot premium is never recalculated or overwritten.

### Reliability Case 2: Duplicate Callback Deduplication
- **Database Guarantee**: The `provider_events` table enforces a `UNIQUE` constraint on `provider_event_id`.
- **Atomic Callback Ingestion**:
  1. The incoming callback immediately attempts to insert into `provider_events`.
  2. If a duplicate event arrives (network replay or retry), the unique constraint triggers, catching the violation and returning **200 OK** with `{"status": "duplicate_ignored"}`.
  3. No state transitions or mutations are performed on the policy request.
  4. If the policy request is already in a terminal state (`ISSUED` or `FAILED`), any late-arriving conflicting callback is logged as `REJECTED_INVALID_TRANSITION` without modifying the existing business state.

### Reliability Case 3: Timeout After Success & Reconciliation
- **The Problem**: A provider receives the request and issues the policy, but the HTTP connection drops or times out before returning the response to the client. Blindly re-submitting would create a duplicate policy or double-charge.
- **The Solution**:
  1. If a submit encounters a `ConnectionException` or HTTP 504, the request transitions to **`NEEDS_RECONCILIATION`** (never to `VALIDATED`).
  2. The artisan command `php artisan policyflow:reconcile` (or a subsequent submit attempt) queries the provider's status endpoint (`GET /api/mock-insurer/status/{client_reference}`).
  3. If the policy was issued on the provider side, the references are stored and the state transitions to `ISSUED`.
  4. If the provider confirms no policy exists, it transitions to `VALIDATED`, safely unlocking a fresh attempt.

### Two-Phase Transaction & Locking Approach
Why not hold the database row lock during the provider HTTP call?
- Holding row locks during slow external HTTP calls (e.g., 3-second network latency) exhausts database connection pools and causes thread starvation under load.
- **PolicyFlow Approach**:
  1. **Phase 1 (Lock & Mark)**: Inside a brief transaction, acquire `lockForUpdate()`, verify status is `VALIDATED`, record the attempt, transition state to `SUBMITTED`, and **commit** (releasing the database lock).
  2. **Outbound Call**: The HTTP call executes outside the database transaction. Any concurrent submit request arriving during this time sees state `SUBMITTED` and immediately exits without calling the provider.
  3. **Phase 2 (Lock & Finalize)**: Re-acquire the row lock, record the provider outcome (`ISSUED`, `PENDING_CONFIRMATION`, `NEEDS_RECONCILIATION`, or retriable `VALIDATED`), and commit.

---

## 6. API Contract & Examples

### 1. Intake: Create Policy Request
`POST /api/policy-requests`

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
**Response (201 Created):**
```json
{
  "data": {
    "id": "e4f8a3d1-92b5-4b47-975a-0a2b9e6a8d71",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "state": "VALIDATED",
    "premium": "225.00",
    "travel_days": 5,
    "provider_reference": null,
    "policy_number": null,
    "attempt_count": 0,
    "created_at": "2026-10-23T18:00:00.000000Z",
    "updated_at": "2026-10-23T18:00:00.000000Z"
  },
  "meta": {
    "idempotent_replay": false
  }
}
```

### 2. Repeated Intake (Idempotent Replay)
Sending the same payload again returns HTTP **200 OK**:
```json
{
  "data": {
    "id": "e4f8a3d1-92b5-4b47-975a-0a2b9e6a8d71",
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "state": "VALIDATED",
    "premium": "225.00",
    "travel_days": 5,
    "provider_reference": null,
    "policy_number": null,
    "attempt_count": 0,
    "created_at": "2026-10-23T18:00:00.000000Z",
    "updated_at": "2026-10-23T18:00:00.000000Z"
  },
  "meta": {
    "idempotent_replay": true,
    "payload_matches": true
  }
}
```

### 3. Submit for Issuance
`POST /api/policy-requests/{id}/submit`

```bash
curl -X POST http://127.0.0.1:8000/api/policy-requests/e4f8a3d1-92b5-4b47-975a-0a2b9e6a8d71/submit
```
**Response (200 OK):**
```json
{
  "data": {
    "id": "e4f8a3d1-92b5-4b47-975a-0a2b9e6a8d71",
    "state": "ISSUED",
    "premium": "225.00",
    "provider_reference": "PR-X91A2KB71Z",
    "policy_number": "POL-9847192837",
    "attempt_count": 1
  }
}
```

### 4. Query Policy & Audit Trail
`GET /api/policy-requests/{id}`

Returns policy status along with the complete state machine history (`audit_trail`) and all `attempts`.

---

## 7. Assumptions & Trade-offs

1. **Provider-Side Idempotency**:
   - The mock provider treats `client_reference` (our `uuid`) as the idempotency key. Repeating a submit for the same `client_reference` returns the previously created policy.
2. **Key Reuse with Modified Payload**:
   - If an agent reuses an existing `client_request_id` with different personal details, the system returns the original record (HTTP 200) with `"payload_matches": false` in the meta block to alert the client of key collision.
3. **Webhook Security**:
   - For evaluation simplicity, webhook authentication uses a shared secret header (`X-Mock-Signature`) rather than HMAC-SHA256 signature hashes.
4. **Local Concurrency Testing**:
   - Note that SQLite locks whole database files rather than specific rows (`lockForUpdate` is ignored in SQLite). For multi-threaded concurrency stress testing, MySQL 8+ or PostgreSQL should be used.

---

## 8. File Structure Summary

```
app/
├── Console/Commands/ReconcilePendingCommand.php
├── Enums/
│   ├── AttemptOutcome.php
│   ├── Destination.php
│   ├── MockScenario.php
│   ├── PlanCode.php
│   ├── PolicyState.php
│   └── ProviderEventOutcome.php
├── Exceptions/
│   └── InvalidStateTransitionException.php
├── Http/
│   ├── Controllers/Api/
│   │   ├── MockInsurerController.php
│   │   └── PolicyRequestController.php
│   ├── Requests/StorePolicyRequest.php
│   └── Resources/PolicyRequestResource.php
├── Models/
│   ├── IssuanceAttempt.php
│   ├── MockPolicy.php
│   ├── PolicyRequest.php
│   ├── PolicyStateHistory.php
│   └── ProviderEvent.php
└── Services/
    ├── CallbackProcessor.php
    ├── IssuanceService.php
    ├── MockInsurerClient.php
    ├── PolicyStateMachine.php
    ├── PremiumCalculationResult.php
    └── PremiumCalculator.php
database/migrations/
├── 2026_01_01_000001_create_policy_requests_table.php
├── 2026_01_01_000002_create_issuance_attempts_table.php
├── 2026_01_01_000003_create_provider_events_table.php
├── 2026_01_01_000004_create_policy_state_history_table.php
└── 2026_01_01_000005_create_mock_policies_table.php
examples/curl.sh
tests/
├── Feature/
│   ├── CallbackProcessingTest.php
│   ├── PolicyIntakeTest.php
│   └── PolicyIssuanceAndReconciliationTest.php
└── Unit/
    ├── PolicyStateMachineTest.php
    └── PremiumCalculatorTest.php
AI_USAGE.md
README.md
```
