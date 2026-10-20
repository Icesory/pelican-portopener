#!/bin/sh
# Real Pelican services with an empty, disposable database and simulated Wings HTTP.
set -eu
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
docker run --rm -i --network none --entrypoint sh \
    -v "$project_dir/plugin:/source:ro" \
    --tmpfs /var/www/html/plugins:rw,nosuid,mode=1777 \
    -w /var/www/html \
    -e APP_NAME='PortOpener Verification' -e APP_ENV=local \
    -e APP_URL=https://panel.example.com -e APP_INSTALLED=true \
    -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/portopener-verification.sqlite \
    -e QUEUE_CONNECTION=database -e CACHE_STORE=array -e SESSION_DRIVER=array \
    ghcr.io/pelican/panel@sha256:9e2e83f2fd4094bb15661b308b996af284dae525fdba4dd58f98f65c586604ed -s <<'VERIFY'
set -eu
cp -R /source/port-opener /var/www/html/plugins/port-opener
mkdir /var/www/html/plugins/tests
cp /source/tests/ipv4_pelican.php /var/www/html/plugins/tests/
cp /source/tests/ports_grouping.php /var/www/html/plugins/tests/
cp /source/tests/diagnostics.php /var/www/html/plugins/tests/
php <<'PHP'
<?php
$path='/var/www/html/plugins/port-opener/plugin.json';
$manifest=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
$manifest['meta']=['status'=>'enabled','status_message'=>null];
if (file_put_contents($path,json_encode($manifest,JSON_THROW_ON_ERROR))===false) exit(1);
PHP
touch /tmp/portopener-verification.sqlite
php plugins/tests/ipv4_pelican.php
VERIFY
