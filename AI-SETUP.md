# Configuring the AI agent layer

The repository works fully without AI. Every AI feature is an assist that
degrades to manual entry when the layer is off, unreachable, or over its
spend cap — nothing in the submission or review workflow blocks on it.

This guide covers turning it on, and what to check when it looks dead.

---

## The one thing that trips everyone up

Configuration lives in **two places**, and they are not interchangeable:

| Setting | Where it lives | Who changes it |
|---|---|---|
| **API key** | `.env` only | A developer / server admin |
| On/off, provider, model, spend cap, confidence, capabilities | **Database** (`system_settings`) | A system admin, in the web UI |

The database wins. `AiSettings::fromCurrent()` reads the provider from
`system_settings` and only falls back to `config/ai.php` when that column
is empty.

**So setting `AI_PROVIDER=groq` in `.env` does nothing on its own.** If the
admin screen still says `anthropic`, the app looks for
`ANTHROPIC_API_KEY` — and if that's blank, the layer stays inert no matter
what else you configured.

Change the provider **in the admin screen**, not in `.env`.

The API key is the deliberate exception: it is never written to or read
from the database, and the admin screen only ever reports whether a key is
*present*, never its value.

---

## Option A — Groq (free, no card)

Good enough for a demo or a thesis defence, and costs nothing.

1. Get a key at <https://console.groq.com> → **API Keys**.

2. In `backend/.env`:

   ```dotenv
   AI_ENABLED=true
   GROQ_API_KEY=gsk_your_key_here
   ```

3. Restart the backend so the new environment is read:

   ```bash
   php artisan config:clear
   php artisan serve
   ```

4. Sign in as a **system admin** → **AI settings** in the sidebar.
   Set **Provider** to `groq`, pick a model, and **Save**.

5. Press **Test connection**.

### Choosing a Groq model

Groq rotates its hosted models often, and the suggestion features depend
on the model reliably honouring a *forced tool call*. As of September 2026
the `gpt-oss` models on Groq frequently refuse one; the Qwen models work.

Confirm what your key can actually reach:

```bash
curl -H "Authorization: Bearer $GROQ_API_KEY" \
     https://api.groq.com/openai/v1/models
```

If a model isn't in `config/ai.php` under `providers.groq.models`, add it
there — the admin screen only offers models listed in config, and the
server rejects anything else.

---

## Option B — Anthropic (paid, most reliable)

1. Get a key at <https://console.anthropic.com>.

2. In `backend/.env`:

   ```dotenv
   AI_ENABLED=true
   ANTHROPIC_API_KEY=sk-ant-your_key_here
   ```

3. `php artisan config:clear`, restart, then in **AI settings** set
   Provider to `anthropic`, choose a model, Save, and Test connection.

Models available out of the box, cheapest first:

| Model | $/1M in | $/1M out |
|---|---|---|
| `claude-haiku-4-5` | 1.00 | 5.00 |
| `claude-sonnet-5` | 2.00 | 10.00 |
| `claude-opus-5` | 5.00 | 25.00 |

Haiku is the default and is more than adequate here — the prompts are
short and the outputs are structured.

---

## Option C — A local model (Ollama, LM Studio, vLLM)

No key, no cost, no data leaving the machine. Slower, and small local
models are the least reliable at forced tool calls.

```dotenv
AI_ENABLED=true
OPENAI_COMPAT_BASE_URL=http://localhost:11434/v1
OPENAI_COMPAT_API_KEY=not-needed
```

Then set Provider to `openai_compatible` in the admin screen. Add whatever
model ids you plan to use under `providers.openai_compatible.models` in
`config/ai.php`.

---

## Verifying it works

**In the UI:** AI settings shows a status line. "On, but the provider is
not reachable" means the toggle is on but `isConfigured()` is false —
usually a missing key for the *selected* provider, or a model the provider
doesn't serve.

**Test connection** does a real round-trip and reports the provider's own
error message, which is normally specific enough to act on
(`invalid_api_key`, `model_not_found`, a connection refusal).

**From the command line**, to see exactly what the app resolves:

```bash
cd backend
php artisan tinker
>>> App\AI\AiSettings::fromCurrent();
>>> app(App\AI\Contracts\AiProvider::class)->isConfigured();
```

If `isConfigured()` is `false`, work through the checklist below.

---

## When it looks dead

Checked in this order, because each depends on the one before it:

1. **Is the toggle on?** `system_settings.ai_enabled`, set in the admin
   screen — not `AI_ENABLED` in `.env`, which is only the initial default.

2. **Does the key match the selected provider?** This is the common one.
   Provider `anthropic` reads `ANTHROPIC_API_KEY`; `groq` reads
   `GROQ_API_KEY`. A key for the *other* provider is invisible to it.

3. **Did you clear the config cache?** Laravel caches `.env` into
   `config/`. A new key does nothing until `php artisan config:clear`, and
   the running `php artisan serve` process must be restarted.

4. **Is the model one the provider serves?** The admin screen only offers
   what's in `config/ai.php`, but that list can drift out of date —
   especially on Groq.

5. **Is the spend cap reached?** Once estimated monthly spend exceeds
   `ai_monthly_cap_usd`, the layer stops calling the provider for the rest
   of the month and writes an `ai_spend_cap_reached` audit entry. Current
   spend is shown on the AI settings screen.

6. **Is the capability switched off?** Each analysis can be disabled
   individually. `near_duplicate` is deterministic and runs even with the
   whole layer off — it costs nothing and makes no provider call.

---

## What the layer actually does

Nine capabilities, all suggest-only. **Nothing an AI produces is ever
applied to a document until a human accepts it** — that's a hard rule
(BR-03), enforced in `AiSuggestionController` and covered by tests.

| Capability | What it does |
|---|---|
| `classification` | Suggests category and document type |
| `metadata` | Proposes tidier title, period, keywords, description, date, owning office |
| `completeness` | Flags gaps a reviewer should look at |
| `confidentiality` | Judges whether the stated access level fits the content |
| `summary` | Drafts a document summary |
| `near_duplicate` | Text-similarity duplicate detection (**free, no provider call**) |
| `search` | Parses natural-language repository queries into filters |
| `report_narrative` | Drafts a cover note over a report's own figures |

Two places show suggestions:

- **Before submitting** — the uploader sees duplicate/version findings and
  classification suggestions, and accepts or overrides each one.
- **During review** — the office admin sees the same layer's output in the
  review queue and accepts or dismisses each suggestion.

---

## Cost control

- **Monthly cap** (`ai_monthly_cap_usd`, default $20). A hard stop, not a
  warning. Estimated from token counts and the per-model rates in
  `config/ai.php` — indicative for budgeting, not a billing record.
- **Per-capability switches.** Turning off `summary` alone removes one
  provider call per uploaded document.
- **Confidence threshold** (default 0.6). Low-confidence suggestions are
  stored but not surfaced as recommendations.

Every provider call is audited: `ai_suggestion_created`,
`ai_suggestion_accepted`, `ai_suggestion_dismissed`,
`ai_spend_cap_reached`, `ai_settings_updated`, `ai_settings_tested`.

---

## Security notes

- **The key never reaches the database or the browser.** It is read from
  the environment at request time; the admin API reports only
  `key_present: true|false`.
- **Only document metadata is sent by default** — title, type, period,
  keywords, description, category, access level. The `summary` capability
  additionally sends extracted document text.
- **Do not commit `.env`.** If you push this repository anywhere, confirm
  `.env` is git-ignored and rotate any key that has ever been committed.
- For a local-only setup with no data leaving the machine, use **Option C**.
