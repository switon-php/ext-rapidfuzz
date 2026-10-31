#!/bin/sh
# Extension version from the git tag. v1.0.1 is 1.0.1. Three commits later is 1.0.1-3.
# The abbreviated commit id is not part of the version. Without a tag, the fallback is 1.0.0.
set -e
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$root"

strip_tag() {
    case "$1" in
        v[0-9]*) printf '%s\n' "${1#v}" ;;
        *) printf '%s\n' "$1" ;;
    esac
}

if tag=$(git describe --tags --exact-match 2>/dev/null); then
    strip_tag "$tag"
    exit 0
fi
if desc=$(git describe --tags 2>/dev/null); then
    strip_tag "$desc" | sed -E 's/-[0-9]+-g[0-9a-fA-F]+$//'
    exit 0
fi
printf '%s\n' '1.0.0'
