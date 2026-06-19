# Questions & Assumptions

**Fill this in before you write code.** Read the brief (`README.md`), the leave
policy (`docs/LEAVE_POLICY.md`), and the seeded data (`src/DataFixtures/AppFixtures.php`),
then capture what you'd want to clarify with the "product owner" (us) and the
assumptions you're making in the meantime.

Send it over — we'll answer your questions. **Good questions are a strong signal**,
so don't hold back. There's no upper limit; the empty bullets below are just a
starting shape, not a quota. We'd expect a senior submission to have ~5–15 across
the three sections.

> All seed arithmetic below was verified against the real weekday calendar and the
> BY/BE public-holiday tables in §5.

---

## Policy constraints (summary)

For reference — these are the rules I read out of `docs/LEAVE_POLICY.md`:

| # | Constraint | Source |
|---|------------|--------|
| C1 | Entitlement base = `contractualLeaveDays`, never below the statutory 20 (5-day week). | §1 |
| C2 | Joiner/leaver pro-rata = `contractual × full_months / 12`, round **up** to half-day. Full month = employed the **whole** calendar month. | §2 |
| C3 | Part-time scaling = `× workingDaysPerWeek / 5`, round **up** to half-day. Compose with pro-rata. | §3 |
| C4 | A consumed day = working day in `[start,end]` − weekends − state public holidays − 0.5 per half-day flag. | §4 |
| C5 | Holidays are state-specific (`federalState`); a holiday on Sat/Sun has no effect. | §5 |
| C6 | Carryover lapses on `carryoverExpiresOn`; if `runDate > expiry`, carryover = 0. | §6 |
| C7 | Deplete still-valid carryover **first**, then current-year entitlement. | §7 |
| C8 | `VACATION` consumes; `SICK`/`UNPAID` never consume; `SPECIAL` always approved, separate allotment. | §8 |
| C9 | Certified `SICK` overlapping an **approved** `VACATION` credits the overlapping **working days** back. | §9 |
| C10 | Reject a request overlapping an already-approved period (incl. approved earlier in the same run). | §10 |
| C11 | Insufficient balance ⇒ reject the **whole** request (no partial approval). | §11 |
| C12 | `CANCELLED` consumes nothing; un-approving previously-approved days returns them. | §12 |
| C13 | Re-runs must be idempotent: no duplicate HR posts, no double deduction. | HR contract / re-run |

---

## Questions

_Things you'd want a real product owner to clarify before you commit to a design,
ordered by how much they'd change the design._

1. **Part-time day counting (biggest gap).** Entitlement is scaled by `workingDaysPerWeek/5`, but §4 counts consumption in plain Mon–Fri working days. We only store the **count** (`3`), not **which** weekdays an employee works. Counting Bjarne's Mon–Fri request as 5 days mixes units (17-day scaled entitlement vs 5-day-week consumption). Should part-timers only consume days on their actual working weekdays — and if so, where does the working-day pattern come from?

2. **Leaver pro-rata.** §2 illustrates a joiner only. For someone with `employmentEndDate` mid-year, do we exclude the partial exit month the same way (full-months-only), and do we cap consumption to dates before the end date?

3. **Composed rounding.** When pro-rata **and** part-time both apply, round once at the very end (`ceil((c × m/12 × d/5) × 2)/2`) or after each factor? Rounding twice can differ by half a day.

4. **Where is the entitlement cap?** `LeaveBalance` stores `carriedOverDays`/`usedDays` but no total. Confirm the processor derives the cap from `Employee` each run rather than reading it from somewhere authoritative.

5. **No carryover sub-ledger.** There's no `usedCarryoverDays` column, yet C7 requires carryover-first depletion. Track the split in-memory per run and only persist into `usedDays`, or do you want a schema change?

6. **Overlap scope by type.** §10 says "leave periods" but §9 explicitly lets `SICK` overlap `VACATION`. Does the overlap rejection apply only between `VACATION` periods, or to any approved type? Do `PENDING`↔`PENDING` overlaps reject the later submission, or only `PENDING`↔`APPROVED`?

7. **Standalone sick / unpaid approval.** §9 only covers sick *during* vacation. A `SICK` with no overlapping approved vacation, or with `medicalCertificate=false` — always approved record-only? Any rejection path?

8. **Sick-credit ordering.** Credit-back can free up balance. If a pending vacation and a credit-bearing sick note are in the same run, must sick notes be applied **before** vacations (regardless of `submittedAt`) so the freed days are usable?

9. **Half-day semantics on edges.** If `halfDayStart` falls on a weekend/holiday boundary day, does it apply to the first actual working day or is it ignored? If `start==end` with both flags, is that 0.5, an error, or 0? Do half-day flags mean anything on `SICK`/`UNPAID`/`SPECIAL`?

10. **`SPECIAL` allotment.** §8 says a *separate* allotment, but there is no such field on any entity. Confirm "always approved, balance untouched, `days`=working-day count" is acceptable, or should a special balance be modelled?

11. **HR `days` field meaning.** For `REJECTED`/`SICK`/`UNPAID`/`SPECIAL`, is `days` always `0`, or the working-day count of the range (for reporting), even when no vacation balance moves?

12. **Idempotency key shape & skip rule.** Deterministic key per request (e.g. `absence-run-{requestId}`)? On re-run, skip anything not `PENDING`, or re-post and rely on the API's replay behaviour?

13. **Unknown `federalState`.** Only BY/BE are tabulated. For another state code, which do you want:
    (a) **hard fail** the run, (b) treat as **zero regional holidays** with a warning, or (c) **extend the table with the full set of holidays for every German state**? Option (c) is the most correct long-term but is the most data to source and maintain; (b) keeps the run going but will over-count leave in states with extra holidays. My current default is (b) until we commit to (c).

14. **Requests crossing 31 Dec / the year boundary.** Default plan: attribute the days up to 31 Dec to the **`startDate` year's** balance, and the days from 1 Jan to the **new year's** balance. The open policy question is the **spillover**: e.g. a request `20.12 → 05.01` where the employee has only **3 days left in the old year** but the pre-31-Dec portion needs more — should the run (a) **reject the whole request** (C11, treating each year's portion against its own balance), (b) **let the old-year shortfall draw from the new year's balance**, or (c) **split into two decisions** (approve the part each year can cover, reject the rest)? This needs a ruling because C11 says no partial approval, which (c) would violate.

15. **Unknown / out-of-employment dates.** Should a request with dates before `employmentStartDate` or after `employmentEndDate` be rejected, or is that assumed impossible in the input?

---

## Assumptions I'm making (until told otherwise)

_The defaults you're picking so you can keep moving. State each one — if we
disagree we'll tell you, and if we don't, your code shows your reasoning._

- Entitlement is **derived at runtime** from `Employee` (contractual, employment dates, `workingDaysPerWeek`); `LeaveBalance` only holds carryover + consumption.
- **Part-time consumption is counted in plain working days** (Mon–Fri − holidays) for now, because the working-weekday pattern isn't in the data — this is the riskiest assumption (Q1).
- Composed pro-rata/part-time factors are **multiplied, then rounded up once** to the nearest half-day (`ceil(x * 2) / 2`).
- `runDate` (the `--date` option) drives carryover expiry (C6) and is stamped as `decidedAt`; all `PENDING` rows are eligible regardless of date.
- Processing order is `submittedAt ASC, id ASC` (as the repository already does); **sick credit-back is applied before vacation decisions** in the same run so freed days are usable, and overlap rejections consider requests approved earlier in the same run.
- Overlap rejection (C10) is evaluated against **approved `VACATION`** periods; `SICK` may overlap (C9); among two pending vacations the earlier `submittedAt` wins and the later is rejected.
- Standalone `SICK`/`UNPAID` are **approved, record-only** (`consumedDays=0`); a `SICK` without a certificate is still approved but triggers no vacation credit; `SPECIAL` is always approved, balance untouched.
- Sick credit-back reduces `usedDays`; carryover-first depletion is tracked in memory and never drives `usedDays` below the post-credit floor.
- Re-runs **skip non-`PENDING`** requests and use a **deterministic idempotency key** per request (e.g. `absence-run-{requestId}`); `externalReference` is persisted from the first successful post.
- HR `days` = working days consumed for approved vacation; **`0`** for rejected/sick/unpaid/special.
- Holidays come from a hard-coded 2025 BY/BE map; unknown state ⇒ no regional holidays + warning, not a crash (pending the Q13 ruling).
- Balance year = `startDate.format('Y')`; cross-year spillover is unresolved (Q14) — default is to attribute each portion to its own year's balance and reject if either year is short.

---

## Things in the data that look surprising (verified)

_Anything in the seeded period that smells off, contradicts the policy, or
seems to be missing a value. Flag it; we'll decide if it's a bug in the seed
or a real scenario we want you to handle. Working days are computed against the
real calendar and the §5 holiday tables._

- **Eva Klein — holiday lands *inside* the requested range.** Vacation `2025-06-05 → 2025-06-11` (Berlin), `halfDayStart`. Days: Thu 5, Fri 6, **Mon 9 = Whit Monday (BE holiday)**, Tue 10, Wed 11 (7–8 are weekend). So **4 working days − 0.5 = 3.5**, not 5. Entitlement 28, used 5 ⇒ approves. This is the single best holiday-counting test case in the seed.
- **Felix Wolf — `usedDays = 0` means a FULL balance, plus an Ascension holiday in range.** First vacation `2025-05-26 → 2025-05-30` (Berlin) contains **Thu 29 = Ascension (BE holiday)** ⇒ **4 working days**, and with all 28 days free it **APPROVES**. The second vacation `2025-05-28 → 2025-06-03` overlaps 28–30 ⇒ **rejected on overlap (C10)**, not on balance. (`usedDays=0` is zero *used*, i.e. a full balance — not zero available.)
- **Anna Becker — carryover already lapsed at run time.** Carryover 6 days expired `2025-03-31`, run date `2025-04-15` ⇒ treat as 0. With used 24 / contractual 28, remaining ≈ 4. Vacation `05-19→05-23` (5 days, submitted first) **rejects**; `04-28→04-30` `halfDayStart` (**2.5 days**) **approves**. `SPECIAL 06-02` always approves.
- **Carla Roth — joiner with little headroom.** Joined `2025-03-01` ⇒ 10 full months ⇒ `30 × 10/12 = 25` entitlement. Used 21 ⇒ ~4 left. Vacation `07-07→07-11` (5 working days, no BE holiday) **rejects**. Confirm the seeded `usedDays=21` is intentional (no underlying approved requests are in the fixture).
- **Dilan Yilmaz — sick-during-vacation credit.** Approved March vacation `03-17→03-28` = exactly **10 working days** (= seeded `usedDays`). Pending certified `SICK 03-24→03-26` (Mon–Wed, no holiday) overlaps it ⇒ **credit 3 working days back** ⇒ used 10 → 7. There is no later pending vacation to actually use the freed days, so the credit's effect is balance-only this run.
- **Bjarne Vogt — part-time entitlement vs consumption mismatch.** `28 × 3/5 = 16.8 → 17` entitlement; `usedDays=14` (from the balance seed), leaving **~3** days. Vacation `07-07→07-11` is 5 Mon–Fri days. If counted as 5 plain working days he **rejects**; if it should only count his actual 3 working weekdays it could **approve** — but we don't know which weekdays he works, so the consumed figure is ambiguous (Q1).
- **Eva — cancelled-but-never-approved + unpaid.** Feb vacation is `CANCELLED` (never `APPROVED`), so C12 "return the days" is a no-op; `usedDays=5` originates elsewhere. `UNPAID 05-05→05-09` must be recorded with **no** balance impact.
- **Structural gaps in the seed:** no employee has `employmentEndDate` (leaver pro-rata untested), no non-BY/BE state, no cross-year request, and no entity field for the `SPECIAL` allotment referenced in §8.
