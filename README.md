# Let's Go Polar — Sales Dashboard (desk.letsgopolar.com)

Internal sales tool for Antarctica Travel Group. Live pricing and availability
for polar cruises across many operators, in one interface. Runs at the private
staff subdomain `desk.letsgopolar.com` on WordPress / Hostinger.

This repo is the source of truth for the dashboard code. Deploys go to the server
through Claude Code + the Novamira MCP connection, not the Hostinger File Manager.

## Layout

```
desk/     Server side: the rate-data.php proxy/merge, upload endpoints,
          price audit, cabin alias map, and the Google Apps Script (Code.gs).
site/     Front end: index, dates-and-rates (the engine), closed-market (deals),
          api-integrations (operator upload controls).
docs/     Project docs: PROJECT.md (living reference), DEPLOY-README.md.
scripts/  validate.sh — php -l + node --check gate before any deploy.
```

Runtime data files (`desk/*.json`, `desk/*.csv`) are generated on the server and
are git-ignored. Operator credentials live in a server-only `desk/config.php`
(also git-ignored) — never commit them.

## Deploy path on the host

`/home/u886488648/domains/letsgopolar.com/public_html/desk/` holds both the PHP
endpoints and the HTML pages (the subdomain doc root). `DirectoryIndex index.html`
serves the dashboard ahead of the WordPress install underneath.

## Workflow

1. Edit on a branch.
2. `bash scripts/validate.sh` — must pass.
3. Deploy the changed files to the server via Novamira, with a timestamped backup
   of each replaced file.
4. For risky work, deploy to `desk/staging/` first, verify, then promote.

See `docs/PROJECT.md` for architecture, the operator API status, and the roadmap.
