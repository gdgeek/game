#!/bin/sh
set -eu

# Accept HTTPS origins only. In particular, never substitute arbitrary Nginx
# syntax, URL credentials, query strings or paths from deployment environment.
validate_origin() {
    case "$1" in
        https://*) authority=${1#https://}; authority=${authority%/} ;;
        *) echo "Frontend backend URL must be an HTTPS origin." >&2; exit 1 ;;
    esac
    case "$authority" in
        ''|*[!A-Za-z0-9.:-]*) echo "Frontend backend URL contains invalid characters." >&2; exit 1 ;;
    esac
    if ! printf '%s\n' "$authority" | LC_ALL=C grep -Eq '^([A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(:[0-9]{1,5})?$'; then
        echo "Frontend backend URL must contain only a hostname and optional port." >&2
        exit 1
    fi
    case "$authority" in
        *:*) port=${authority##*:}
             if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
                 echo "Frontend backend URL contains an invalid port." >&2
                 exit 1
             fi ;;
    esac
    printf '%s' "$authority"
}

api_1_authority=$(validate_origin "${APP_API_1_URL:-https://x.b0.01xr.com}")
api_2_authority=$(validate_origin "${APP_API_2_URL:-https://x.b1.01xr.com}")
export APP_API_1_ORIGIN="https://$api_1_authority"
export APP_API_1_HOST="$api_1_authority"
export APP_API_1_TLS_NAME="${api_1_authority%%:*}"
export APP_API_2_ORIGIN="https://$api_2_authority"
export APP_API_2_HOST="$api_2_authority"
export APP_API_2_TLS_NAME="${api_2_authority%%:*}"

template=${NGINX_FRONTEND_TEMPLATE_PATH:-/etc/nginx/frontend-nginx.conf.template}
output=${NGINX_FRONTEND_OUTPUT_PATH:-/etc/nginx/conf.d/default.conf}
envsubst '${APP_API_1_ORIGIN} ${APP_API_1_HOST} ${APP_API_1_TLS_NAME} ${APP_API_2_ORIGIN} ${APP_API_2_HOST} ${APP_API_2_TLS_NAME}' < "$template" > "$output"
