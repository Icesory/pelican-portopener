#!/bin/sh
# Verify the actual deployable ZIP with a fresh database and simulated Wings HTTP.
set -eu
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
python3 "$project_dir/packaging/build_release.py"
version=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$project_dir/plugin.json")
docker run --rm -i --network none --entrypoint sh \
    -v "$project_dir/dist/port-opener-$version.zip:/tmp/port-opener-release.zip:ro" \
    -v "$project_dir/tests:/source-tests:ro" \
    --tmpfs /var/www/html/plugins:rw,nosuid,mode=1777 \
    -w /var/www/html \
    -e APP_NAME='PortOpener Verification' -e APP_ENV=local \
    -e APP_URL=https://panel.example.com -e APP_INSTALLED=true \
    -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/tmp/portopener-verification.sqlite \
    -e QUEUE_CONNECTION=database -e CACHE_STORE=array -e SESSION_DRIVER=array \
    ghcr.io/pelican/panel@sha256:9e2e83f2fd4094bb15661b308b996af284dae525fdba4dd58f98f65c586604ed -s <<'VERIFY'
set -eu
php <<'PHP' > /tmp/port-opener-manifest.json
<?php
$archive = new ZipArchive();
if ($archive->open('/tmp/port-opener-release.zip') !== true || !$archive->extractTo('/var/www/html/plugins')) {
    throw new RuntimeException('Cannot extract the built plugin release.');
}
$archive->close();
$path='/var/www/html/plugins/port-opener/plugin.json';
$manifest=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
$manifest['meta']=['status'=>'enabled','status_message'=>null];
echo json_encode($manifest,JSON_THROW_ON_ERROR);
PHP
cp /tmp/port-opener-manifest.json /var/www/html/plugins/port-opener/plugin.json
touch /tmp/portopener-verification.sqlite
php /source-tests/ipv4_pelican.php
VERIFY
