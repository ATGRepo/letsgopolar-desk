# Project reference — Let's Go Polar sales dashboard

Living doc. Keep it current as the build changes. Deeper history is in
`docs/DEPLOY-README.md` and the original dashboard context brief.

## What it is

A private sales dashboard at `desk.letsgopolar.com` showing live pricing and
availability for polar cruises across many operators. The front end holds no
baked-in data; it fetches a merged JSON feed from `rate-data.php`.

## Data flow (current)

```
Google Sheet (manual operator rates)  ->  Code.gs (Apps Script web app)  ->
rate-data.php (server proxy + per-operator merges + ~5 min cache)  ->
dates-and-rates.html (fetch on load)  ->  closed-market.html (iframe embed)
```

Operators with a **live API** (validated from the server 2026-07-21):

| Operator | Auth | Base | Key endpoints |
|---|---|---|---|
| Quark | none (public) | `www.quarkexpeditions.com/api/public/1` | `/itinerary`, `/departure` |
| Swan Hellenic | POST login/password -> JWT | `api-crs.swanhellenic.com` | `/security/authorise`, `/json/v3/cruises`, `/json/v3/cruise/room-tariffs?id={id}` |
| Aurora | OAuth2 client_credentials (Basic auth) -> Bearer | app: `aurora-partner-app-26ta7u.5sc6y6-4.usa-e2.cloudhub.io/api` | token: `mule-oauth-token-provider-26ta7u...cloudhub.io/token`; data: `/packages`, `/service?voyage={code}&currency=USD` |

All other operators (Oceanwide, Poseidon, G Adventures, Antarpply, Polar
Latitudes, ...) come in through the Google Sheet.

**Credentials are NOT in this repo.** They live server-side in `desk/config.php`
(git-ignored). Quark needs none.

## Join key between the two data worlds

The public `antarctic-trips` WordPress CPT (marketing pages on letsgopolar.com)
and the dashboard feed match on **ship + departure date**, plus each trip's
`lgpLink`. `wp-price-audit.php` already implements this match.

## Decisions

- Phases 3+ (central DB, antarctic-trips migration) happen on a **staging** copy
  first, then promote. Phase 2 also builds on `desk/staging/` before going live.
- Version control lives in a dedicated repo (this one), deployed via Novamira.

## Roadmap

- **Phase 1 — foundation.** Repo + validation gate + Novamira deploy. Remove the
  Hostinger leftovers (`create_autologin_*.php`, `default.php`, `default.php.old.php`).
- **Phase 2 — APIs in the dashboard.** Port Quark / Swan / Aurora to PHP fetchers
  on the server, credentials in `desk/config.php`, an in-dashboard "refresh"
  plus a cron. Retire the separate Next.js app and the download/upload loop.
  Uploads stay as a fallback. Build on staging first.
- **Phase 3 — central store.** One normalized `sailings` table as the single
  source of truth; `rate-data.php` reads it; feed shape unchanged. Staging.
- **Phase 4 — unify antarctic-trips pricing.** Public trip pages read live prices
  from the central store; remove the redundant WP price fields
  (`card_name_price_1..3`, `value_1..3`, `value_filter`, `value_filter02`,
  `promotion_percentage`) after export/backup; rebuild JetSmartFilters.
- **Phase 5 (optional).** Evaluate rendering trip pages headless from the store.

## antarctic-trips (letsgopolar.com) snapshot

CPT `antarctic-trips`, 649 published. Taxonomies: `regions-destination` (35),
`adventure-activities` (8), `specialty-trips` (5), `season` (38). Not registered
via JetEngine. Redundant price meta to retire in Phase 4 listed above.
