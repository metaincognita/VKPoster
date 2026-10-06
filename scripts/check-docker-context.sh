#!/bin/sh
# Regression: synthetic secrets only. Never sends the application's real credentials to a builder.
set -eu
base=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
mkdir -p "$fixture/context/tools/telegram-reader/deep" "$fixture/context/other/.aws" "$fixture/context/runtime"
cp "$base/.dockerignore" "$fixture/context/.dockerignore"
for path in .env tools/telegram-reader/.env tools/telegram-reader/deep/.env.local tools/telegram-reader/deep/account.session tools/telegram-reader/deep/account.session-journal tools/telegram-reader/deep/runtime.sqlite tools/telegram-reader/deep/runtime.sqlite-wal other/.aws/credentials other/private.key runtime/state .DS_Store; do
    printf 'synthetic-secret-sentinel\n' > "$fixture/context/$path"
done
printf 'safe-example\n' > "$fixture/context/tools/telegram-reader/.env.example"
printf 'FROM scratch\nCOPY . /\n' > "$fixture/context/Dockerfile"
BUILDX_CONFIG="$fixture/buildx" docker build --quiet --output "type=local,dest=$fixture/image" "$fixture/context" >/dev/null
if find "$fixture/image" -type f -exec grep -l 'synthetic-secret-sentinel' {} + | grep -q .; then
    echo 'FAIL: secret sentinel reached Docker image' >&2
    exit 1
fi
test -f "$fixture/image/tools/telegram-reader/.env.example"
if grep -q '^COPY --chown=app:www-data \. /var/www/html' "$base/docker/php/Dockerfile"; then
    echo 'FAIL: production image must use explicit application inputs' >&2
    exit 1
fi
echo 'PASS: nested secrets absent from Docker context/image; production COPY uses explicit inputs'
