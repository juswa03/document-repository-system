# Frontend Consistency & Enhancement Plan

**Scope:** `frontend/src` — React 18 + Vite, plain CSS with a custom-property design-token layer.
**Method:** `redesign-skill` audit + Open/Closed Principle (OCP) + WCAG 2.1 AA / Nielsen heuristics.
**Guiding rule:** *extend* the token layer and shared primitives; do not rewrite pages. Components consume tokens and shared classes — they never re-declare visual values.

---

## 1. What's already good (keep and build on)

- `src/styles/tokens.css` is a real single-source-of-truth: one cool-gray family, one desaturated blue, tinted shadows, a documented radius scale. This is the backbone — the plan hardens it, it does not replace it.
- `dashboards.css` has a coherent component vocabulary: `.btn`, `.panel`, `.data-table`, `.badge`, `.dash-input`, `.filter-bar`, `.modal-*`.
- `DashboardShell` is a proper layout primitive; 24 pages route through it. Semantic landmarks (`<header>`, `<main>`, `<aside>`, `<nav>`) are used.
- `StatusBadge` already centralises status → label + class mapping (good OCP pattern — new statuses extend the config object).
- `prefers-reduced-motion` is respected globally.

---

## 2. Findings — inconsistencies

### 2.1 Colour / accent — HIGH

| # | Problem | Evidence | Skill rule |
|---|---------|----------|------------|
| C1 | **Two accent colours across auth flows.** `Login.css` submit button + focus ring = `--primary` (blue). `AuthLayout.css` (`ForgotPassword`, `ResetPassword`) submit button, links, focus ring = `--seal` / `--seal-deep` (green). Same product, two identities. | `Login.css:248` `background: var(--primary)` vs `AuthLayout.css` `background: var(--seal)`, `.auth-link` green, `.auth-input:focus` green ring | "More than one accent color. Pick one." |
| C2 | **67 raw hex literals in component CSS** (30 in `dashboards.css`, 17 in `NotificationBell.css`, 14 in `Login.css`, 3 each in `Sidebar.css` / `AdminOverview.css`). Tokens file explicitly says "components read these, never raw hex." | `.badge--approved { background:#dcfce7; color:#16a34a }`, `.data-table tbody tr:hover { background:#f8fafc }`, `.field input::placeholder { color:#94a3b8 }` | "Mixing… stick to one gray family / tint consistently" |
| C3 | **Status colours defined twice, differently.** `tokens.css` has `--success #1f7a44`, `--danger #c0362c`, `--warning #b26b00` with `-soft` backgrounds. `dashboards.css` badges use a *different* unrelated set — `#16a34a`, `#dc2626`, `#d97706`, `#dbeafe/#1d4ed8`. A "rejected" badge and a "rejected" notice are not the same red. | `dashboards.css:213-241` vs `tokens.css:61-66` | inconsistent status semantics |
| C4 | **Login left panel is a blue AI-gradient** (`linear-gradient(135deg,#1e3a8a,#2563eb)`) using colours that exist nowhere else in the system. | `Login.css:11` | "Purple/blue AI gradient aesthetic… replace" |

### 2.2 Buttons — HIGH

| # | Problem | Evidence |
|---|---------|----------|
| B1 | **No focus-visible ring on `.btn`.** Keyboard users get no visible focus on *any* dashboard button (approve, reject, claim, pager, tabs). Only `Login.css .submit-btn` and `.ghost-toggle` have one. **WCAG 2.4.7 failure.** | `grep :focus dashboards.css` → none |
| B2 | **No `:active` / pressed state on `.btn`.** Only `.submit-btn` has `translateY(1px)`. 28 of 30 transitions in the codebase are `0.15s` but there's 1 `:active` rule total. | `dashboards.css:244-298` |
| B3 | **Three parallel button systems.** (a) `.btn .btn--primary/outline/danger-outline` (dashboards), (b) `.submit-btn` (login), (c) `.auth-submit` (auth layout), (d) bare `<button>` with inline `style={{}}` or icon-only with no class — `NotificationBell` (3×), `Pager` (2×), `AiSuggestionPanel` (2×), `ReportRunner`. Each re-implements padding, radius, hover. | `grep "<button" \| grep -v className` → 30+ hits |
| B4 | **`.modal-close` and `.sidebar-toggle` and `.bell-btn` are each hand-rolled icon buttons** with their own sizing/hover/focus (or lack of it). No shared `.icon-btn`. |
| B5 | **`.btn` has no `:focus`, but `.tab-btn` (also a button) has no hover or focus either** — `dashboards.css:597`. Tabs give zero interactive feedback until `.is-active`. |
| B6 | **Destructive actions are inconsistent.** `ManageObjectives`, `ManageRequiredDocuments`, `SystemSettings` use `window.confirm()`. `ReviewQueue` reject uses an inline remarks form. No shared confirm affordance. Skill: "Do not use `window.alert()`." |

### 2.3 Layout / spacing — MEDIUM

| # | Problem | Evidence |
|---|---------|----------|
| L1 | **`100vh` everywhere (6 files), never `100dvh`.** iOS Safari viewport jump. | `index.css:35`, `dashboards.css:2,8`, `Sidebar.css:6`, `Login.css:2`, `AuthLayout.css:2` |
| L2 | **133 inline `style={{}}` occurrences across 30 files** — one-off `marginTop`, `maxWidth`, `width:'auto'`, `color:'var(--text-label)'`, `padding`. Ad-hoc spacing defeats vertical rhythm and can't be themed. Skill: "Inline styles mixed with CSS classes — move to the styling system." |
| L3 | **No spacing scale.** Values are freehand: `0.35rem, 0.4rem, 0.45rem, 0.6rem, 0.7rem, 0.75rem, 0.82rem, 0.85rem, 0.95rem, 1.1rem, 1.15rem, 1.2rem, 1.25rem, 1.3rem, 1.5rem, 1.6rem, 1.7rem, 1.9rem, 2rem, 2.25rem, 2.5rem…`. Tokens define radius/shadow but **no `--space-*`**. |
| L4 | **`radius: 50%` and `999px` literals 14×** instead of `--radius-full`. `tokens.css:53` defines it; almost nothing uses it. |
| L5 | **Panel padding is asymmetric-by-accident:** `.panel { padding: 1.5rem 1.6rem 1.7rem }` — three different values, not an intentional optical adjustment, just drift. |
| L6 | **`.page-body { max-width: 72rem }`** but `DocumentRepository`, `AdminOverview`, `Reports` render wide tables/charts that fight the constraint; no `--container-wide` escape hatch. |
| L7 | **Transition durations unstandardised:** `0.05s, 0.12s, 0.14s, 0.15s, 0.18s, 0.6s` all appear. No `--transition-*` token. |

### 2.4 States — MEDIUM

| # | Problem | Evidence |
|---|---------|----------|
| S1 | **Loading = plain text.** `"Loading the queue…"`, `"Loading…"`, `.loading-text`, `.route-loading`. No skeletons matching table/card shape. | `ReviewQueue.jsx:273`, `App.jsx:37` |
| S2 | **Empty states are one bare `<td>`** — `"Nothing here."`, `"No items match that category."`. No composed empty state (icon + message + primary action). | `dashboards.css:195` `.empty-row`, `ReviewQueue.jsx:291` |
| S3 | **Error surface is split:** `.error-banner` (dashboards), `.form-error` (login), `.auth-error` (auth layout), plus `err?.response?.data?.message` rendered raw in some pages. Three visual treatments for "something failed." |
| S4 | **No custom 404** — `App.jsx:260` `<Route path="*" element={<Navigate to="/" replace />} />` silently bounces. Skill: "No custom 404 page." |
| S5 | **Success messages inconsistent** — `.success-banner` (dashboards) vs `.auth-success` (auth). |

### 2.5 Component patterns — LOW/MEDIUM

| # | Problem | Evidence |
|---|---------|----------|
| P1 | **Generic card look repeated 4×** with slightly different values: `.panel`, `.stat-card`, `.ai-sugg-card`, `.auth-card`, `.modal-card`, `.bell-dropdown` each = `background + 1px border + radius + shadow` but with different radius/shadow/padding tokens. Should be one `.surface` with modifiers. |
| P2 | **`StatCard` takes an `accent` prop and applies it via inline `style={{ borderLeft: '3px solid ' + accent }}`** — colour passed from JS instead of a variant class. Not OCP-friendly (every new accent = new caller-supplied hex). |
| P3 | **Modal used for everything** including simple confirms; `redesign-skill` suggests inline/slide-over for simple actions, but at minimum the confirm path should be consistent (see B6). |
| P4 | **Lucide icons exclusively** — skill notes this is the "default AI" set. Low priority for an internal tool; note only. |
| P5 | **Badge is always a pill** (`border-radius:999px`); `.chip` too. Fine, but `--radius-full` should gate it so it's a one-line change later. |

### 2.6 Code quality — MEDIUM

| # | Problem | Evidence |
|---|---------|----------|
| Q1 | Inline styles (L2) — 133×. |
| Q2 | **`var(--x, #hardcoded-fallback)` littered through JSX** — `'var(--border, #e2e8f0)'`, `'var(--danger, #a1442f)'`, `'var(--content-bg, #f8fafc)'`. The fallbacks don't even match the real token values (`--danger` is `#c0362c`, not `#a1442f`). Dead + misleading. | `AiSettings.jsx:148`, `Governance.jsx:102`, `AuditLog.jsx:255` |
| Q3 | **`z-index` ad hoc:** `modal-overlay:100`, `bell-dropdown:50`, `perforation:2`. No scale token. |
| Q4 | Trailing empty rule / stray whitespace — `dashboards.css:12` (`.app-main` has a blank line + trailing space inside the block). Minor. |
| Q5 | **`.badge--active`/`--inactive` (blue/gray) live next to `--pending`/`--approved`/etc.** — two unrelated badge taxonomies (row status vs. record status) in one namespace with no naming distinction. |

### 2.7 Accessibility (WCAG 2.1 AA)

| # | Problem | Criterion |
|---|---------|-----------|
| A1 | No visible focus on `.btn`, `.tab-btn`, `.badge`-links, `.sidebar-toggle`, `.modal-close`, pager buttons. | **2.4.7 Focus Visible** |
| A2 | `.data-table tbody tr:hover` only — no `tr:focus-within`; row action discoverability is mouse-only. | 2.1.1 |
| A3 | Modal: `Modal.jsx` has `role="dialog"` + `aria-modal` + `aria-label` (good) but **no focus trap, no Escape handler, no focus restore** on close. | 2.1.2 No Keyboard Trap (inverse), 2.4.3 Focus Order |
| A4 | Icon-only buttons: `NotificationBell` bell has label; check `Pager` arrows, `.modal-close` (`aria-label="Close"` ✓), `sidebar-toggle` (✓). Pager needs audit. | 4.1.2 |
| A5 | Colour-only status signalling in badges (text present ✓) — OK, but `Governance.jsx:102` conveys "overdue" **only** via `color: var(--danger)` on a `<td>`. | **1.4.1 Use of Color** |
| A6 | No "skip to content" link. | 2.4.1 Bypass Blocks |
| A7 | `window.confirm` for destructive actions is screen-reader-announced but not stylable and inconsistent with the rest of the UI (UX, not strict AA). | — |
| A8 | Contrast: verify `--ink-soft` (`#64748b`) on `--paper` = 4.6:1 ✓; `.cell-muted` `#94a3b8` on white = **2.8:1 — FAILS** for the 0.82rem text it's used on. | **1.4.3 Contrast** |

---

## 3. The plan

### Design principle: Open/Closed applied to the UI

> Pages and components are **closed for modification** — you should be able to add a new dashboard page, a new status, a new button intent, a new surface elevation **without editing existing component CSS or JSX**, only by:
> 1. adding a token, or
> 2. adding a modifier class next to the existing ones, or
> 3. adding an entry to a config map (like `StatusBadge`'s).

Every step below is structured as *extend the base, migrate callers* — never *rewrite the base and break callers*.

---

### Phase 0 — Baseline & guardrails (no visual change)

**0.1** Add an ESLint rule (`react/forbid-dom-props` for `style`) set to **warn**, so new inline styles are visible in review. Do not auto-fix.
**0.2** Add a short `frontend/DESIGN.md`: the token list, "no raw hex in components," the button/surface/spacing vocabulary, and the OCP rule above. This is the contract Phase 1+ enforces.
**0.3** Screenshot the current Login, an admin dashboard, ReviewQueue, DocumentRepository, and a modal (via `run` skill / manual) — before/after reference.

*Deliverable:* lint warning active, `DESIGN.md`, baseline screenshots. **Zero runtime change.**

---

### Phase 1 — Harden the token layer (extend `tokens.css` only)

Add — never remove — the following token groups. Existing `var(--x)` refs keep resolving; new tokens are additive.

**1.1 Spacing scale**
```css
--space-1: 0.25rem;  --space-2: 0.5rem;   --space-3: 0.75rem;
--space-4: 1rem;      --space-5: 1.5rem;   --space-6: 2rem;
--space-7: 2.5rem;    --space-8: 3.5rem;
```

**1.2 Transition + motion**
```css
--transition-fast: 120ms ease;
--transition: 160ms ease;
--transition-slow: 240ms ease;
--ease-spring: cubic-bezier(0.2, 0.8, 0.2, 1);
```

**1.3 Focus ring (single definition)**
```css
--focus-ring: 0 0 0 3px var(--primary-soft);
--focus-ring-offset: 2px;
```

**1.4 Z-index scale**
```css
--z-base: 1; --z-dropdown: 50; --z-sticky: 80;
--z-modal: 100; --z-toast: 200;
```

**1.5 Status tokens — reconcile C3.** Decide **one** value per status (recommend keeping the muted `tokens.css` set — it matches the "desaturated" system direction — and retiring the brighter `dcfce7/#16a34a` badge palette). Add the missing badge-background tokens:
```css
--info: #1d4ed8; --info-soft: #dbeafe;   /* for badge--active */
```

**1.6 One accent — resolve C1.** Auth flows adopt `--primary`. Introduce a semantic alias so the *decision* is expressed once:
```css
--accent: var(--primary);
--accent-hover: var(--primary-hover);
--accent-soft: var(--primary-soft);
```
`--seal` stays **only** as the "approved/verification" status colour (its original intent), not as a UI accent.

**1.7 Container widths**
```css
--container: 72rem;
--container-wide: 96rem;
```

*Deliverable:* extended `tokens.css`. Still zero visual change — nothing consumes the new tokens yet.

---

### Phase 2 — One button system (`redesign-skill` fix-priority #3)

**2.1** In `dashboards.css`, extend `.btn`:
- add `:focus-visible { outline: none; box-shadow: var(--focus-ring); }`
- add `:active:not(:disabled) { transform: translateY(1px); }`
- swap the hand-tuned `0.15s` list for `var(--transition-fast)`
- add intents as **new modifiers alongside** the existing ones (do not touch `--primary/--outline/--danger-outline`): `.btn--ghost` (text-only), `.btn--danger` (solid, for confirmed destructive).

**2.2** Add `.icon-btn` — one class for `.modal-close`, `.sidebar-toggle`, `.bell-btn`, pager arrows: square, `--radius-sm`, centered icon, shared hover + `:focus-visible`. Migrate those four call sites to it (markup change only, no behaviour change).

**2.3** Fold `.submit-btn` and `.auth-submit` into `.btn`:
- `Login.jsx` submit → `className="btn btn--primary btn--block"` (add `.btn--block { width:100%; }`)
- `AuthLayout` `.auth-submit` → same; delete `.auth-submit` rule, delete `.submit-btn` rule.
- Keep the login button's larger size via `.btn--lg` (add it) so we don't special-case.

**2.4** `.tab-btn` — add `:hover` and `:focus-visible`. Consider re-expressing as `.btn .btn--tab` later; for now just give it feedback.

**2.5** Sweep bare `<button style={{}}>` in `NotificationBell`, `Pager`, `AiSuggestionPanel`, `ReportRunner` → `.btn`/`.btn--ghost`/`.btn-sm` + `.icon-btn`. Remove the inline styles.

**2.6** Destructive-action affordance (B6): add a tiny `<ConfirmDialog>` built on the existing `Modal` primitive (so it inherits any Phase 5 a11y fixes). Replace the 3 `window.confirm` calls. One component, config-driven (`title`, `body`, `confirmLabel`, `onConfirm`) — OCP: new confirms don't modify it.

*Deliverable:* every button in the app renders through `.btn` / `.icon-btn`; focus + active states universal; `window.confirm` gone. Auth-flow accent unified as a side effect.

---

### Phase 3 — Kill raw hex & inline styles in the visual layer (fix-priority #2 + #7)

**3.1** `dashboards.css` (30 literals): replace every hex with the token it duplicates. `#f8fafc`→`var(--gray-50)`, `#f1f5f9`→`var(--gray-100)`, badge colours → the reconciled status tokens from 1.5, `#cbd5e1` toggle track → `var(--gray-300)`, etc. Pure find-replace, visually identical (or a deliberate, noted 1-shade shift where the old value was off-system).

**3.2** `NotificationBell.css` (17), `Sidebar.css` (3), `AdminOverview.css` (3): same treatment.

**3.3** `Login.css` (14): the gradient (C4) — replace `linear-gradient(135deg,#1e3a8a,#2563eb)` with a `--primary-strong`-based treatment (the colour already used for sidebar/header, so the login panel finally matches the app it logs into). Optional: add a subtle `picsum`/noise overlay per skill's "empty flat sections" note — **flag for your approval, not automatic.**

**3.4** Inline-style sweep (L2, 133×). Triage:
- `marginTop/marginBottom` one-offs → utility classes `.mt-2 .mt-3 .mt-4 .mb-2 …` mapped to `--space-*`, **or** fix the component's own CSS to own its spacing (preferred where it's a real component).
- `color:'var(--text-label)'` on `<label>` etc. → move into the relevant `.dash-label` / `.filter-field label` rule (it's already supposed to be that colour).
- `width:'auto'` on selects → `.dash-select--auto` modifier.
- `style={{ background: r.color }}` on chart legend dots → this one is legitimately dynamic (data-driven colour); **keep**, it's the documented exception.
- Delete every `var(--x, #wrong-fallback)` (Q2) — just `var(--x)`.

**3.5** `radius:50%` / `999px` (14×) → `var(--radius-full)`.

*Deliverable:* `grep '#[0-9a-fA-F]' --include=*.css` outside `tokens.css` → **0** (or a short, documented allowlist). Inline `style` count down ~80%.

---

### Phase 4 — Surface & spacing consistency (fix-priority #4)

**4.1** Add `.surface` to `dashboards.css`:
```css
.surface { background: var(--paper); border: 1px solid var(--line);
           border-radius: var(--radius); box-shadow: var(--shadow-sm); }
.surface--raised { box-shadow: var(--shadow-md); }
.surface--flat   { box-shadow: none; }
.surface--lg     { border-radius: var(--radius-lg); }
```
Refactor `.panel`, `.stat-card`, `.ai-sugg-card` to `@extend`-style composition (plain CSS: `.panel { }` keeps only its *unique* padding/margin, and the JSX adds `className="surface panel"`). `.modal-card`, `.bell-dropdown`, `.auth-card` likewise. **Callers add a class; no component is rewritten.**

**4.2** Normalise `.panel` padding to `var(--space-5)` all sides (drop the accidental `1.5/1.6/1.7` asymmetry, L5). If an optical bottom-bias is wanted, make it deliberate: `padding: var(--space-5) var(--space-5) var(--space-6)`.

**4.3** Replace freehand rem spacing in the 6 CSS files with `--space-*` tokens where it's structural (gaps, section margins, padding). Leave typographic micro-values (`0.35rem` label gaps) as-is or map to `--space-1`.

**4.4** `StatCard` (P2): replace `accent` colour prop with `tone` prop → `className={'stat-card stat-card--' + tone}` where `tone ∈ {default, success, warning, danger, info}`, each a modifier reading a status token. Callers pass a name, not a hex. **OCP.**

*Deliverable:* one surface definition; spacing on a scale; `StatCard` closed for modification.

---

### Phase 5 — States & a11y (fix-priority #6 + accessibility)

**5.1 Focus & keyboard**
- Global: `:focus-visible { outline: none; box-shadow: var(--focus-ring); }` fallback for links/inputs not otherwise handled.
- `.data-table tbody tr:focus-within { background: var(--gray-50); }` (A2).
- `Modal.jsx`: add Escape-to-close, focus trap (focus first focusable on open, restore trigger focus on close). Every modal in the app benefits — `Modal` is the shared primitive. (A3)
- `<a class="skip-link">Skip to content</a>` in `DashboardShell` + `AuthLayout`, target `#main`. (A6)

**5.2 Contrast (A8)**
- `.cell-muted` / `.cell-mono` colour `#94a3b8` (2.8:1) → `var(--gray-500)` (`#64748b`, 4.6:1) at the sizes currently used. Verify with a contrast check.
- Audit `--text-muted` usages at <0.875rem; bump to `--text-secondary` where they carry meaning.

**5.3 Use-of-colour (A5)**
- `Governance.jsx` overdue: add a text/icon marker (`⚠ overdue` label like `ReviewQueue` already does) in addition to the red — don't rely on colour alone.

**5.4 Loading states (S1)**
- Add `.skeleton` (shimmer block, respects reduced-motion) + `<TableSkeleton rows cols>` and `<CardSkeleton>`. Swap the `"Loading…"` text in `ReviewQueue`, `DocumentRepository`, `AdminOverview`, `App.jsx` route fallback.

**5.5 Empty states (S2)**
- `<EmptyState icon title message action?>` component. Replace `.empty-row` bare text and the `"Nothing here."` cells. Config-driven, one component.

**5.6 Error/success unification (S3, S5)**
- One `<Banner tone="error|success|info" />` on `.surface--flat` + status token border-left. Retire `.error-banner`/`.form-error`/`.auth-error` and the two success variants — replace with `<Banner>`. Raw `err.response.data.message` renders inside it.

**5.7 Custom 404 (S4)**
- `<NotFound />` page (branded, "back to dashboard" link). Wire `path="*"` to it instead of silent `<Navigate>`.

*Deliverable:* WCAG 2.4.7 / 2.1.2 / 1.4.3 / 1.4.1 gaps closed; skeleton + empty + error + 404 states consistent and reusable.

---

### Phase 6 — Typography polish (fix-priority #1 & #7, lowest risk / do anytime)

**6.1** Confirm `Public Sans` + `IBM Plex Mono` are actually loaded (font-face / link) — `tokens.css` references them but check `index.html`. If missing, add or drop to the fallback stack honestly.
**6.2** Add a type scale to tokens (`--text-xs … --text-2xl`, line-heights) and map the freehand `0.72–1.9rem` font-sizes onto it.
**6.3** `text-wrap: balance` on `.page-title`, `.panel-title`, `.modal-header h2`, `.ink-headline`. `text-wrap: pretty` on `.auth-lead`, `.ink-sub`, `.prose`.
**6.4** Numeric columns already use `tabular-nums` in `.data-table`/`.stat-value` — extend to `Reports` figures and any `amount` cells.
**6.5** Sentence-case sweep of headers (skill: "Title Case On Every Header"). Most already are; catch stragglers.

---

## 4. Sequencing & risk

| Phase | Risk | Visual change | Can ship alone |
|-------|------|---------------|----------------|
| 0 Guardrails | none | none | ✓ |
| 1 Tokens (additive) | none | none | ✓ |
| 2 Buttons | low | focus/active rings appear; auth button turns blue | ✓ |
| 3 Hex/inline sweep | low | near-identical; login panel restyled | ✓ (after 1) |
| 4 Surface/spacing | low-med | minor padding normalisation | ✓ (after 1,3) |
| 5 States/a11y | med | new skeletons/empty/404; modal keyboard behaviour | ✓ (after 2) |
| 6 Typography | low | tighter headings, balanced wrap | ✓ anytime |

**Recommended order:** 0 → 1 → 2 → 3 → 6 → 4 → 5.
6 before 4/5 because it's pure polish, zero-dependency, and gives a visible quality lift early.

**Testing after each phase:** `npm run build` clean, click through Login → each role's dashboard → ReviewQueue → DocumentRepository → one modal → one CRUD create/edit/deactivate → Reports. Keyboard-only pass after Phase 2 and Phase 5.

---

## 5. New shared primitives this plan introduces

All config-driven, all closed for modification (extend via props/config, not edits):

| Primitive | Replaces | OCP extension point |
|-----------|----------|---------------------|
| `.btn--ghost / --danger / --block / --lg` | `.submit-btn`, `.auth-submit`, bare buttons | new `--intent` modifier |
| `.icon-btn` | `.modal-close`, `.sidebar-toggle`, `.bell-btn`, pager arrows | — |
| `.surface` + modifiers | 6 copies of "border+shadow+radius card" | new `--elevation` modifier |
| `--space-* / --transition-* / --z-* / --text-*` tokens | 20+ freehand values each | add a token |
| `<ConfirmDialog>` | 3× `window.confirm` | props |
| `<Banner tone>` | `.error-banner`, `.form-error`, `.auth-error`, `.success-banner`, `.auth-success` | new `tone` |
| `<TableSkeleton> / <CardSkeleton>` | `"Loading…"` text | props |
| `<EmptyState>` | `.empty-row`, `"Nothing here."` | props |
| `<NotFound>` | silent `<Navigate to="/">` | — |
| `StatCard tone` prop | `accent` hex prop | new `tone` value + modifier class |

---

## 6. Out of scope / deferred (note only)

- Icon-set swap away from Lucide (P4) — cosmetic, internal tool, skip.
- Slide-over panels replacing modals for simple edits (P3) — larger UX change, separate proposal.
- Login-panel background imagery / noise overlay (3.3 optional) — needs your sign-off on direction.
- Dark mode — token layer already re-declares semantics for `.sidebar`/`.page-header`; a full theme is a separate effort but the groundwork exists.
