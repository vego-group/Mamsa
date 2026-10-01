#!/usr/bin/env bash
#
# Push this monorepo's backend/ to vego-group/mamsaa-backend-api, which holds
# the backend at its ROOT.
#
# Why this script exists: `git push backend-api <branch>` succeeds and produces
# a branch nobody can merge. The remote's main has app/, config/, routes/ at top
# level; a monorepo branch has backend/, frontend/, docs/. Git will not complain
# — the shapes are simply unrelated — and the mismatch is invisible until
# somebody opens the PR. It happened on 2026-09-06.
#
# Usage:  scripts/push-backend.sh <remote-branch-name>
set -euo pipefail

BRANCH="${1:-}"
[ -n "$BRANCH" ] || { echo "usage: $0 <remote-branch-name>"; exit 1; }

cd "$(git rev-parse --show-toplevel)"

TMP="tmp/split-$(date +%s)"
git subtree split --prefix=backend -b "$TMP" >/dev/null

# Content check, not just ancestry. A subtree split rewrites SHAs, so
# fast-forwarding proves lineage, not that the files are right.
SPLIT_TREE=$(git rev-parse "$TMP^{tree}")
MONO_TREE=$(git rev-parse HEAD:backend)

if [ "$SPLIT_TREE" != "$MONO_TREE" ]; then
    echo "✗ split tree does not match backend/ — refusing to push"
    git branch -D "$TMP" >/dev/null
    exit 1
fi

echo "✓ split tree matches backend/ exactly ($SPLIT_TREE)"
git push backend-api "$TMP:refs/heads/$BRANCH"
git branch -D "$TMP" >/dev/null

cat <<'NOTE'

Note: anything outside backend/ — docs/, nginx/, scripts/, the frontend — is
NOT in this push. It cannot be: the target repo has no place to put it.
NOTE
