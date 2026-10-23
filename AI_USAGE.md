# AI Usage Disclosure (TrippersShield Assessment)

This document satisfies the AI disclosure requirement specified in Section 10 of the assessment guidelines. All statements reflect the authentic development process followed for **PolicyFlow**.

---

### 1. Tools Used
- **Primary Coding Assistants:**
  - Antigravity IDE, Claude 3.5 Sonnet, ChatGPT
- **Local Tooling & Environment:**
  - PHP 8.3 CLI, Composer 2, Git, PowerShell / Bash, Curl, PHPUnit 11.

---

### 2. Concrete Ways AI Assisted in Development
1. **Decimal-Safe Pricing Function Design (`bcmath`):**
   - AI helped scaffold the pure `PremiumCalculator` math routines using `bcmath` string operations with scale 6 and half-up rounding, preventing standard IEEE-754 floating-point inaccuracies.
2. **State Machine Transition Matrix & Pessimistic Locking Workflow:**
   - AI assisted in formalizing the allowed state transitions table and the two-phase transaction pattern (releasing the database lock *prior* to slow external HTTP provider calls to avoid starving connection pools).
3. **Automated Test Scaffolding:**
   - AI generated comprehensive test matrices covering boundary conditions (age 17/18, age 70/71, leap years, min 1 travel day, timeout simulation using `Http::fake()`).

---

### 3. Incorrect or Incomplete AI Suggestions Encountered
`[TODO: candidate to confirm / edit with your real experience during execution]`
- **Example Encountered:**
  - Initially, standard AI suggestions recommended wrapping the entire HTTP call and row lock inside a single large database transaction (`DB::transaction`).
  - **Correction Applied:** In high-concurrency environments, holding database row locks during external network calls can exhaust connection pools and cause thread starvation. We refactored this into two atomic transactions:
    1. Transaction 1: Lock row, mark state as `SUBMITTED` (in-flight marker), increment attempt count, and **commit**.
    2. Outside Transaction: Perform the external HTTP call to the provider.
    3. Transaction 2: Re-lock row and apply the provider response (`ISSUED`, `VALIDATED` on temporary failure, or `NEEDS_RECONCILIATION` on timeout).

---

### 4. How the Output Was Tested and Verified
1. **Automated Testing:**
   - Executed full PHPUnit test suite (`php artisan test`) covering 15 required scenarios across unit and feature tests.
   - Verified that `Http::fake()` accurately verified request deduplication (`Http::assertSentCount(1)`).
2. **Database Constraint Verification:**
   - Confirmed that compound unique constraints (`uq_agent_client_request` and unique `provider_event_id`) prevent duplicate inserts even under concurrent race conditions.
3. **Manual CLI Walkthrough:**
   - Executed `examples/curl.sh` to test end-to-end flows:
     - 201 Created on first intake -> 200 Replay on second intake.
     - Simulated 504 Timeout -> transitioned to `NEEDS_RECONCILIATION`.
     - `php artisan policyflow:reconcile` successfully queried provider status and promoted policy to `ISSUED`.
     - Fired duplicate callback 3 times; confirmed only the first applied and subsequent calls returned `duplicate_ignored`.

---

Candidate Signature / Confirmation:
Name: `Md Zishan`
Candidate ID: `12308511`
Date: `2026-10-24`
