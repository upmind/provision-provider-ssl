#!/usr/bin/env bash
# Edit and read CHANGELOG.md for a release.
#
#   changelog.sh release <version> <date> <owner/repo>
#       Move the Unreleased entries under a new version heading. Fail if there are none.
#   changelog.sh notes <version>
#       Print the entries of a version.
#   changelog.sh unreleased
#       Print the Unreleased entries.
#   changelog.sh verify <owner/repo>
#       Fail if a version's entries differ from its GitHub release notes. A difference means a merge
#       put entries under the wrong heading, or a correction reached only one of the two.
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
    local repo="$1" version tag released current err failed=0

    # a 404 below means "not released"; a wrong repository would 404 for every version
    if ! gh api "repos/${repo}" --silent; then
        echo "::error file=${file}::Cannot read the ${repo} repository"
        return 1
    fi

    err="$(mktemp)"

    for version in $(sed -n 's/^## \[\([^]]*\)\].*/\1/p' "$file"); do
        tag="v${version}"

        # CLICOLOR_FORCE would add colour codes to the output
        if ! released="$(CLICOLOR_FORCE=0 gh api "repos/${repo}/releases/tags/${tag}" --jq .body 2>"$err")"; then
            # not released yet, e.g. on a release pull request
            grep -q 'HTTP 404' "$err" && continue
            failed=1
            echo "::error file=${file}::Cannot read the ${tag} release notes: $(head -n 1 "$err")"
            continue
        fi

        # the release page editor saves CRLF line endings
        released="${released//$'\r'/}"
        current="$(section "## [${version}](")"

        if [ "$current" != "$released" ]; then
            failed=1
            echo "::error file=${file}::The ${version} entries differ from the ${tag} release notes. Put new entries under ## Unreleased. To correct released notes, edit the GitHub release too."
            diff -u --label "${tag} release" --label "${file}" <(echo "$released") <(echo "$current") || true
        fi
    done

    rm -f "$err"
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
    verify) verify "$2" ;;
    *) echo "Usage: $0 release <version> <date> <owner/repo> | notes <version> | unreleased | verify <owner/repo>" >&2; exit 1 ;;
esac
