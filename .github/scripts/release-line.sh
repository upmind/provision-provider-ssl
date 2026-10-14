#!/usr/bin/env bash
# Print the version prefix a branch releases, or fail if the branch does not release.
#
#   release-line.sh main      -> ''      (any version)
#   release-line.sh v1-dev    -> '1.'    (1.*.*)
#   release-line.sh v1.2-dev  -> '1.2.'  (1.2.*)
set -euo pipefail

branch="${1:-}"

if [ "$branch" = main ]; then
    echo ''
elif [[ "$branch" =~ ^v((0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))?)-dev$ ]]; then
    echo "${BASH_REMATCH[1]}."
else
    echo "Branch $branch is not main, vN-dev or vN.M-dev" >&2
    exit 1
fi
