# Frontend design contract

This is the rule set the frontend is being brought in line with. New code
follows it; existing code is migrated phase by phase (see
`FRONTEND_CONSISTENCY_PLAN.md`).

---

## The one rule: Open/Closed

You should be able to add **a new page, a new status, a new button intent, a
new surface elevation** without editing any existing component's CSS or JSX.
The only permitted extension moves are:

1. **Add a token** in `src/styles/tokens.css`.
2. **Add a modifier class** next to the existing ones (`.btn--x`, `.surface--x`).
3. **Add an entry to a config map** (e.g. `StatusBadge`'s `STATUS_CONFIG`).

If a change requires editing a shared component's internals to accommodate a
new case, the component is wrong — make it config-driven instead.

---

## Stylesheet layering

- **`src/styles/tokens.css`** — every custom property. No rules.
- **`src/styles/controls.css`** — the app-wide `.btn` / `.icon-btn` /
  `.banner` system. Imported from `index.css`, so **every** page (dashboards
  and the auth screens) gets it without importing `dashboards.css`.
- **`src/pages/dashboards/dashboards.css`** — dashboard chrome only
  (panels, tables, forms, filter bar, tabs, modal shell).
- Page-specific CSS (`Login.css`, `AuthLayout.css`, `Sidebar.css`,
  `NotificationBell.css`) — layout/chrome unique to that surface, no
  re-implementation of buttons or banners.

## Tokens — `src/styles/tokens.css`

Single source of truth. **Components read tokens, never raw hex, never raw
px for spacing/radius/z-index.**

| Group | Tokens | Use for |
|---|---|---|
| Type | `--font-sans` `--font-mono` `--font-body` `--font-display` | font families |
| Type scale | `--text-xs … --text-2xl`, `--leading-tight` `--leading-normal` | every `font-size` |
| Neutrals | `--gray-50 … --gray-900` | one cool-gray family — **no warm grays** |
| Accent | `--accent` `--accent-hover` `--accent-soft` | the **one** UI accent (points at `--primary`) |
| Surfaces | `--paper` `--paper-sunken` `--ink` `--ink-soft` `--line` `--line-soft` | backgrounds / text / borders |
| Spacing | `--space-1 … --space-8` (4px base) | structural gaps, padding, section rhythm |
| Layout | `--container` (72rem) `--container-wide` (96rem) | page max-width |
| Radius | `--radius-sm` `--radius` `--radius-lg` `--radius-full` | see scale below |
| Motion | `--transition-fast` (120ms) `--transition` (160ms) `--transition-slow` (240ms) `--ease-spring` | every `transition` / animation easing |
| Focus | `--focus-ring` | every `:focus-visible` box-shadow |
| Z-index | `--z-base` `--z-dropdown` `--z-sticky` `--z-modal` `--z-toast` | **never hand-pick a z-index** |
| Shadow | `--shadow-sm` `--shadow-md` `--shadow-lg` | slate-tinted elevation |
| Status | `--danger/-soft` `--success/-soft` `--warning/-soft` `--info/-soft` | badges, notices, banners — **the only status colours** |

`--seal` / `--seal-deep` are **status** colours (approved / verified), never a
UI accent. Legacy aliases (`--card-bg`, `--border`, `--text-primary`, …) stay
for back-compat; new code prefers the primary names above.

### Radius scale (deliberately small)

- `--radius-sm` — controls: inputs, buttons, badges, chips, tabs
- `--radius` — cards / panels / stat cards
- `--radius-lg` — modal + dropdown outer shells
- `--radius-full` — **only** status pills and avatars (no bare `999px` / `50%`)

---

## Buttons

One system. Every clickable control renders through `.btn` or `.icon-btn`.

- **`.btn`** + one intent: `.btn--primary` (solid accent), `.btn--outline`,
  `.btn--ghost` (text only), `.btn--danger` (solid, confirmed destructive),
  `.btn--danger-outline`.
- **Size:** default, `.btn-sm`, `.btn--lg`. **`.btn--block`** for full width.
- **`.icon-btn`** — square icon-only button (modal close, sidebar toggle,
  bell, pager arrows). Needs an `aria-label`.
- Every button has `:hover`, `:active` (`translateY(1px)`), and
  `:focus-visible` (`box-shadow: var(--focus-ring)`). No exceptions.
- **No** `window.confirm` / `window.alert`. Destructive actions use
  `<ConfirmDialog>` (built on `Modal`).
- **No** bare `<button style={{}}>`.

---

## Surfaces

One definition of "a card": `--paper` ground + `1px --line` border +
`--radius` + `--shadow-sm`, in **`controls.css`**. The named card classes —
`.panel`, `.stat-card`, `.ai-sugg-card`, `.modal-card`, `.bell-dropdown`,
`.bell-toast`, `.auth-card` — are **in that selector list**, so they get the
surface for free without their (28+) call sites needing an extra class; each
class then adds only its own padding / layout in its own file. Genuinely new
card-shaped markup uses `class="surface"` directly.

Modifiers tune from the default: `.surface--raised` (`--shadow-md`),
`.surface--floating` (`--shadow-lg`), `.surface--flat` (no shadow),
`.surface--lg` / `.surface--sm` (radius). `.panel` padding is
`--space-5 --space-5 --space-6` (a deliberate optical bottom-bias, not the
old accidental `1.5 / 1.6 / 1.7`).

`StatCard` takes `tone` — `default | success | warning | danger | info` — a
name that maps to a `.stat-card--<tone>` modifier reading a status token.
Never a hex.

---

## States (must all exist, must be reused)

| State | Component | Not this |
|---|---|---|
| Loading | `<TableSkeleton rows>` / `<CardSkeleton>` / `<Skeleton>` (shape-matched, `role="status"`) | `"Loading…"` text |
| Empty | `<EmptyState icon title message action?>` | a bare `<td>` |
| Error | `<Banner tone="error">` (adds `role="alert"`) | `.error-banner` / `.form-error` / `.auth-error` |
| Success | `<Banner tone="success">` | `.success-banner` / `.auth-success` |
| 404 | `<NotFound>` page (branded, "back" link) | silent `<Navigate to="/">` |

`<Banner>` is the single inline status message — `tone ∈ {error, success, info}`.
All 34 `.error-banner` / `.success-banner` sites migrated; the alias CSS is
deleted. Skeletons are used on the table-heavy pages (ReviewQueue,
DocumentRepository, AuditLog, ManageUsers); `.loading-text` (tokenised) is
the acceptable default for the smaller list pages. `<EmptyState>` replaces the
bare "nothing here" cell on ReviewQueue and DocumentRepository.

---

## Accessibility — WCAG 2.1 AA

- **Visible focus** on every interactive element (`--focus-ring`). Non-negotiable (2.4.7). ✓
- **Modal:** focus trap, Escape to close, restore focus to trigger on close (2.1.2, 2.4.3). ✓
- **Skip link** to `#main` in `DashboardShell` (2.4.1). ✓
- **Table rows:** `tr:focus-within` mirrors `:hover` so keyboard users see the active row. ✓
- **Contrast:** body/meaningful text ≥ 4.5:1. `--gray-400` (`#94a3b8`, ~2.8:1
  on white) must not carry meaning — use `--gray-500`+. The dark-surface
  `--text-muted` / `--text-label` were lifted to 0.7 / 0.75 alpha to clear
  4.5:1 on `--primary-strong`. Input **placeholders** are exempt (not content).
- **Never signal by colour alone** (1.4.1) — pair with text or an icon.
  Governance "overdue" now carries a `· overdue` flag, not just red. ✓
- Icon-only controls need `aria-label`.

---

## Layout / CSS hygiene

- `min-height: 100dvh` for full-screen — never `100vh` (iOS Safari jump).
- No inline `style={{}}` for static values — use a class, a `.u-*` utility,
  or the component's CSS. The **only** allowed inline styles are genuinely
  dynamic: a value from props (`maxWidth: width`), a value from data
  (`background: series.color` on a chart swatch), or a computed CSS custom
  property (`style={{ '--depth': n }}` feeding a `calc()` in the stylesheet).
- No raw hex / `rgba()` in component CSS — use a token. On-dark structural
  surfaces (login ink panel, sidebar, header) use the `--on-dark*` tokens.
  Genuine circles may use `border-radius: 50%`; pills/avatars use
  `--radius-full`. `#ffffff` is allowed only as text/icon colour on a
  filled coloured button.
- No `var(--x, #fallback)` — the token always exists; the literal fallback
  just rots out of sync (and the ones that were there had *wrong* values).
- `.u-*` utilities (`u-mt-*`, `u-mb-*`, `u-ml-1`, `u-scroll-x`, `u-w-auto`,
  `u-flex-1`, `u-py-3`, `u-tnum`, `u-nowrap`, `u-maxw-sm`) cover recurring
  spacing/layout one-offs. Reach for a real component class first; use a
  utility only for a true one-off adjustment.
- Semantic HTML: `<nav> <main> <header> <aside> <section>`.

---

## Typography

- Families load via Google Fonts in `index.html` (`Public Sans`, `IBM Plex Mono`).
- All `font-size` values map to `--text-2xs … --text-2xl`. The only literal
  `font-size` left is `.modal-close` — that sizes the `×` glyph, not text.
- `text-wrap: balance` on headings; `text-wrap: pretty` on body paragraphs.
- `font-variant-numeric: tabular-nums` on any column/figure of numbers.
- Sentence case for headers, not Title Case. No exclamation marks in success copy.

---

## Deviations from the plan

- **Phase 0 ESLint guard-rail skipped.** The project has no ESLint setup or
  dependency; adding one is out of proportion to a guardrail step and would
  flood the first run with unrelated warnings. This document is the contract
  instead; enforcement is by review.
- **`NotificationBell.css` dark-mode blocks** still carry two `#1e2530`
  literals and a few `rgba(255,255,255,…)`. They sit inside
  `@media (prefers-color-scheme: dark)` / `:root[data-theme='dark']` and the
  app ships no active dark theme, so they never render. Tokenise them if/when
  a real dark theme lands (see the Phase 1 note — the token layer already
  re-declares semantics for `.sidebar` / `.page-header`).
- **`soft-skill` not applied.** It targets marketing/agency sites (OLED
  black, Bento grids, cinematic motion, "ban Lucide"); wrong fit for an
  internal records dashboard. Only its type-hierarchy / sentence-case
  guidance was used.
