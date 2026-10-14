#!/usr/bin/env bash
set -euo pipefail

nginx_profile=deployment/nginx-emailer-light.conf.example
apache_profile=deployment/apache-emailer-light-vhost.conf.example

for profile in "$nginx_profile" "$apache_profile"; do
    test -f "$profile"
    if rg -n '/srv/emailer-light/releases/current($|[^/])' "$profile" >/dev/null; then
        echo "Deployment profile does not constrain its document root to public/." >&2
        exit 1
    fi
done

rg -q 'root /srv/emailer-light/releases/current/public;' "$nginx_profile"
rg -q 'location = /index\.php' "$nginx_profile"
rg -q 'location ~ \\.php\$ \{ return 404; \}' "$nginx_profile"
rg -q 'fastcgi_param HTTPS on;' "$nginx_profile"
rg -q 'DocumentRoot /srv/emailer-light/releases/current/public' "$apache_profile"
rg -q 'Options -Indexes -ExecCGI -Includes' "$apache_profile"
rg -q 'FallbackResource /index.php' "$apache_profile"
rg -q 'SetHandler "proxy:unix:/run/php/emailer-light.sock|fcgi://localhost/"' "$apache_profile"

if rg -n 'AllowOverride All|Options Indexes|0\.0\.0\.0:|/srv/emailer-light/releases/current[^/]$' deployment >/dev/null; then
    echo "Deployment profile contains an unsafe broad directive." >&2
    exit 1
fi
