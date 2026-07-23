# Let's Go Polar — full deploy (15 Jul 2026)

Two folders. Upload the **contents** of each to the matching place on the server.

## desk/  →  the `desk` folder on Hostinger
`/home/u886488648/domains/letsgopolar.com/public_html/desk`

All the PHP endpoints, the Apps Script, and the setup notes:
- `rate-data.php` — the proxy/merge (Oceanwide + Quark + Aurora + Swan + Quark CMD)
- `cabin-alias-map.php` — cabin-name aliases used by the price audit (NEW)
- `wp-audit.php` — live WordPress vs dashboard audit (reads antarctic-trips
  directly from the main site DB, read-only; no CSV upload). Reads main DB creds
  from the main site wp-config at runtime and the desk gate creds from the
  server-only operator-config.php. Behind the gate, manual only.
- `wp-writeback.php` — pushes feed prices back onto the website (writes to the
  main site DB). Prices only, full struck through, sold-out cabins untouched,
  $1 rounding skipped. Preview + apply-by-approved-ids, backs up each page to
  desk/price-backups/ before writing. Behind the gate, manual only.
- `wp-price-audit.php` — WordPress vs dashboard per-cabin price audit (CSV-upload
  fallback, kept alongside the live audit)
- `upload-quark-cmd.php` — Quark Closed Market xlsx upload/parse
- `upload-links.php`, `upload-quark-detail.php`, `upload-quark-links.php`,
  `upload-aurora-services.php`, `upload-aurora-links.php`,
  `upload-swan-trips.php`, `upload-swan-links.php` — operator upload endpoints
- `Code.gs`, `README-live-data-setup.md` — Apps Script + setup reference

## site/  →  the site pages (same `desk` folder root)
- `index.html` — home
- `dates-and-rates.html` — dashboard / kanban engine (also embedded by Closed Market)
- `closed-market.html` — deals module (Swan Flash, Swan Solo, Quark CMD)
- `api-integrations.html` — operator uploads + WordPress price-audit card

## After uploading
1. **Swan regions:** re-upload the new `LGP_Links_-_Swan_Hellenic.csv` (with the
   `region` column) via the Swan card on the API Integrations page. This fixes the
   Arctic/Antarctic labels — no code change was needed, the merge already reads that column.
2. **Cabin mapping:** the alias map is baked into `cabin-alias-map.php`. With it in place
   the price audit reports real per-cabin differences for Poseidon, Antarpply,
   Polar Latitudes and G Adventures (previously "naming needs mapping").
   Only the G Adventures "Superior" cabin stays unmapped (no dashboard equivalent yet).
3. Hard-refresh each page. For Closed Market, if a deal board looks stale, open
   `dates-and-rates.html?deals=quarkcmd&embed=1` directly once to clear the iframe cache.

## Notes on unused dashboard cabins
These feed cabins currently have no WordPress counterpart and are intentionally unmapped:
Upper Deck Obstructed View Triple, Solo French, Brynhilde Suite.
