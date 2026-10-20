"""Run on server in dev root; generates only development credentials."""
import base64, pathlib, secrets, uuid, json
root = pathlib.Path.cwd()
config = root / '.env.dev'
if not config.exists():
    config.write_text('APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode() + '\n')
    config.chmod(0o600)
(root / 'runtime').mkdir(exist_ok=True, mode=0o700)
lines = config.read_text().splitlines()
values = dict(line.split('=', 1) for line in lines if '=' in line)
defaults = {
    'PORT_OPENER_INSTALLATION_ID': str(uuid.uuid4()),
    'PORT_OPENER_TOKEN': secrets.token_hex(32),
    'PORT_OPENER_NODE_ID': '1',
    'PORT_OPENER_ENABLED': 'true',
    'PORT_OPENER_ENDPOINT': 'http://port-opener-service:8787',
}
for key, value in defaults.items():
    if key not in values:
        lines.append(key + '=' + value)
        values[key] = value
config.write_text('\n'.join(lines) + '\n')
config.chmod(0o600)
service_config = root / 'runtime' / 'service.json'
service_config.write_text(json.dumps({
    'installation_id': values['PORT_OPENER_INSTALLATION_ID'],
    'node_id': 1, 'internal_address': '192.168.178.50',
    'min_port': 54000, 'max_port': 54999,
    'backend': 'fake', 'dry_run': False,
    'bind': '0.0.0.0', 'listen_port': 8787,
    'state_file': '/state/state.json',
    'lease_seconds': 3600, 'reconcile_seconds': 60,
}, indent=2) + '\n')
# Contains no secrets; container's unprivileged uid must be able to read it.
service_config.chmod(0o644)
print('Development environment file ready; secrets not printed.')
