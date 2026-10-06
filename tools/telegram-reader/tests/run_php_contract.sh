#!/bin/sh
# Disposable HTTP PHP service + synthetic reader contract against app_test only.
set -eu
cd "$(dirname "$0")/../../.."
container="vkposter-source-contract-$$"
port="${TEST_HTTP_PORT:-18085}"
secret="source-reader-contract-secret-not-real-123456789"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true' EXIT INT TERM
docker compose run -d --no-deps --name "$container" -p "127.0.0.1:$port:8085" \
    -e APP_ENV=testing -e APP_DEBUG=0 -e DB_DATABASE=app_test -e MEDIA_LOCAL_ROOT=storage/testing/source-image-contract \
    -e "SOURCES_READER_SECRET=$secret" app php -S 0.0.0.0:8085 -t public >/dev/null
docker compose exec -T app php tools/telegram-reader/tests/seed_contract.php
docker run --rm -v "$PWD/tools/telegram-reader:/work" -w /work \
    -e "TEST_INTERNAL_URL=http://host.docker.internal:$port" -e "TEST_READER_SECRET=$secret" \
    --entrypoint python vkposter-telegram-reader-spike-reader \
    -m unittest tests.test_integration.PHPContractTests -v
docker compose exec -T app php -r 'require "vendor/autoload.php"; $db=App\Tests\Support\TestEnv::connection(); foreach(["source_events", "source_items", "source_messages"] as $t) {echo $t, ": ", $db->table($t)->count(), PHP_EOL;} if($db->table("source_items")->count()!==2 || $db->table("source_messages")->count()!==4) {exit(1);}'

docker compose exec -T app php tools/telegram-reader/tests/prepare_image_contract.php
docker run --rm -v "$PWD/tools/telegram-reader:/work" -w /work \
    -e "TEST_INTERNAL_URL=http://host.docker.internal:$port" -e "TEST_READER_SECRET=$secret" \
    --entrypoint python vkposter-telegram-reader-spike-reader \
    -m unittest tests.test_image_delivery.PHPImageContractTests -v
docker compose exec -T app php -r 'require "vendor/autoload.php"; $db=App\Tests\Support\TestEnv::connection(); $runs=$db->table("source_image_processings")->where("status","=","completed")->count(); $variants=$db->table("source_image_variants")->count(); echo "image_runs: $runs, variants: $variants", PHP_EOL; if($runs!==4 || $variants!==8) {exit(1);}'
