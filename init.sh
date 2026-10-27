#!/usr/bin/env bash
set -euo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
repo_root="$(pwd)"
deploy_dir="${repo_root}/deploy"

# ---------------------------------------------------------------------------
# Cboard one-command Docker installer
# ---------------------------------------------------------------------------

# --- 1. Preflight: docker + compose v2 --------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is not installed. Install Docker Engine first, then re-run this script." >&2
    echo "  https://docs.docker.com/engine/install/" >&2
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is not available. Install the compose plugin, then re-run this script." >&2
    echo "  https://docs.docker.com/compose/install/" >&2
    exit 1
fi

mkdir -p "${deploy_dir}/storage" "${deploy_dir}/plugins" "${deploy_dir}/storage/framework/cache" \
    "${deploy_dir}/storage/framework/sessions" "${deploy_dir}/storage/framework/views" "${deploy_dir}/storage/logs"

env_file="${deploy_dir}/.env"

# --- 2. Prepare deploy/.env -------------------------------------------------
if [ ! -f "$env_file" ]; then
    echo "Creating deploy/.env from .env.example..."

    app_key="base64:$(openssl rand -base64 32)"
    db_password="$(openssl rand -hex 16)"
    mysql_root_password="$(openssl rand -hex 16)"

    server_ip="${CBOARD_HOST:-}"
    if [ -z "$server_ip" ]; then
        server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
        [ -z "$server_ip" ] && server_ip="127.0.0.1"
    fi
    port="${CBOARD_PORT:-7001}"
    app_url="http://${server_ip}:${port}"

    cp "${repo_root}/.env.example" "$env_file"
    sed -i \
        -e "s|^APP_KEY=.*|APP_KEY=${app_key}|" \
        -e "s|^APP_URL=.*|APP_URL=${app_url}|" \
        -e "s|^DB_HOST=.*|DB_HOST=mysql|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=cboard|" \
        -e "s|^DB_USERNAME=.*|DB_USERNAME=cboard|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${db_password}|" \
        -e "s|^REDIS_HOST=.*|REDIS_HOST=redis|" \
        -e "s|^REDIS_PORT=.*|REDIS_PORT=6379|" \
        "$env_file"

    {
        echo ""
        echo "# Docker stack settings (generated once)."
        echo "CBOARD_PORT=${port}"
        echo "MYSQL_ROOT_PASSWORD=${mysql_root_password}"
        echo "MYSQL_DATABASE=cboard"
        echo "MYSQL_USERNAME=cboard"
        echo "MYSQL_PASSWORD=${db_password}"
    } >> "$env_file"

    echo "Generated deploy/.env with random credentials."
else
    echo "deploy/.env already exists; reusing it."
fi

# Read values back from the environment file for the health check and summary.
db_password="$(grep -E '^DB_PASSWORD=' "$env_file" | cut -d= -f2- | tr -d '"' || true)"
mysql_root_password="$(grep -E '^MYSQL_ROOT_PASSWORD=' "$env_file" | cut -d= -f2- | tr -d '"' || true)"
app_url="$(grep -E '^APP_URL=' "$env_file" | cut -d= -f2- | tr -d '"' || true)"

# Persist the host port so later updates reuse it.
if ! grep -q '^CBOARD_PORT=' "$env_file"; then
    { echo "CBOARD_PORT=${CBOARD_PORT:-7001}"; } >> "$env_file"
fi

# Make an explicitly supplied port win and keep APP_URL in step with it.
if [ -n "${CBOARD_PORT:-}" ] && ! grep -q "^CBOARD_PORT=${CBOARD_PORT}$" "$env_file"; then
    sed -i "s|^CBOARD_PORT=.*|CBOARD_PORT=${CBOARD_PORT}|" "$env_file"
fi

# --- 3. Start the stack -----------------------------------------------------
echo "Pulling images and starting the stack..."
docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file" up -d

# --- 4. Wait for MySQL, then run the installer ------------------------------
echo "Waiting for MySQL to accept connections..."
for i in $(seq 1 120); do
    if docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file" \
        exec -T mysql mysqladmin ping -h 127.0.0.1 -u root -p"${mysql_root_password}" >/dev/null 2>&1; then
        break
    fi
    sleep 2
    if [ "$i" -eq 120 ]; then
        echo "MySQL did not become ready in time. Check 'docker compose logs mysql'." >&2
        exit 1
    fi
done
echo "MySQL is ready."

# Skip initialization when an administrator already exists (idempotent re-run).
db_name="$(grep -E '^DB_DATABASE=' "$env_file" | cut -d= -f2- | tr -d '"' || true)"
db_name="${db_name:-cboard}"
admin_count="$(docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file" \
    exec -T mysql mysql -N -B -u root -p"${mysql_root_password}" \
    -e "SELECT COUNT(*) FROM \`${db_name}\`.v2_user WHERE is_admin = 1" 2>/dev/null | tr -d '[:space:]' || true)"
if [ -n "$admin_count" ] && [ "$admin_count" != "0" ]; then
    echo "An administrator already exists; the panel is already installed."
    echo "Use ./update.sh to apply updates."
    exit 0
fi

admin_email="${ADMIN_ACCOUNT:-}"
if [ -z "$admin_email" ]; then
    read -r -p "Enter the administrator email: " admin_email
fi
if [ -z "$admin_email" ]; then
    echo "An administrator email is required." >&2
    exit 1
fi

echo "Running the installer..."
install_output="$(printf '%s\n' "$admin_email" | \
    docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file" \
    exec -T app php artisan v2board:install --resume 2>&1)" || {
    echo "Installer failed:" >&2
    echo "$install_output" >&2
    exit 1
}
echo "$install_output"

# --- 5. Print the result ----------------------------------------------------
secure_path="$(docker compose -f "${deploy_dir}/compose.yaml" --env-file "$env_file" \
    exec -T app php -r '$e=file_get_contents("/www/.env"); preg_match("/^APP_KEY=(.*)$/m",$e,$m); echo hash("crc32b", trim($m[1]));' \
    2>/dev/null || true)"

echo
echo "================================================================"
echo " Cboard is installed and running."
echo "   Site:           ${app_url}"
echo "   Admin panel:    ${app_url}/${secure_path}"
echo "   Admin email:    ${admin_email}"
echo "   Admin password: printed by the installer above (shown once)."
echo "================================================================"
echo "Start the scheduler in cron if you need background tasks:"
echo "  * * * * * docker compose -f ${deploy_dir}/compose.yaml exec -T app php artisan schedule:run"
echo "Update later with: ./update.sh"
