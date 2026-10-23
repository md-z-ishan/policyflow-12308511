#!/usr/bin/env bash
# PolicyFlow API Demonstration & Testing Script

BASE_URL="http://127.0.0.1:8000/api"

echo "=== 1. INTAKE: Create New Policy Request (201 Created) ==="
INTAKE_RESP=$(curl -s -X POST "$BASE_URL/policy-requests" \
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
  }')
echo "$INTAKE_RESP" | jq .
REQUEST_ID=$(echo "$INTAKE_RESP" | jq -r '.data.id')

echo -e "\n=== 2. RELIABILITY CASE 1: Duplicate Intake Submission (200 Replay) ==="
curl -s -X POST "$BASE_URL/policy-requests" \
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
  }' | jq .

echo -e "\n=== 3. GET Single Policy Request (with Audit Trail & Attempts) ==="
curl -s -X GET "$BASE_URL/policy-requests/$REQUEST_ID" | jq .

echo -e "\n=== 4. LIST Policy Requests (Filtered & Paginated) ==="
curl -s -X GET "$BASE_URL/policy-requests?status=VALIDATED&per_page=5" | jq .

echo -e "\n=== 5. SUBMIT Policy to Insurer (SUCCESS Scenario -> 200 ISSUED) ==="
curl -s -X POST "$BASE_URL/policy-requests/$REQUEST_ID/submit" | jq .

echo -e "\n=== 6. SUBMIT Repeat (Idempotent 200 Replay, No Extra Call) ==="
curl -s -X POST "$BASE_URL/policy-requests/$REQUEST_ID/submit" | jq .

echo -e "\n=== 7. RELIABILITY CASE 2: Duplicate Callback Processing ==="
CALLBACK_PAYLOAD='{
  "event_id": "evt_demo_callback_101",
  "event_type": "POLICY_ISSUED",
  "provider_reference": "PR-DEMO-REF",
  "policy_number": "POL-DEMO-555",
  "occurred_at": "2026-10-23T18:00:00Z"
}'

echo "--- First Delivery (Applied) ---"
curl -s -X POST "$BASE_URL/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: mock-shared-secret-placeholder" \
  -d "$CALLBACK_PAYLOAD" | jq .

echo "--- Second Delivery (Duplicate Ignored) ---"
curl -s -X POST "$BASE_URL/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "X-Mock-Signature: mock-shared-secret-placeholder" \
  -d "$CALLBACK_PAYLOAD" | jq .

echo -e "\n=== 8. RELIABILITY CASE 3: Timeout Scenario & Reconciliation ==="
TIMEOUT_INTAKE=$(curl -s -X POST "$BASE_URL/policy-requests" \
  -H "Content-Type: application/json" \
  -d '{
    "client_request_id": "AGT-TIMEOUT-001",
    "agent_id": "AGENT-17",
    "customer_name": "Timeout Demo",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000,
    "mock_scenario": "TIMEOUT_AFTER_ISSUE"
  }')
TIMEOUT_ID=$(echo "$TIMEOUT_INTAKE" | jq -r '.data.id')

echo "--- Submitting (times out -> transition to NEEDS_RECONCILIATION) ---"
curl -s -X POST "$BASE_URL/policy-requests/$TIMEOUT_ID/submit" | jq .

echo "--- Running Reconciliation Command ---"
php artisan policyflow:reconcile

echo "--- Verifying Status after Reconcile (State = ISSUED) ---"
curl -s -X GET "$BASE_URL/policy-requests/$TIMEOUT_ID" | jq .
