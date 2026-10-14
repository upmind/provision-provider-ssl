#!/usr/bin/env bash
# Edit and read CHANGELOG.md for a release.
#
#   changelog.sh release <version> <date> <owner/repo>
#       Move the Unreleased entries under a new version heading. Fail if there are none.
#   changelog.sh notes <version>
#       Print the entries of a version.
set -euo pipefail

file="${CHANGELOG:-CHANGELOG.md}"

release() {
    local version="$1" date="$2" repo="$3" out status=0
    out="$(mktemp)"

    awk -v heading="## [${version}](https://github.com/${repo}/releases/tag/v${version}) - ${date}" '
        function flush(gap) {
            sub(/^\n+/, "", entries)
            sub(/\n+$/, "", entries)
            if (entries == "") { exit 2 }
            print heading "\n\n" entries gap
            in_unreleased = 0
        }
        /^## / && in_unreleased { flush("\n") }
        /^## Unreleased$/ { found = 1; in_unreleased = 1; print $0 "\n"; next }
        in_unreleased { entries = entries $0 "\n"; next }
        { print }
        END {
            if (!found) { exit 3 }
            if (in_unreleased) { flush("") }
        }
    ' "$file" > "$out" || status=$?

    case $status in
        0) mv "$out" "$file"; return ;;
        2) echo "No entries under ## Unreleased" >&2 ;;
        3) echo "No ## Unreleased heading" >&2 ;;
    esac
    rm -f "$out"
    exit 1
}

notes() {
    local version="$1"

    section "## [${version}]("
}

# print the entries under the first heading that starts with $1
section() {
    awk -v prefix="$1" '
        index($0, prefix) == 1 { found = 1; next }
        found && /^## / { exit }
        found { entries = entries $0 "\n" }
        END {
            if (!found) { exit 1 }
            sub(/^\n+/, "", entries)
            sub(/\n+$/, "", entries)
            print entries
        }
    ' "$file"
}

case "${1:-}" in
    release) release "$2" "$3" "$4" ;;
    notes) notes "$2" ;;
    *) echo "Usage: $0 release <version> <date> <owner/repo> | notes <version>" >&2; exit 1 ;;
esac
