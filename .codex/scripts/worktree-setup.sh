#!/usr/bin/env bash

set -euo pipefail

worktree_root="$(git rev-parse --show-toplevel)"
primary_worktree="$(git worktree list --porcelain | awk '$1 == "worktree" { print substr($0, 10); exit }')"

if [[ "$worktree_root" == "$primary_worktree" ]]; then
    echo "Skipping worktree setup in the primary checkout."
    exit 0
fi

site_hash="$(printf '%s' "$worktree_root" | shasum -a 256 | cut -c1-10)"
site_name="filemax-$site_hash"

synchronize_dependency_directory() {
    local directory="$1"

    if [[ -d "$primary_worktree/$directory" ]]; then
        mkdir -p "$worktree_root/$directory"
        rsync -a --delete "$primary_worktree/$directory/" "$worktree_root/$directory/"
    fi
}

if [[ -f "$primary_worktree/.env" ]]; then
    cp "$primary_worktree/.env" "$worktree_root/.env"
else
    cp "$worktree_root/.env.example" "$worktree_root/.env"
fi

herd link "$site_name" --secure --update-env

synchronize_dependency_directory vendor
synchronize_dependency_directory node_modules

herd composer install --no-interaction --no-progress --prefer-dist
bun install --frozen-lockfile

if ! grep -q '^APP_KEY=base64:' "$worktree_root/.env"; then
    herd php artisan key:generate --force --no-interaction
fi

rm -f "$worktree_root/database/database.sqlite"
touch "$worktree_root/database/database.sqlite"

herd php artisan migrate --seed --force --no-interaction
bun run build

echo "Worktree ready at https://$site_name.test"
