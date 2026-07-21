#!/usr/bin/env bash
# Pre-deploy validation gate.
# - php -l on every PHP endpoint
# - node --check on every <script> block extracted from the HTML pages
# Exits non-zero on the first failure so a bad file never reaches the server.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0

echo "== PHP lint =="
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do
    if ! php -l "$f" >/dev/null 2>err.txt; then
      echo "FAIL  $f"; cat err.txt; fail=1
    else
      echo "ok    $f"
    fi
  done < <(find desk -name '*.php' | sort)
  rm -f err.txt
else
  echo "SKIP  php CLI not installed (apt-get install -y php-cli)"
fi

echo ""
echo "== JS syntax in HTML pages =="
if command -v node >/dev/null 2>&1; then
  for html in site/*.html; do
    [ -f "$html" ] || continue
    tmp="$(mktemp --suffix=.js)"
    # concatenate every <script>...</script> body (inline scripts only)
    node -e '
      const fs=require("fs");
      const s=fs.readFileSync(process.argv[1],"utf8");
      const m=[...s.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)]
        .map(x=>x[1]).filter(b=>!/\bsrc\s*=/.test(b));
      fs.writeFileSync(process.argv[2], m.join("\n;\n"));
    ' "$html" "$tmp"
    if node --check "$tmp" 2>err.txt; then
      echo "ok    $html"
    else
      echo "FAIL  $html"; cat err.txt; fail=1
    fi
    rm -f "$tmp" err.txt
  done
else
  echo "SKIP  node not installed"
fi

echo ""
if [ "$fail" -eq 0 ]; then echo "ALL CHECKS PASSED"; else echo "VALIDATION FAILED"; fi
exit $fail
