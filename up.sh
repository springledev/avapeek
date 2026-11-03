#!/usr/bin/env bash
# Avapeek installer / upgrader. Safe to re-run: existing secrets and values are
# never overwritten (only empty or placeholder values are filled in).
#
#   ./up.sh                                   interactive, pulls prebuilt images
#   ./up.sh --domain photos.example.com --storage garage --yes
#   ./up.sh --build                           build images from source instead
#   ./up.sh --env-only                        only write .env files, start nothing
#   ./up.sh --domain localhost --yes --demo   try it with a made-up family
#
# See docs/installation.md.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

ROOT_ENV=".env"
BACKEND_ENV="backend/.env"
ROOT_EXAMPLE=".env.production.example"
BACKEND_EXAMPLE="backend/.env.production.example"
# Host port published by Caddy in docker-compose.prod.yml.
HTTP_PORT="88"

DOMAIN=""
URL_OVERRIDE=""
STORAGE=""
BUILD=0
YES=0
ENV_ONLY=0
SKIP_CHECK=0
DEMO=0

usage() {
    cat <<'EOF'
Usage: ./up.sh [options]

  --domain HOST      Public hostname, e.g. photos.example.com. Becomes
                     https://HOST (TLS is terminated by your reverse proxy).
                     "localhost" or an IP becomes http://HOST:88.
  --url URL          Full public URL, overrides --domain (e.g. https://photos.example.com).
  --storage MODE     "garage" (bundled self-hosted S3) or "external" (AWS S3, B2, R2, ...).
  --build            Build images from source instead of pulling from GHCR.
  --yes, -y          Never prompt; use flags/defaults.
  --env-only         Write/complete the .env files and exit (also: --dry-run).
  --skip-check       Do not run `php artisan storage:check` at the end.
  --demo             Fill a new instance with a made-up family to try Avapeek out.
                     Refused if real accounts exist. Undo with avapeek:demo-clear.
  -h, --help         Show this help.

Environment: AVAPEEK_VERSION (root .env) selects the image tag, default "latest".
EOF
}

die() {
    echo "error: $*" >&2
    exit 1
}

info() {
    echo "==> $*"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --domain) [ $# -ge 2 ] || die "--domain needs a value"; DOMAIN="$2"; shift 2 ;;
        --url) [ $# -ge 2 ] || die "--url needs a value"; URL_OVERRIDE="$2"; shift 2 ;;
        --storage) [ $# -ge 2 ] || die "--storage needs a value"; STORAGE="$2"; shift 2 ;;
        --build) BUILD=1; shift ;;
        --yes | -y) YES=1; shift ;;
        --env-only | --dry-run) ENV_ONLY=1; shift ;;
        --skip-check) SKIP_CHECK=1; shift ;;
        --demo) DEMO=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

case "${STORAGE}" in
    "" | garage | external) ;;
    *) die "--storage must be 'garage' or 'external'" ;;
esac

# ---------------------------------------------------------------- prerequisites

check_prerequisites() {
    command -v openssl >/dev/null 2>&1 || die "openssl is required to generate secrets. Install it (e.g. 'apt install openssl')."
    [ "${ENV_ONLY}" -eq 1 ] && return 0

    command -v docker >/dev/null 2>&1 || die "docker is not installed. See https://docs.docker.com/engine/install/"
    docker info >/dev/null 2>&1 || die "cannot talk to the Docker daemon. Is it running, and is your user in the 'docker' group?"

    local version major minor
    version="$(docker compose version --short 2>/dev/null || true)"
    [ -n "${version}" ] || die "the Docker Compose v2 plugin is missing. Install 'docker-compose-plugin' (need >= 2.20)."
    version="${version#v}"
    major="${version%%.*}"
    minor="${version#*.}"
    minor="${minor%%.*}"
    if [ "${major}" -lt 2 ] || { [ "${major}" -eq 2 ] && [ "${minor}" -lt 20 ]; }; then
        die "Docker Compose ${version} is too old; need >= 2.20 (optional depends_on is used)."
    fi
}

# ------------------------------------------------------------------ env helpers

# get_var FILE KEY -> value (quotes stripped), empty if missing.
get_var() {
    local value
    value="$(grep -E "^$2=" "$1" 2>/dev/null | head -n 1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    printf '%s' "${value}"
}

# set_var FILE KEY VALUE: replace the first KEY= line or append one. The value
# is passed through the environment so no sed delimiter or escaping issues
# (base64 contains / and +).
set_var() {
    local file="$1" key="$2" value="$3" tmp
    tmp="$(mktemp)"
    if grep -qE "^${key}=" "${file}"; then
        KEY="${key}" VALUE="${value}" awk '
            BEGIN { k = ENVIRON["KEY"] "="; done = 0 }
            !done && index($0, k) == 1 { print k ENVIRON["VALUE"]; done = 1; next }
            { print }
        ' "${file}" >"${tmp}"
    else
        cat "${file}" >"${tmp}"
        printf '%s=%s\n' "${key}" "${value}" >>"${tmp}"
    fi
    cat "${tmp}" >"${file}"
    rm -f "${tmp}"
}

is_placeholder() {
    case "$1" in
        "" | change-me-please | "base64:YOUR_GENERATED_KEY") return 0 ;;
        *) return 1 ;;
    esac
}

# set_if_empty FILE KEY VALUE: only fills empty/placeholder values.
set_if_empty() {
    if is_placeholder "$(get_var "$1" "$2")"; then
        set_var "$1" "$2" "$3"
        return 0
    fi
    return 1
}

# replace_if_equals FILE KEY OLD NEW: change a shipped default, keep user edits.
replace_if_equals() {
    if [ "$(get_var "$1" "$2")" = "$3" ]; then
        set_var "$1" "$2" "$4"
    fi
}

gen_hex() { openssl rand -hex "$1"; }

prompt() {
    # prompt "Question" "default" -> echoes answer
    local answer=""
    if [ "${YES}" -eq 0 ] && [ -t 0 ]; then
        read -r -p "$1 [$2]: " answer || true
    fi
    printf '%s' "${answer:-$2}"
}

url_for_domain() {
    case "$1" in
        localhost | 127.* | *:* | [0-9]*.[0-9]*.[0-9]*.[0-9]*) printf 'http://%s' "$1" ;;
        *) printf 'https://%s' "$1" ;;
    esac
}

# ------------------------------------------------------------------ env setup

prepare_env() {
    [ -f "${ROOT_EXAMPLE}" ] || die "${ROOT_EXAMPLE} not found; run from the repository root."
    [ -f "${BACKEND_EXAMPLE}" ] || die "${BACKEND_EXAMPLE} not found."

    local fresh_root=0
    if [ ! -f "${ROOT_ENV}" ]; then
        cp "${ROOT_EXAMPLE}" "${ROOT_ENV}"
        fresh_root=1
        info "created ${ROOT_ENV}"
    fi
    if [ ! -f "${BACKEND_ENV}" ]; then
        cp "${BACKEND_EXAMPLE}" "${BACKEND_ENV}"
        info "created ${BACKEND_ENV}"
    fi
    chmod 600 "${ROOT_ENV}" "${BACKEND_ENV}"

    # --- storage mode: flag > existing COMPOSE_PROFILES > prompt/default garage
    if [ -z "${STORAGE}" ]; then
        if [ "${fresh_root}" -eq 0 ]; then
            case "$(get_var "${ROOT_ENV}" COMPOSE_PROFILES)" in
                *garage*) STORAGE="garage" ;;
                *) STORAGE="external" ;;
            esac
        else
            STORAGE="$(prompt 'Storage: "garage" (bundled, self-hosted) or "external" (S3/B2/R2)' garage)"
            case "${STORAGE}" in garage | external) ;; *) die "storage must be 'garage' or 'external'" ;; esac
        fi
    fi

    # --- public URL: flag > existing real value > prompt
    local app_url=""
    if [ -n "${URL_OVERRIDE}" ]; then
        app_url="${URL_OVERRIDE%/}"
    elif [ -n "${DOMAIN}" ]; then
        app_url="$(url_for_domain "${DOMAIN}")"
    else
        local current
        current="$(get_var "${BACKEND_ENV}" APP_URL)"
        if [ -z "${current}" ] || [ "${current}" = "https://your-domain.com" ]; then
            DOMAIN="$(prompt 'Public domain (use "localhost" for a local trial)' localhost)"
            app_url="$(url_for_domain "${DOMAIN}")"
        fi
    fi
    case "${app_url}" in
        http://localhost | http://127.*) app_url="${app_url}:${HTTP_PORT}" ;;
    esac
    # A plain-http host with no explicit port still reaches Caddy on HTTP_PORT.
    if [ -n "${DOMAIN}" ] && [ -z "${URL_OVERRIDE}" ]; then
        case "${app_url}" in
            http://*:*) ;;
            http://*) app_url="${app_url}:${HTTP_PORT}" ;;
        esac
    fi

    # --- root .env
    set_if_empty "${ROOT_ENV}" DB_PASSWORD "$(gen_hex 24)" || true
    set_if_empty "${ROOT_ENV}" REDIS_PASSWORD "$(gen_hex 24)" || true
    if [ "${STORAGE}" = "garage" ]; then
        set_var "${ROOT_ENV}" COMPOSE_PROFILES garage
        set_if_empty "${ROOT_ENV}" GARAGE_RPC_SECRET "$(gen_hex 32)" || true
        set_if_empty "${ROOT_ENV}" GARAGE_ADMIN_TOKEN "$(gen_hex 32)" || true
        set_if_empty "${ROOT_ENV}" S3_ACCESS_KEY_ID "GK$(gen_hex 12)" || true
        set_if_empty "${ROOT_ENV}" S3_SECRET_ACCESS_KEY "$(gen_hex 32)" || true
    else
        # Only drop the garage profile; a user's other profiles are kept.
        if get_var "${ROOT_ENV}" COMPOSE_PROFILES | grep -q garage; then
            set_var "${ROOT_ENV}" COMPOSE_PROFILES ""
        fi
    fi

    # --- backend/.env
    if is_placeholder "$(get_var "${BACKEND_ENV}" APP_KEY)"; then
        set_var "${BACKEND_ENV}" APP_KEY "base64:$(openssl rand -base64 32)"
    fi
    set_var "${BACKEND_ENV}" DB_DATABASE "$(get_var "${ROOT_ENV}" DB_DATABASE)"
    set_var "${BACKEND_ENV}" DB_USERNAME "$(get_var "${ROOT_ENV}" DB_USERNAME)"
    set_if_empty "${BACKEND_ENV}" DB_PASSWORD "$(get_var "${ROOT_ENV}" DB_PASSWORD)" || true
    set_if_empty "${BACKEND_ENV}" REDIS_PASSWORD "$(get_var "${ROOT_ENV}" REDIS_PASSWORD)" || true

    if [ -n "${app_url}" ]; then
        set_var "${BACKEND_ENV}" APP_URL "${app_url}"
        set_var "${BACKEND_ENV}" FRONTEND_URL "${app_url}"
    else
        set_if_empty "${BACKEND_ENV}" FRONTEND_URL "$(get_var "${BACKEND_ENV}" APP_URL)" || true
    fi

    if [ "${STORAGE}" = "garage" ]; then
        # Keep root S3_* and backend AWS_* identical. The root values are the
        # source of truth unless only the backend ones were customised.
        set_if_empty "${BACKEND_ENV}" AWS_ACCESS_KEY_ID "$(get_var "${ROOT_ENV}" S3_ACCESS_KEY_ID)" || true
        set_if_empty "${BACKEND_ENV}" AWS_SECRET_ACCESS_KEY "$(get_var "${ROOT_ENV}" S3_SECRET_ACCESS_KEY)" || true
        set_var "${ROOT_ENV}" S3_ACCESS_KEY_ID "$(get_var "${BACKEND_ENV}" AWS_ACCESS_KEY_ID)"
        set_var "${ROOT_ENV}" S3_SECRET_ACCESS_KEY "$(get_var "${BACKEND_ENV}" AWS_SECRET_ACCESS_KEY)"
        set_var "${BACKEND_ENV}" AWS_TEMPORARY_URL "$(get_var "${BACKEND_ENV}" APP_URL)"
        set_if_empty "${BACKEND_ENV}" STORAGE_VOLUME_PATH "/garage-data" || true
    else
        # Undo Garage-only shipped defaults; explicit user values are kept.
        replace_if_equals "${BACKEND_ENV}" STORAGE_VOLUME_PATH "/garage-data" ""
        replace_if_equals "${BACKEND_ENV}" AWS_ENDPOINT "http://garage:3900" ""
        replace_if_equals "${BACKEND_ENV}" AWS_USE_PATH_STYLE_ENDPOINT "true" "false"
        if [ "$(get_var "${BACKEND_ENV}" AWS_TEMPORARY_URL)" = '${APP_URL}' ] \
            || [ "$(get_var "${BACKEND_ENV}" AWS_TEMPORARY_URL)" = "$(get_var "${BACKEND_ENV}" APP_URL)" ]; then
            set_var "${BACKEND_ENV}" AWS_TEMPORARY_URL ""
        fi
    fi

}

report_external_todo() {
    local missing=""
    for key in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_BUCKET AWS_DEFAULT_REGION; do
        if [ -z "$(get_var "${BACKEND_ENV}" "${key}")" ]; then
            missing="${missing} ${key}"
        fi
    done
    if [ "$(get_var "${BACKEND_ENV}" AWS_DEFAULT_REGION)" = "garage" ]; then
        echo "warning: AWS_DEFAULT_REGION is still 'garage'; set your provider's region."
    fi
    if [ "$(get_var "${BACKEND_ENV}" AWS_BUCKET)" = "photos" ]; then
        echo "warning: AWS_BUCKET is still the shipped default 'photos'; use your bucket name."
    fi
    case "$(get_var "${BACKEND_ENV}" AWS_ACCESS_KEY_ID)" in
        GK*) echo "warning: AWS_ACCESS_KEY_ID looks like a Garage-generated key (GK...); replace it with your provider's key." ;;
    esac
    echo
    echo "External storage selected. Edit ${BACKEND_ENV} and set AWS_ACCESS_KEY_ID,"
    echo "AWS_SECRET_ACCESS_KEY, AWS_BUCKET, AWS_DEFAULT_REGION and (B2/R2) AWS_ENDPOINT,"
    echo "then configure bucket CORS. See docs/storage.md."
    if [ -n "${missing}" ]; then
        echo "Still empty:${missing}"
    fi
}

# --------------------------------------------------------------------- run

check_prerequisites
prepare_env

if [ "${ENV_ONLY}" -eq 1 ]; then
    info "env files are ready (${ROOT_ENV}, ${BACKEND_ENV}); nothing started (--env-only)."
    exit 0
fi

COMPOSE=(docker compose -f docker-compose.prod.yml)
if [ "${BUILD}" -eq 0 ]; then
    COMPOSE+=(-f docker-compose.release.yml)
fi

if [ "${STORAGE}" = "external" ]; then
    report_external_todo
    for key in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_BUCKET; do
        if [ -z "$(get_var "${BACKEND_ENV}" "${key}")" ]; then
            die "${key} is empty in ${BACKEND_ENV}; fill in your S3 credentials and re-run ./up.sh."
        fi
    done
fi

if [ "${BUILD}" -eq 1 ]; then
    info "building images from source and starting the stack"
    "${COMPOSE[@]}" up -d --build --remove-orphans
else
    info "pulling images from ghcr.io/springledev (tag: $(get_var "${ROOT_ENV}" AVAPEEK_VERSION | sed 's/^$/latest/'))"
    "${COMPOSE[@]}" pull || die "could not pull images. Check your network, or use './up.sh --build' to build from source."
    "${COMPOSE[@]}" up -d --no-build --remove-orphans
fi

info "waiting for the backend to become healthy (/up)"
ready=0
for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T backend wget -q -O /dev/null http://localhost/up 2>/dev/null; then
        ready=1
        break
    fi
    sleep 2
done
if [ "${ready}" -ne 1 ]; then
    "${COMPOSE[@]}" logs --tail=40 backend || true
    die "backend did not become healthy within 120s. See the logs above."
fi

info "running database migrations"
"${COMPOSE[@]}" exec -T backend php artisan migrate --force

# The backend entrypoint already runs config:cache and route:cache in production
# on every container start, so no explicit caching step is needed here.

if [ "${SKIP_CHECK}" -eq 0 ]; then
    info "checking object storage"
    if ! "${COMPOSE[@]}" exec -T backend php artisan storage:check; then
        echo "warning: storage:check failed. The app is up, but uploads may not work." >&2
        echo "         See docs/storage.md and docs/faq.md." >&2
    fi
fi

app_url="$(get_var "${BACKEND_ENV}" APP_URL)"

if [ "${DEMO}" -eq 1 ]; then
    info "adding the demo family"
    if "${COMPOSE[@]}" exec -T backend php artisan avapeek:demo-seed; then
        echo
        echo "Avapeek is running with demo data: ${app_url}"
        echo "To start for real: docker compose -f docker-compose.prod.yml exec backend php artisan avapeek:demo-clear"
        echo "then register your first account. To remove everything instead, see docs/installation.md#uninstall"
        exit 0
    fi
    echo "warning: demo data was not added. See the message above." >&2
fi

echo
echo "Avapeek is running: ${app_url}"
echo "Open it and register the first account (the first user can register freely;"
echo "after that registration is invite-only)."
echo "Back up ${ROOT_ENV}, ${BACKEND_ENV} and the database: docs/installation.md#backups"
