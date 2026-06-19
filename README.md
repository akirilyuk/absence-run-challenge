# The Absence Run

Employees request time off — vacation, sick days, and more. We need a script
that processes the **pending** requests for a period: for each one, check the
employee's remaining entitlement, decide **approve** or **reject**, update their
balance, and post the decision to our HR API.

We've stubbed the repo and seeded a sample period. There are a couple of passing
tests for the basic case. **Make it production-ready.** You may use AI, Google,
and ask us anything.

---

## Setup

You need PHP 8.2+ and Composer. No Docker, no database server — the app uses a
local SQLite file.

```bash
composer install

# Create the database schema and load the sample period
php bin/console doctrine:schema:create
php bin/console doctrine:fixtures:load --no-interaction
```

Start the mock HR API in a second terminal (it stays running):

```bash
php -S 127.0.0.1:8081 mock-hr-api/server.php
```

Then run the script and the tests:

```bash
php bin/console app:absence:run --date=2025-04-15
php bin/phpunit
```

The sample period is the leave year **2025** with a run date of **2025-04-15**.

## Cursor MCP setup

This repo includes a `.cursor/mcp.json` with the local
[**tokensave**](https://github.com/tokensave/tokensave) server and the
[**ToolHive**](https://github.com/stacklok/toolhive/) MCP server configured.
ToolHive runs with the MCP optimizer enabled, so Cursor routes GitHub and
git-read operations through the optimizer before calling the underlying MCP
tools. See the [MCP optimizer setup
guide](https://docs.stacklok.com/toolhive/tutorials/mcp-optimizer) for the
ToolHive steps to enable it.

Example: start the local tokensave server from the repo root:

```bash
tokensave serve
```

## What's in the box

| Path | What it is |
|------|------------|
| `src/Entity/` | `Employee`, `LeaveRequest`, `LeaveBalance` |
| `src/Service/LeaveRequestProcessor.php` | The processor — a deliberately naive first pass. **This is what you'll work on.** |
| `src/Command/AbsenceRunCommand.php` | The `app:absence:run` entry point |
| `src/Hr/` | The HR API client (`HrApiClientInterface` + HTTP implementation) |
| `src/DataFixtures/AppFixtures.php` | The seeded sample period |
| `mock-hr-api/server.php` | A standalone mock of the HR API (Bearer auth + idempotency) |
| `docs/LEAVE_POLICY.md` | **The leave policy.** Read it carefully — it defines what "correct" means. |
| `tests/` | A base test case + three passing happy-path tests |

The HR API credentials and base URL are in `config/services.yaml`.

## What we'd like from you

Work in phases — the thinking matters as much as the code:

1. **`QUESTIONS.md`** — before writing code, read the brief, the policy, and the
   seeded data, and write down your questions and assumptions. Send them over; we'll
   answer.
2. **`SPEC.md`** — turn that into a short spec: the rules you'll implement, the edge
   cases, what's out of scope, and how you'll test it.
3. **Prototype** — spike whatever you find riskiest to convince yourself it'll work.
   Throwaway code is fine here.
4. **Build** — make the processor production-ready, with tests.

Afterwards we'll sit down for ~an hour, walk through your code, and talk about how
you'd run this thing for real.

## Rules of engagement

- **Use AI, search, whatever you like.** We care about what you ship and whether you
  understand it — we'll ask.
- **Ask questions.** A thin brief is intentional. Good questions are a strong signal.
- It's a backend script, so **tests matter**. Extend the suite as you go.
