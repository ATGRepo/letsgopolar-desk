# Dates and Rates - live data setup (for the developer)

This wires the existing dashboard to the live Google Sheet. Three files, in order.

## Files
1. `Code.gs` - Google Apps Script that reads the Sheet and returns clean JSON.
2. `rate-data.php` - small proxy for the desk subdomain that calls the Apps Script and, later, trip pages. Keeps everything same-origin and hides the Apps Script URL.
3. `expedition-rate-desk-fetch.html` - the dashboard, unchanged except it now fetches its data instead of carrying a baked-in copy.

## Step 1 - Apps Script
1. Open the rates Google Sheet, then Extensions, then Apps Script.
2. Replace the default file contents with `Code.gs`.
3. Deploy, then New deployment, type Web app. Execute as: Me. Access: Anyone with the link.
4. Copy the resulting `/exec` URL.

The script reproduces the exact normalization the offline build uses: per-tab cabin pairing by column position, the Region column with the safeguard that corrects blatant contradictions and logs them, date-derived seasons, and the itinerary merges. It returns `{ "updated": "...", "trips": [ ... ] }`.

## Step 2 - PHP proxy
1. Open `rate-data.php` and paste the `/exec` URL into `APPS_SCRIPT_URL`.
2. Upload it to the desk subdomain web root, next to the dashboard:
   `/home/uXXXXXXXX/domains/letsgopolar.com/public_html/desk/rate-data.php`
3. Test by visiting `https://desk.letsgopolar.com/rate-data.php` - it should return JSON.

Caching: `CACHE_SECONDS` defaults to 300 (5 minutes), which spares the Apps Script on every page load and is fine for weekly pricing. Set it to 0 to always read live. If Apps Script is briefly unreachable, the proxy serves the last good cache rather than failing.

## Step 3 - the dashboard
1. In `expedition-rate-desk-fetch.html`, confirm `DATA_ENDPOINT` is `"/rate-data.php"` (same folder as the page). That is the default.
2. Put this version in place of the current baked-in dashboard, the same way the current one was added (WPCode HTML snippet, or as a file in the desk folder).
3. Load the page. It shows "Loading sailings...", then fills once the proxy responds. Update the Sheet, reload, the numbers change. No more weekly manual rebuild.

## Later - the live brochure
`rate-data.php` already has a second mode: `?trip=<full letsgopolar.com URL>` returns that trip page's HTML (same-origin), which the brochure can parse for description, images, and inclusions. Only letsgopolar.com URLs are accepted, so it cannot be used as an open proxy. The brochure wiring itself is a later step.

## Testing shortcut
To test the dashboard before the proxy exists, you can temporarily set `DATA_ENDPOINT` to the Apps Script `/exec` URL directly. It will work, but the Apps Script URL becomes visible in the page source, so switch back to `/rate-data.php` for production.
