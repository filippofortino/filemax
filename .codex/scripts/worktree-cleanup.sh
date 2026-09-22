#!/usr/bin/env bash

set -euo pipefail

worktree_root="$(git rev-parse --show-toplevel)"
primary_worktree="$(git worktree list --porcelain | awk '$1 == "worktree" { print substr($0, 10); exit }')"

if [[ "$worktree_root" == "$primary_worktree" ]]; then
    echo "Skipping worktree cleanup in the primary checkout."
    exit 0
fi

site_hash="$(printf '%s' "$worktree_root" | shasum -a 256 | cut -c1-10)"
site_name="filemax-$site_hash"

herd unlink "$site_name" >/dev/null 2>&1 || true
echo "Removed Herd link $site_name.test"
