#!/usr/bin/env bash
#
# Repo syntax checker — the single source of truth for the "safety net" CI
# (.github/workflows/ci.yml) and for local pre-push checks. It needs nothing
# beyond `php` and `node`, both already required to work on this project
# (no build step, no Composer/npm install).
#
#   scripts/check-syntax.sh [php|js|all]      # default: all
#
# PHP: runs `php -l` on every tracked *.php file.
#
# JS : runs `node --check` on every *.js file, ESM-aware. IMPORTANT — in
#      current Node, `node --check <file>.js` SILENTLY PASSES a file that uses
#      top-level import/export (it never actually parses it), so a broken ES
#      module would sail through. We therefore check ES-module files (anything
#      with a top-level import/export — all of src/js, app.js, player.js) via
#      `--input-type=module` over stdin, which DOES report syntax errors, and
#      check classic scripts / CommonJS (sw.js, admin/*, electron/*) as files.
#
set -uo pipefail

cd "$(dirname "$0")/.."

mode="${1:-all}"
status=0

# List source files of a given extension, skipping vendored / VCS dirs.
find_files() { # $1 = extension
    find . \
        -path ./.git -prune -o \
        -path ./node_modules -prune -o \
        -path ./vendor -prune -o \
        -name "*.$1" -print | sort
}

check_php() {
    echo "==> php -l on all *.php"
    local f out n=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        n=$((n + 1))
        if ! out=$(php -l "$f" 2>&1); then
            echo "PHP SYNTAX ERROR: $f"
            echo "$out"
            status=1
        fi
    done < <(find_files php)
    echo "    checked $n PHP file(s)"
}

check_js() {
    echo "==> node --check on all *.js (ESM-aware)"
    local f out n=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        n=$((n + 1))
        if grep -qE '^[[:space:]]*(import|export)[[:space:]{*]' "$f"; then
            # ES module — force module mode; a plain file arg would pass blindly.
            if ! out=$(node --check --input-type=module < "$f" 2>&1); then
                echo "JS SYNTAX ERROR (esm): $f"
                echo "$out" | sed "1s#^\[stdin\]#$f#"
                status=1
            fi
        else
            # classic script / CommonJS module
            if ! out=$(node --check "$f" 2>&1); then
                echo "JS SYNTAX ERROR: $f"
                echo "$out"
                status=1
            fi
        fi
    done < <(find_files js)
    echo "    checked $n JS file(s)"
}

case "$mode" in
    php) check_php ;;
    js)  check_js ;;
    all) check_php; check_js ;;
    *)   echo "usage: $(basename "$0") [php|js|all]" >&2; exit 2 ;;
esac

if [ "$status" -eq 0 ]; then
    echo "OK: no syntax errors"
fi
exit "$status"
