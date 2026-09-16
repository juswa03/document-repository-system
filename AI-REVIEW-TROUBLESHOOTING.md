# "No AI suggestions" on a single document — troubleshooting

You ran AI review on one uploaded document and got:

> No AI suggestions — the layer may be switched off, or analysis is still running.

...even though **Test Connection** in **AI settings** succeeds. This can also
show up on one machine but not another, with the same code checked out on
both.

For general "the whole layer looks off" setup and checks (toggle, API key,
provider/model match, config cache), see [AI-SETUP.md](AI-SETUP.md) first.
This file covers the specific case where the connection genuinely works but
a document still ends up with zero suggestions.

---

## Why Test Connection can pass while this still happens

**Test Connection** does one synchronous round trip to the AI provider, on
demand, the moment you click it. It only checks that the layer is turned on
and the API key is valid for the selected provider
(`AiSettingController::test()` → `AiProvider::healthCheck()`).

**Actual document analysis is different.** When a document is uploaded or
resubmitted, the app queues two background jobs
(`ExtractDocumentText`, `AnalyzeDocument`) instead of running them
immediately. With this project's default `QUEUE_CONNECTION=database`, those
jobs just sit as rows in the `jobs` table until a **separate worker
process** picks them up and runs them. `php artisan serve` does **not**
process queued jobs by itself.

So Test Connection can succeed while analysis for a specific document
never actually ran, or ran but was blocked by something Test Connection
doesn't check at all (a disabled capability, the spend cap). The frontend
can't tell these cases apart either — [AiSuggestionPanel.jsx](frontend/src/components/AiSuggestionPanel.jsx)
shows the same generic message any time the suggestions list comes back
empty, whether that's because the job hasn't run, was blocked, or failed.

This is also why it can differ between two machines running identical
code: whether a queue worker happens to be running is a per-machine,
per-terminal state, not something stored in the repo or `.env`.

---

## Quick fix — try this first

From `backend/`:

```bash
php artisan documents:reanalyze <tracking_no>
```

Or to catch every document that currently has zero AI suggestion rows:

```bash
php artisan documents:reanalyze --missing --dry-run   # preview only
php artisan documents:reanalyze --missing              # actually re-run
```

This command runs the analysis **synchronously**, in the current process —
it bypasses the queue entirely, so it will work even if no worker is
running. It exists specifically for this situation (added after the same
symptom was hit before — see the "Related history" note below).

If this fixes it, the underlying cause is almost certainly the queue
worker not running (next section) — the quick fix just doesn't prevent it
from happening again on the *next* upload.

---

## Checklist, most likely cause first

### 1. The queue worker isn't running on this machine

Queued jobs need a worker process actively consuming them:

```bash
cd backend
php artisan queue:work
```

(or `queue:listen` if you want it to pick up code changes without
restarting). This has to be running continuously, in its own terminal,
alongside `php artisan serve`. Nothing in this repo starts it
automatically — there's no Windows service, no second process in the
Dockerfile, no scheduled task for it. If one laptop has a terminal running
`queue:work` (maybe left over from a previous session) and the other
doesn't, that alone fully explains "works here, not there."

**To check without guessing:**

```bash
php artisan tinker
>>> DB::table('jobs')->count();          // queued, not yet processed
>>> DB::table('failed_jobs')->count();   // processed, but errored
```

A growing `jobs` count with analysis never completing means nothing is
consuming the queue. A non-zero `failed_jobs` count means jobs *are* being
picked up but are erroring — check `backend/storage/logs/laravel.log` for
the exception.

### 2. A capability is switched off for this document's analysis

Each AI capability (`classification`, `summary`, `metadata`,
`completeness`, `confidentiality`, etc.) can be toggled individually in
**AI settings**, independent of the master on/off switch. Test Connection
only checks the master switch and the key — not these. If the specific
capability a document needed is off, that part of the analysis produces
nothing, which can look identical to "nothing ran at all."

### 3. The monthly spend cap has been reached

Once estimated spend for the month reaches `ai_monthly_cap_usd`, the
layer stops calling the provider for the rest of the month. This fails
silently from the user's point of view — it only writes an
`ai_spend_cap_reached` audit entry, no error banner. Check current spend
against the cap on the AI settings screen.

### 4. The two machines have different databases, and settings live in the database

On/off, provider, model, spend cap, and capability toggles are all stored
in `system_settings` in the database — **not** in `.env`. Two machines
with separate local databases can have different effective AI settings
even with byte-identical `.env` files and both passing Test Connection.
See the "one thing that trips everyone up" section in
[AI-SETUP.md](AI-SETUP.md#the-one-thing-that-trips-everyone-up) — confirm
both machines actually agree on what's in `system_settings`, not just
what's in `.env`.

### 5. A one-off connection failure during that specific analysis run

The provider call inside the queued job can fail transiently (a TLS blip,
a timeout) even when the provider is generally reachable. There's
automatic retry for this on the OpenAI-compatible/Groq provider path; if
you're on Anthropic it relies on the SDK's own default retry only. Either
way, `documents:reanalyze <tracking_no>` (above) recovers from it.

### 6. It's genuinely still processing

If you check the suggestions panel immediately after upload, the queued
job may simply not have run yet. Confirm a worker is running (step 1) and
give it a few seconds, then refresh.

---

## Not the cause

A recent commit ("Phase 38") fixed a CORS gap on `/broadcasting/auth`.
That's part of the live-notification (Reverb/Echo) system, unrelated to
the AI suggestions endpoint — don't spend time chasing it for this issue.

---

## Where to look

- `backend/storage/logs/laravel.log` — exceptions thrown while a queued
  job was processing.
- `failed_jobs` table — jobs that were picked up but errored out.
- `jobs` table — jobs queued but not yet processed (stuck here usually
  means no worker is running).
- AI settings screen (admin) — master toggle, provider/model, per-capability
  switches, current spend vs. cap.
