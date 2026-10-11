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
             fi ;;
    esac
    printf '%s' "$authority"
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
        if (key !~ /^APP_API_[0-9]+_URL$/) continue
        split(key, parts, "_")
        slot = parts[3]
        if (slot !~ /^[1-9][0-9]*$/ || length(slot) > 16 ||
            (length(slot) == 16 && ("x" slot) > "x9007199254740991")) {
            print "Frontend backend URL has an invalid slot number." > "/dev/stderr"
            exit 1
        }
        printf "%s %s\n", slot, key
    }
}' > "$work/unsorted"
LC_ALL=C sort -n "$work/unsorted" > "$work/slots"
if [ ! -s "$work/slots" ]; then
    echo "At least one APP_API_N_URL HTTPS origin is required." >&2
    exit 1
fi

# Fixed Web routes use their selected origin and never join the Bearer pool.
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

: > "$work/routes"
b0_found=0
printf '{"backends":[' > "$work/manifest"
separator=''
while read -r slot variable; do
    # The sentinel preserves trailing newlines so validation also rejects them.
    origin=$(awk -v key="$variable" 'BEGIN { printf "%s.", ENVIRON[key] }')
    origin=${origin%.}
    authority=$(validate_origin "$origin")
    export BACKEND_ORIGIN="https://$authority"
    export BACKEND_HOST="$authority"
    export BACKEND_TLS_NAME="${authority%%:*}"
    export BACKEND_ID="b$((slot - 1))"
    public_host=$authority
    case "$authority" in
        *:*) public_port=$(awk -v port="${authority##*:}" 'BEGIN { printf "%d", port }')
             public_host=${authority%%:*}
             if [ "$public_port" -ne 443 ]; then public_host="$public_host:$public_port"; fi ;;
    esac
    # Validated authorities contain no JSON or Nginx string delimiters.
    printf '%s{"id":"%s","name":"正式后端 %s","host":"%s"}' \
        "$separator" "$BACKEND_ID" "$slot" "$public_host" >> "$work/manifest"
    separator=,

    for public_base in /backends /api/backends; do
        export BACKEND_ROUTE_PREFIX="$public_base/$BACKEND_ID"
        export BACKEND_COOKIE_PATH="$BACKEND_ROUTE_PREFIX/"
        render_admin_route
        render_health_route
    done
    if [ "$BACKEND_ID" = b0 ]; then
        b0_found=1
        export BACKEND_ROUTE_PREFIX=/api
        export BACKEND_COOKIE_PATH=/api/admin-api/
        render_admin_route
    fi
done < "$work/slots"
printf ']}' >> "$work/manifest"
# Reserve Session aliases even when slot 1 is absent. Nginx matches locations
# against normalized paths, so encoded admin-api aliases cannot enter the pool.
if [ "$b0_found" = 0 ]; then
    printf '    location ^~ /api/admin-api/ { return 404; }\n' >> "$work/routes"
fi
cat <<'RESERVED' >> "$work/routes"
    location = /api/admin-api { return 404; }
    location ^~ /api/backends/ { return 404; }

RESERVED

# Insert generated text without interpreting it as awk source or replacements.
# Buffer rendering before opening the final output, so validation cannot leave
# partial configuration behind.
awk -v routes="$work/routes" '
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
# Keep the manifest outside Nginx directive arguments: configuration tokens have
# a fixed size limit, while the number of configured backend slots does not.
cat "$work/manifest" > "$runtime_config"
cat "$work/config" > "$output"
