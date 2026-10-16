#!/bin/sh
set -eu

# Accept HTTPS origins only. Never substitute arbitrary Nginx syntax, URL
# credentials, query strings or paths from deployment environment.
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
    if ! printf '%s\n' "${authority%%:*}" | LC_ALL=C awk '{
        if (length($0) > 253) exit 1
        count = split($0, labels, "[.]")
        for (i = 1; i <= count; i++) if (length(labels[i]) > 63) exit 1
    }'; then
        echo "Frontend backend URL contains an oversized hostname or label." >&2
        exit 1
    fi
    case "$authority" in
        *:*) port=${authority##*:}
             if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
                 echo "Frontend backend URL contains an invalid port." >&2
                 exit 1
             fi
             port=$(awk -v value="$port" 'BEGIN { printf "%d", value }')
             host=${authority%:*}
             if [ "$port" -ne 443 ]; then authority="$host:$port"; else authority=$host; fi ;;
    esac
    printf '%s' "$authority"
}

validate_authority() {
    case "$1" in
        ''|*[!A-Za-z0-9.:-]*) echo "Frontend upstream Host must be a hostname and optional port." >&2; exit 1 ;;
    esac
    if ! printf '%s\n' "$1" | LC_ALL=C grep -Eq '^([A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(:[0-9]{1,5})?$'; then
        echo "Frontend upstream Host must be a hostname and optional port." >&2
        exit 1
    fi
    case "$1" in
        *:*) port=${1##*:}
             if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
                 echo "Frontend upstream Host contains an invalid port." >&2
                 exit 1
             fi
             port=$(awk -v value="$port" 'BEGIN { printf "%d", value }')
             host=${1%:*}
             if [ "$port" -ne 443 ]; then printf '%s:%s' "$host" "$port"; else printf '%s' "$host"; fi ;;
        *) printf '%s' "$1" ;;
    esac
}

validate_resolver() {
    case "$1" in
        ''|*[!0-9.:]*) echo "Frontend DNS resolver must be an IPv4 endpoint." >&2; exit 1 ;;
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
        echo "Frontend DNS resolver is invalid." >&2
        exit 1
    fi
    printf '%s' "$1"
}

template=${NGINX_FRONTEND_TEMPLATE_PATH:-/etc/nginx/frontend-nginx.conf.template}
output=${NGINX_FRONTEND_OUTPUT_PATH:-/etc/nginx/conf.d/default.conf}
runtime_config=${NGINX_FRONTEND_RUNTIME_CONFIG_PATH:-/usr/share/nginx/html/runtime-config.json}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM

# Read names from ENVIRON, never eval deployment input or parse values as lines.
# Reject aliases and indices beyond JavaScript's safe integer range so browser
# and proxy route IDs stay identical even when deployment slots have gaps.
awk 'BEGIN {
    for (key in ENVIRON) {
        if (key !~ /^APP_API_[0-9]+_(URL|WEIGHT)$/) continue
        split(key, parts, "_")
        slot = parts[3]
        field = parts[4]
        if (slot !~ /^[1-9][0-9]*$/ || length(slot) > 16 ||
            (length(slot) == 16 && ("x" slot) > "x9007199254740991")) {
            print "Frontend backend setting has an invalid slot number." > "/dev/stderr"
            exit 1
        }
        if (field == "URL") urls[slot] = key
        else {
            weight = ENVIRON[key]
            if (weight !~ /^[1-9][0-9]*$/ || length(weight) > 7 || weight > 1000000) {
                print "Frontend backend weight must be a positive integer no greater than 1000000." > "/dev/stderr"
                exit 1
            }
            weights[slot] = weight
        }
    }
    count = 0
    for (slot in urls) {
        weight = (slot in weights) ? weights[slot] : 1
        printf "%s %s %s\n", slot, urls[slot], weight
        count++
    }
    for (slot in weights) if (!(slot in urls)) {
        print "Frontend backend weight has no matching APP_API_N_URL." > "/dev/stderr"
        exit 1
    }
    if (count == 0) {
        print "At least one APP_API_N_URL HTTPS origin is required." > "/dev/stderr"
        exit 1
    }
}' > "$work/unsorted"
LC_ALL=C sort -n "$work/unsorted" > "$work/slots"

# Use two decimal places for the split ranges and reserve the final remainder
# with '*', so rounding can never make the percentages exceed 100 percent.
awk '{ slots[NR]=$1; weights[NR]=$3; sum += $3 }
    END {
        for (i = 1; i <= NR; i++) {
            id = "b" (slots[i] - 1)
            if (weights[i] * 10000 < sum) {
                print "Each APP_API_N_WEIGHT must receive at least 0.01% of the request split." > "/dev/stderr"
                exit 1
            }
            if (i == NR) printf "* api_pool_%s;\n", id
            else printf "%.2f%% api_pool_%s;\n", int(weights[i] * 10000 / sum) / 100, id
        }
    }' "$work/slots" > "$work/splits"

# Fixed per-backend aliases remain available for diagnostics and older clients.
render_admin_route() {
    envsubst '${BACKEND_ORIGIN} ${BACKEND_HOST} ${BACKEND_TLS_NAME} ${BACKEND_ROUTE_PREFIX} ${BACKEND_COOKIE_PATH}' <<'ADMIN' >> "$work/routes"
    location ^~ ${BACKEND_ROUTE_PREFIX}/admin-api/ {
        proxy_pass ${BACKEND_ORIGIN}/admin-api/;
        proxy_next_upstream off;
        proxy_set_header Host ${BACKEND_HOST};
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_ssl_name ${BACKEND_TLS_NAME};
        proxy_cookie_path ~^/.*$ ${BACKEND_COOKIE_PATH};
        proxy_cookie_domain ~. $host;
        proxy_cookie_flags ~ secure httponly samesite=lax;
        proxy_redirect ${BACKEND_ORIGIN}/admin-api/ ${BACKEND_ROUTE_PREFIX}/admin-api/;
        proxy_redirect /admin-api/ ${BACKEND_ROUTE_PREFIX}/admin-api/;
    }

ADMIN
}

render_health_route() {
    envsubst '${BACKEND_ORIGIN} ${BACKEND_HOST} ${BACKEND_TLS_NAME} ${BACKEND_ROUTE_PREFIX}' <<'HEALTH' >> "$work/routes"
    # Health probes never carry a login cookie, authorization or write body.
    location = ${BACKEND_ROUTE_PREFIX}/health {
        limit_except GET { deny all; }
        set $args "";
        proxy_pass ${BACKEND_ORIGIN}/health;
        proxy_method GET;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header Cookie "";
        proxy_set_header Authorization "";
        proxy_set_header X-Http-Method-Override "";
        proxy_set_header Host ${BACKEND_HOST};
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_ssl_name ${BACKEND_TLS_NAME};
        proxy_hide_header Set-Cookie;
    }

HEALTH
}


API_RESOLVER=$(validate_resolver "${APP_API_RESOLVER:-127.0.0.11}")
export API_RESOLVER

: > "$work/http"
: > "$work/routes"
b0_found=0
first_authority=''
first_slot=''
separator=''
while read -r slot variable weight; do
    # The sentinel preserves trailing newlines so validation rejects them.
    origin=$(awk -v key="$variable" 'BEGIN { printf "%s.", ENVIRON[key] }')
    origin=${origin%.}
    authority=$(validate_origin "$origin")
    [ -n "$first_authority" ] || { first_authority=$authority; first_slot=$slot; }
    API_SERVER_AUTHORITY=$authority
    case "$API_SERVER_AUTHORITY" in *:*) ;; *) API_SERVER_AUTHORITY="$API_SERVER_AUTHORITY:443" ;; esac
    export BACKEND_ORIGIN="https://$authority"
    export BACKEND_HOST="$authority"
    export BACKEND_TLS_NAME="${authority%%:*}"
    export BACKEND_ID="b$((slot - 1))"
    public_host=$authority
    case "$authority" in
        *:*) public_port=${authority##*:}
             public_host=${authority%%:*}
             if [ "$public_port" -ne 443 ]; then public_host="$public_host:$public_port"; fi ;;
    esac

    # Each weighted bucket puts its chosen backend first and all other peers in
    # Nginx's backup tier. The named upstream map below makes split_clients the
    # actual route selector while keeping DNS and passive failover in Nginx.
    printf 'upstream api_pool_%s {\n    zone api_pool_%s 256k;\n    resolver %s valid=30s ipv6=off;\n    resolver_timeout 5s;\n' \
        "$BACKEND_ID" "$BACKEND_ID" "$API_RESOLVER" >> "$work/http"
    printf '    server %s resolve max_fails=1 fail_timeout=10s;\n' "$API_SERVER_AUTHORITY" >> "$work/http"
    while read -r backup_slot backup_variable backup_weight; do
        [ "$backup_slot" = "$slot" ] && continue
        backup_origin=$(awk -v key="$backup_variable" 'BEGIN { printf "%s.", ENVIRON[key] }')
        backup_authority=$(validate_origin "${backup_origin%.}")
        case "$backup_authority" in *:*) ;; *) backup_authority="$backup_authority:443" ;; esac
        printf '    server %s resolve max_fails=1 fail_timeout=10s backup;\n' "$backup_authority" >> "$work/http"
    done < "$work/slots"
    printf '}\n\n' >> "$work/http"

    for public_base in /backends /api/backends; do
        export BACKEND_ROUTE_PREFIX="$public_base/$BACKEND_ID"
        export BACKEND_COOKIE_PATH="$BACKEND_ROUTE_PREFIX/"
        render_admin_route
        render_health_route
    done
    if [ "$BACKEND_ID" = b0 ]; then b0_found=1; fi
    separator=,
done < "$work/slots"

API_UPSTREAM_HOST=$(validate_authority "${APP_API_UPSTREAM_HOST:-$first_authority}")
export API_UPSTREAM_HOST
API_UPSTREAM_TLS_NAME=${API_UPSTREAM_HOST%%:*}
export API_UPSTREAM_TLS_NAME
{
    printf 'split_clients "$request_id" $api_pool {\n'
    cat "$work/splits"
    printf '}\n\n'
    printf 'map $api_pool $api_upstream_url {\n    default https://api_pool_b%s;\n' "$((first_slot - 1))"
    while read -r slot variable weight; do printf '    api_pool_b%s https://api_pool_b%s;\n' "$((slot - 1))" "$((slot - 1))"; done < "$work/slots"
    printf '}\n\nmap $api_pool $api_upstream_host {\n    default %s;\n' "$API_UPSTREAM_HOST"
    while read -r slot variable weight; do printf '    api_pool_b%s %s;\n' "$((slot - 1))" "$API_UPSTREAM_HOST"; done < "$work/slots"
    printf '}\n\nmap $api_pool $api_upstream_tls_name {\n    default %s;\n' "$API_UPSTREAM_TLS_NAME"
    while read -r slot variable weight; do printf '    api_pool_b%s %s;\n' "$((slot - 1))" "$API_UPSTREAM_TLS_NAME"; done < "$work/slots"
    cat <<'MAP'
}

map $request_uri $api_admin_target {
    default $request_uri;
    "~^/api/(?:admin-api|%61dmin-api)(?<api_admin_suffix>/.*)$" /admin-api$api_admin_suffix;
}

map "$request_method:$request_uri" $api_admin_read_route {
    default 0;
    "~^(GET|HEAD):/api/admin-api/(session|devices|games)([?].*)?$" 1;
    "~^(GET|HEAD):/api/admin-api/scan-login/tickets/[A-Za-z0-9_-]+([?].*)?$" 1;
}

map "$api_admin_read_route:$http_x_http_method_override" $api_admin_read_only {
    default 0;
    "1:" 1;
}
MAP
} >> "$work/http"

# The stable same-origin Web API is automatic and uses Bearer authentication.
cat <<'ADMIN' >> "$work/routes"
    location = /api/admin-api { return 404; }
    location ^~ /api/admin-api/ {
        error_page 418 = @admin_api_no_retry;
        if ($api_admin_read_only = 0) { return 418; }
        proxy_next_upstream error timeout http_502 http_503 http_504;
        proxy_next_upstream_tries 2;
        proxy_next_upstream_timeout 25s;
        proxy_read_timeout 40s;
        proxy_pass $api_upstream_url$api_admin_target;
        proxy_set_header Host $api_upstream_host;
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie "";
        proxy_set_header X-CSRF-Token "";
        proxy_ssl_name $api_upstream_tls_name;
        proxy_hide_header Set-Cookie;
        proxy_redirect /admin-api/ /api/admin-api/;
    }

    location @admin_api_no_retry {
        proxy_next_upstream off;
        proxy_read_timeout 40s;
        proxy_pass $api_upstream_url$api_admin_target;
        proxy_set_header Host $api_upstream_host;
        proxy_set_header Connection "";
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header Cookie "";
        proxy_set_header X-CSRF-Token "";
        proxy_ssl_name $api_upstream_tls_name;
        proxy_hide_header Set-Cookie;
        proxy_redirect /admin-api/ /api/admin-api/;
    }

ADMIN

# Keep deterministic compatibility aliases. If b0 does not exist, its old fixed
# alias stays closed; the stable automatic route remains available.
if [ "$b0_found" = 0 ]; then
    printf '    location ^~ /api/backends/b0/admin-api/ { return 404; }\n' >> "$work/routes"
fi
cat <<'RESERVED' >> "$work/routes"
    location ^~ /api/backends/ { return 404; }

RESERVED

# Manifest deliberately contains no backend chooser: the browser has one stable
# API base while Nginx owns weighted selection and failover.
printf '{"apiBase":"/api/admin-api","automatic":true,"auth":"jwt"}' > "$work/manifest"

# Insert generated text without interpreting it as awk source or replacements.
# Buffer rendering before opening final paths so validation cannot leave partial
# configuration behind.
awk -v http="$work/http" -v routes="$work/routes" '
    /^[[:space:]]*# FRONTEND_HTTP_CONFIG[[:space:]]*$/ {
        while ((getline line < http) > 0) print line
        close(http)
        next
    }
    /^[[:space:]]*# FRONTEND_BACKEND_ROUTES[[:space:]]*$/ {
        while ((getline line < routes) > 0) print line
        close(routes)
        next
    }
    /^[[:space:]]*# FRONTEND_RUNTIME_CONFIG[[:space:]]*$/ {
        print "    location = /runtime-config.json {"
        print "        default_type application/json;"
        print "        add_header Cache-Control no-store always;"
        print "        add_header Last-Modified \"\" always;"
        print "        add_header X-Content-Type-Options nosniff always;"
        print "        add_header Referrer-Policy same-origin always;"
        print "        add_header X-Frame-Options DENY always;"
        print "        etag off;"
        print "        if_modified_since off;"
        print "        try_files /runtime-config.json =404;"
        print "    }"
        next
    }
    { print }
' "$template" > "$work/config"
if [ "$(grep -c '^[[:space:]]*# FRONTEND_HTTP_CONFIG[[:space:]]*$' "$template")" != 1 ] ||
   [ "$(grep -c '^[[:space:]]*# FRONTEND_BACKEND_ROUTES[[:space:]]*$' "$template")" != 1 ] ||
   [ "$(grep -c '^[[:space:]]*# FRONTEND_RUNTIME_CONFIG[[:space:]]*$' "$template")" != 1 ]; then
    echo "Frontend renderer requires exactly one HTTP, backend-route and runtime-config marker." >&2
    exit 1
fi
cat "$work/manifest" > "$runtime_config"
cat "$work/config" > "$output"
