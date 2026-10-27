#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
repo_root="$(pwd)"
deploy_dir="${repo_root}/deploy"
env_file="${deploy_dir}/.env"

if [ ! -f "$env_file" ]; then
    echo "deploy/.env is missing. Run ./init.sh first." >&2
    exit 1
fi

compose=(docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file")
image_ref="${CBOARD_IMAGE:-ghcr.io/ipevel/cboard:latest}"

echo "Pulling the latest images..."
if ! "${compose[@]}" pull; then
    if docker image inspect "$image_ref" >/dev/null 2>&1; then
        echo "Registry pull failed; continuing with the local image ${image_ref}."
    else
        echo "Registry pull failed and ${image_ref} is not available locally." >&2
        exit 1
    fi
fi

echo "Recreating containers with the new image..."
"${compose[@]}" up -d

echo "Running database migrations and updates..."
"${compose[@]}" exec -T app php artisan v2board:update

echo "Rebuilding the configuration cache..."
"${compose[@]}" exec -T app php artisan config:cache

echo "Update complete."
