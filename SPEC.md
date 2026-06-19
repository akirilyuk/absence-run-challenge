# Spec — The Absence Run

A short, living document. Keep it scoped and testable — it doesn't need to be long.

## Problem

Process all pending leave requests as of a run date, decide approve/reject per
leave policy, update vacation balances, and post each decision to the HR API
idempotently.

## Rules I'm implementing

_The concrete rules you'll handle, from the policy and your clarified questions.
Be specific — these become your test cases._

- Compute annual entitlement from `Employee.contractualLeaveDays`, applying
  pro-rata for joiners/leavers (full months only) and part-time scaling
  (`workingDaysPerWeek / 5`), rounding **up** to the nearest half day.
  The leave year is the calendar year of the request dates.
- Count consumed days as working days in `[startDate, endDate]` minus weekends,
  minus state public holidays, minus 0.5 per half-day flag.
- Carryover expires on `carryoverExpiresOn`; if `runDate` is after expiry,
  carryover is zero.
- Deplete **valid** carryover first, then current-year entitlement.
- `VACATION` consumes balance; `SICK` and `UNPAID` never consume; `SPECIAL` is
  always approved and never touches vacation balance.
- Certified `SICK` overlapping an **approved** `VACATION` credits the overlapping
  working days back to the vacation balance.
- Reject any request that overlaps an already-approved period (including one
  approved earlier in the same run).
- Reject requests that would exceed the remaining balance (no partial approval).
- `CANCELLED` requests are inactive and consume nothing.
- Post all decisions to HR with a deterministic idempotency key; do not
  double-deduct balances on re-run.

## Edge cases

_The tricky inputs you've decided how to handle, and why._

| Case | Decision |
|------|----------|
| Carryover expired before run date | Treat carryover as 0 for the run. |
| Half-day start/end flags | Subtract 0.5 per flag from the working-day count. |
| Overlapping pending vacations | Approve earlier (by `submittedAt`), reject the later as overlapping. |
| Certified sick overlapping approved vacation | Credit back overlapping working days and record sick as approved. |
| Unknown `federalState` | Default: no regional holidays + warning (pending decision). |
| Requests crossing year boundary | Split counting by year; reject if either year's balance is short (pending decision). |
| Leaver mid-month | Count the exit month as a **full month** even if the employee leaves mid-month (explicit decision). Example: leave on 2025-06-10 ⇒ June counts as a full month. |

## Out of scope

_What you're deliberately not doing, and why that's OK for now._

- A full per-state German holiday calendar beyond BY/BE (policy only lists BY/BE).
- Tracking a separate persisted special-leave balance (policy treats it as always
  approved for this exercise).
- Choosing exact part-time working weekdays (not represented in the data model).

## Test plan

_How you'll prove it works. Which scenarios get a test?_

- Base cases: approve/reject vacation within/outside balance.
- Weekend/holiday exclusion: Felix (Ascension), Eva (Whit Monday).
- Half-day handling: Anna (half-day start), Eva (half-day start).
- Carryover expiry: Anna (expired carryover before run date).
- Sick credit-back: Dilan (certified sick overlapping approved vacation).
- Sick without certificate: sick request approved, no vacation credit.
- Overlap rejection: Felix’s overlapping vacations.
- Cancellation behavior: Eva’s cancelled February request (no balance impact).
- Re-run idempotency: same request processed twice posts once and doesn't double-use.
- HR API failure + retry: failed post leaves request `PENDING`; second run posts
  once (idempotency key reused) and applies balance exactly once.
- Joiner mid-year pro-rata: join on 2025-03-10 ⇒ full months = Apr–Dec = 9; entitlement = `contractual × 9/12`, round up to half-day.
- Leaver mid-year pro-rata: leave on 2025-06-10 ⇒ full months = Jan–Jun = 6 (exit month counts as full), entitlement = `contractual × 6/12`, round up.
- Join + leave mid-year: join on 2025-03-10, leave on 2025-10-12 ⇒ full months = Apr–Oct = 7 (start partial month excluded, exit month included), entitlement = `contractual × 7/12`, round up.
- Joiner front-load usage (policy gap): join on 2025-03-10 with pro-rated entitlement, request immediately consumes the full pro-rated amount (e.g., 2025-03-15 onward) — passes under upfront entitlement logic; would fail under monthly accrual.

## Operational notes

_How this behaves on a re-run, on partial failure, on bad data. What you'd want to
monitor if this ran every night._

- Each request uses a deterministic idempotency key; re-runs skip non-`PENDING`
  requests and never double-deduct balances.
- If an HR API call fails, the transaction should not mark the request as
  decided; the request remains `PENDING` for the next run.
- Log decisions with request IDs, consumed days, and reasons; alert if a run
  fails to post to HR.

## Production readiness checklist

_Batch CLI focus (no long-running service or health endpoint)._

- Ensure request decision + balance update + HR post are atomic or clearly
  retry-safe (no partial balance updates).
- Persist or derive a deterministic idempotency key; skip non-`PENDING` requests
  on re-runs to avoid duplicates.
- On HR API failure, keep request `PENDING` and avoid balance changes.
- Log per-request decisions with employee/request IDs, consumed days, and reasons.
- Emit a run summary (approved/rejected/error counts, duration).
- Validate configuration at startup (DB connection, HR base URL, auth token).
- Guard against bad input (start > end, unknown state handling, out-of-employment
  dates, cross-year policy).
- Add a lightweight linter/static analyzer for PHP (e.g., PHPStan or Psalm) to
  catch type and logic errors early.
- Add a formatter (PHP-CS-Fixer) to keep diffs consistent and reviewable.
- Add a minimal CI job to run tests + linter on push.

## Open questions

_Anything still unresolved._

- How to count leave consumption for part-time employees without weekday
  schedules (count all Mon–Fri vs only working weekdays).
- How to handle unknown federal states: fail, assume no regional holidays, or
  expand the holiday table.
- Cross-year requests: whether to allow new-year balance to cover old-year
  shortfalls or reject the whole request.
- Whether `SPECIAL` should be unlimited/auto-approved or capped by a separate
  entitlement that is missing from the current data model.
