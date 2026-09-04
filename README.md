# Records & Approvals — login + role routing

A working login flow: Laravel issues a role-aware auth token, React
renders the sign-in screen and redirects each role to its own
dashboard. This covers the "Login → role check → dashboard" path from
the system flowchart — approvals, document upload, and the AI summary
step are separate pieces to build next.

## What's here

```
backend/    Laravel API — auth, roles, protected route middleware
frontend/   React (Vite) — login screen, auth context, role routing
```

## Run it

**Backend** — see `backend/SETUP.md` for wiring these files into a
fresh Laravel install. Short version:

```bash
cd backend
php artisan migrate
php artisan db:seed --class=RoleUserSeeder
php artisan serve   # http://localhost:8000
```

**Frontend**

```bash
cd frontend
npm install
cp .env.example .env
npm run dev          # http://localhost:5173
```

## Try it

Sign in with any of the seeded demo accounts (password: `password`):

| Email                        | Role         | Redirects to |
|-------------------------------|--------------|--------------|
| system.admin@example.test     | system_admin | `/admin`     |
| osm.admin@example.test        | osm_admin    | `/osm-admin` |
| user@example.test             | user         | `/dashboard` |

## How the redirect actually works

1. React posts credentials to `POST /api/login`.
2. Laravel validates them, issues a Sanctum token, and returns the
   user's role **and** a `redirect` path computed server-side
   (`User::dashboardRoute()`).
3. React stores the token, then navigates to whatever `redirect` the
   server said — the frontend never guesses the destination from a
   role string alone.
4. On refresh, `GET /api/me` rehydrates the session from the stored
   token so the redirect logic doesn't have to be repeated in local
   storage.
5. `ProtectedRoute` double-checks the role client-side (for UX — no
   flash of the wrong dashboard), and the Laravel `role` middleware
   enforces it server-side on every protected endpoint, so a tampered
   client-side role can't actually reach another role's data.

## Design notes

The login screen deliberately isn't a generic centered-card template.
It's split into two panels that mirror what this system actually is —
a document/approval tracker: an "ink" panel carries a reference-style
headline and a stamp mark that seals in on load, divided from the
sign-in form by a perforated ticket-stub seam. Palette is navy-ink /
sage-paper / stamp-green / brass, not the default warm-cream-and-terracotta
or dark-mode-neon look. Fully responsive; the split collapses to a
stacked layout under 860px, and `prefers-reduced-motion` disables the
stamp animation.
