#!/bin/sh
set -eu

# The Web renderer runs first. Opt-in adds a separate stateless mini-program
# API gateway upstream without changing fixed Web Session routing.
case "${APP_MP_API_ENABLED:-0}" in
    0) exit 0 ;;
    1) ;;
    *) echo "APP_MP_API_ENABLED must be 0 or 1." >&2; exit 1 ;;
esac

validate_hostname() {
    if ! printf '%s\n' "$1" | LC_ALL=C grep -Eq '^([A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$'; then
        echo "Mini-program upstream must contain a hostname only." >&2
        exit 1
    fi
    # grep is line-oriented, so explicitly reject whitespace and delimiters.
    case "$1" in
        ''|*[!A-Za-z0-9.-]*) echo "Mini-program hostname contains invalid characters." >&2; exit 1 ;;
    esac
    if ! printf '%s\n' "$1" | LC_ALL=C awk '{
        if (length($0) > 253) exit 1
        n = split($0, labels, "[.]")
        for (i = 1; i <= n; i++) if (length(labels[i]) > 63) exit 1
    }'; then
        echo "Mini-program hostname is oversized." >&2
        exit 1
    fi
}

validate_origin() {
    case "$1" in
        https://*) authority=${1#https://}; authority=${authority%/} ;;
        *) echo "Mini-program upstream URL must be an HTTPS origin." >&2; exit 1 ;;
    esac
    case "$authority" in
        ''|*[!A-Za-z0-9.:-]*) echo "Mini-program origin contains invalid characters." >&2; exit 1 ;;
    esac
    case "$authority" in
        *:*) hostname=${authority%:*}; port=${authority##*:} ;;
        *) hostname=$authority; port=443 ;;
    esac
    validate_hostname "$hostname"
    case "$port" in
        ''|*[!0-9]*) echo "Mini-program origin port is invalid." >&2; exit 1 ;;
    esac
    if [ "${#port}" -gt 5 ] || [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
        echo "Mini-program origin port is invalid." >&2
        exit 1
    fi
    port=$(awk -v value="$port" 'BEGIN { printf "%d", value }')
    printf '%s:%s' "$hostname" "$port"
}

validate_resolver() {
    # Docker's embedded DNS is the default; permit a strictly numeric IPv4
    # endpoint override for local fixtures and deployments with another DNS.
    case "$1" in
        ''|*[!0-9.:]*) echo "Mini-program DNS resolver must be an IPv4 endpoint." >&2; exit 1 ;;
    esac
    if ! printf '%s\n' "$1" | LC_ALL=C awk -F: '
        NF > 2 { exit 1 }
        NF == 2 && ($2 !~ /^[0-9]+$/ || length($2) > 5 || $2 < 1 || $2 > 65535) { exit 1 }
        {
            if (split($1, octets, "[.]") != 4) exit 1
            for (i = 1; i <= 4; i++) {
                if (octets[i] !~ /^[0-9]+$/ || length(octets[i]) > 3 || octets[i] > 255) exit 1
            }
        }'; then
        echo "Mini-program DNS resolver is invalid." >&2
        exit 1
    fi
    printf '%s' "$1"
}

MP_PRIMARY_AUTHORITY=$(validate_origin "${APP_MP_API_PRIMARY_URL:-}")
export MP_PRIMARY_AUTHORITY
MP_BACKUP_AUTHORITY=$(validate_origin "${APP_MP_API_BACKUP_URL:-}")
export MP_BACKUP_AUTHORITY
MP_UPSTREAM_HOST=${APP_MP_API_UPSTREAM_HOST:-}
validate_hostname "$MP_UPSTREAM_HOST"
export MP_UPSTREAM_HOST
MP_RESOLVER=$(validate_resolver "${APP_MP_API_RESOLVER-127.0.0.11}")
export MP_RESOLVER

output=${NGINX_FRONTEND_OUTPUT_PATH:-/etc/nginx/conf.d/default.conf}
if [ "$(grep -c '^# MINIPROGRAM_HTTP_CONFIG$' "$output")" != 1 ] ||
   [ "$(grep -c '^[[:space:]]*# MINIPROGRAM_ROUTES$' "$output")" != 1 ]; then
    echo "Mini-program proxy requires the frontend renderer template markers." >&2
    exit 1
fi
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM

envsubst '${MP_PRIMARY_AUTHORITY} ${MP_BACKUP_AUTHORITY} ${MP_RESOLVER}' <<'HTTP' > "$work/http"
# DNS resolution is refreshed without container restart. Shared passive failure
# state sends subsequent requests to B1 while B0 is quarantined for ten seconds.
upstream miniprogram_api {
    zone miniprogram_api 256k;
    resolver ${MP_RESOLVER} valid=30s ipv6=off;
    resolver_timeout 5s;
    server ${MP_PRIMARY_AUTHORITY} resolve max_fails=1 fail_timeout=10s;
    server ${MP_BACKUP_AUTHORITY} resolve max_fails=1 fail_timeout=10s backup;
}

# Audited against DeviceController/DeviceAccess/Device and ServerController/A1Server.
# Device query expansion can create a missing setup, so only query-free device
# reads qualify. Scenes ignores incoming query parameters and reads a fixed URL.
# Match the original URI so encoded or normalized aliases do not gain retries.
map "$request_method:$request_uri" $mp_api_read_route {
    default 0;
    "~^(GET|HEAD):(/api)?/v2/devices(/(manage|[1-9][0-9]*))?$" 1;
    "~^(GET|HEAD):(/api)?/v2/server/scenes([?].*)?$" 1;
}

# Yii accepts a method override header even on GET/HEAD. Preserve that header
# for single-attempt forwarding, but do not grant such requests read retries.
# An exact second map avoids treating arbitrary header text as part of a regex.
map "$mp_api_read_route:$http_x_http_method_override" $mp_api_read_only {
    default 0;
    "1:" 1;
}

# Strip only the literal gateway prefix from the original URI. Using $uri or
# rewrite here would decode/normalize paths and change encoded business input.
map $request_uri $mp_api_target {
    default $request_uri;
    "~^/api(?<mp_api_raw_suffix>/.*)$" $mp_api_raw_suffix;
}
map $request_uri $mp_api_literal_prefix {
    default 0;
    "~^/api/" 1;
}
map $request_uri $mp_api_redirect_prefix {
    default "";
    "~^/api/" /api;
}

# ^~ /api/ bypasses server regex locations. Check normalized paths explicitly,
# including encoded aliases and PATH_INFO after a source/config extension.
map $uri $mp_api_blocked_path {
    default 0;
    "~(^|/)[.]" 1;
    "~*[.](php|phar|phtml|ini|log|sql|env|map|ya?ml|conf)(/|$)" 1;
    "~*(^|/)(config|composer|package|package-lock|tsconfig)[.]json(/|$)" 1;
    "~*(^|/)(debug|gii)(/|$)" 1;
}
HTTP

# Reuse the stateless TLS/header contract for prefixed and legacy requests.
envsubst '${MP_UPSTREAM_HOST} ${MP_PRIMARY_AUTHORITY} ${MP_BACKUP_AUTHORITY}' <<'PROXY' > "$work/proxy"
        proxy_pass https://miniprogram_api$mp_api_target;
        # Keep same-origin HTTP redirects under /api without touching HTML.
        absolute_redirect off;
        proxy_redirect https://${MP_UPSTREAM_HOST}/ $mp_api_redirect_prefix/;
        proxy_redirect https://${MP_PRIMARY_AUTHORITY}/ $mp_api_redirect_prefix/;
        proxy_redirect https://${MP_BACKUP_AUTHORITY}/ $mp_api_redirect_prefix/;
        proxy_redirect ~^(?<mp_api_redirect_path>/(?!/).*)$ $mp_api_redirect_prefix$mp_api_redirect_path;
        proxy_set_header Host ${MP_UPSTREAM_HOST};
        proxy_ssl_name ${MP_UPSTREAM_HOST};
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie "";
        proxy_hide_header Set-Cookie;
PROXY
{
    cat <<'API'
    location ^~ /api/ {
        if ($mp_api_literal_prefix = 0) { return 404; }
        if ($mp_api_blocked_path) { return 404; }
        error_page 418 = @miniprogram_no_retry;
        if ($mp_api_read_only = 0) { return 418; }
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_next_upstream_timeout 25s;
API
    cat "$work/proxy"
    cat <<'API_END'
    }

API_END
    cat <<'READ'
    location ^~ /v2/ {
        # A named internal redirect preserves the original method, body and URI.
        error_page 418 = @miniprogram_no_retry;
        if ($mp_api_read_only = 0) { return 418; }
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_next_upstream_timeout 25s;
READ
    cat "$work/proxy"
    cat <<'WRITE'
    }

    location @miniprogram_no_retry {
        # No replay, even when a connection fails before sending a POST, or
        # when Nginx would otherwise consider a PUT or DELETE idempotent.
        proxy_next_upstream off;
WRITE
    cat "$work/proxy"
    printf '    }\n\n'
    for health_path in /mp-health /api/health; do
        printf '    location = %s {\n' "$health_path"
    cat <<'HEALTH'
        limit_except GET { deny all; }
        set $args "";
        proxy_pass https://miniprogram_api/health;
        proxy_method GET;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header X-Http-Method-Override "";
        proxy_set_header Authorization "";
        proxy_set_header Cookie "";
        proxy_hide_header Set-Cookie;
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_next_upstream_timeout 25s;
HEALTH
    envsubst '${MP_UPSTREAM_HOST}' <<'HEALTH_HEADERS'
        proxy_set_header Host ${MP_UPSTREAM_HOST};
        proxy_ssl_name ${MP_UPSTREAM_HOST};
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
    }
HEALTH_HEADERS
    done
} > "$work/routes"

# Validate and buffer all generated content before replacing the Web config.
awk -v http="$work/http" -v routes="$work/routes" '
    /^# MINIPROGRAM_HTTP_CONFIG$/ {
        while ((getline line < http) > 0) print line
        close(http)
        next
    }
    /^[[:space:]]*# MINIPROGRAM_ROUTES$/ {
        while ((getline line < routes) > 0) print line
        close(routes)
        next
    }
    { print }
' "$output" > "$work/config"
cat "$work/config" > "$output"
