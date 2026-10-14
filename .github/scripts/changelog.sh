#!/usr/bin/env bash
# Edit and read CHANGELOG.md for a release.
#
#   changelog.sh release <version> <date> <owner/repo>
#       Move the Unreleased entries under a new version heading. Fail if there are none.
#   changelog.sh notes <version>
#       Print the entries of a version.
#   changelog.sh unreleased
#       Print the Unreleased entries.
#   changelog.sh verify
#       Fail if a version's entries differ from the entries at its tag. A release cannot change
#       after it is tagged, so a difference means a merge put entries under the wrong heading.
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

verify() {
    local version tag tagged released current failed=0

    for version in $(sed -n 's/^## \[\([^]]*\)\].*/\1/p' "$file"); do
        tag="v${version}"
        # not released yet, e.g. on a release pull request
        git rev-parse --quiet --verify "refs/tags/${tag}" >/dev/null || continue

        tagged="$(git show "${tag}:CHANGELOG.md")" || { failed=1; continue; }
        # a tag on another release line need not list this version
        released="$(section "## [${version}](" - <<< "$tagged")" || continue
        current="$(section "## [${version}](")"

        if [ "$current" != "$released" ]; then
            failed=1
            echo "::error file=${file}::The ${version} entries differ from the ${tag} release. Released notes do not change; put new entries under ## Unreleased."
            diff -u --label "${tag}" --label "${file}" <(echo "$released") <(echo "$current") || true
        fi
    done

    return $failed
}

# print the entries under the first heading that starts with $1, from file $2 (default: $file; - for stdin)
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
    ' "${2:-$file}"
}

case "${1:-}" in
    release) release "$2" "$3" "$4" ;;
    notes) notes "$2" ;;
    unreleased) section '## Unreleased' ;;
    verify) verify ;;
    *) echo "Usage: $0 release <version> <date> <owner/repo> | notes <version> | unreleased | verify" >&2; exit 1 ;;
esac
