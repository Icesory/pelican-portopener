"""Pelican console bootstrap; all mutable data stays outside release files."""
import json
import os
import sys
import time
from pathlib import Path

from outbound import bootstrap_config, expiry, pair, panel_url
from port_opener import atomic_save
from diagnostics import EventLog, failure


def prepare(root):
    os.umask(0o077)
    data = root / 'data'
    data.mkdir(mode=0o700, exist_ok=True)
    credentials_path = data / 'credentials.json'
    config_path = data / 'service.json'
    bootstrap_path = data / 'bootstrap.json'
    origin = panel_url(os.environ.get('PORT_OPENER_PANEL_URL', ''))
    if credentials_path.exists():
        credentials = json.loads(credentials_path.read_text())
        if panel_url(credentials['panel_url']) != origin:
            raise ValueError('Panel URL differs from saved pairing; restore the original URL')
    else:
        if config_path.exists() or (data / 'state.json').exists():
            raise ValueError('Existing installation is missing credentials; administrator recovery required')
        if bootstrap_path.exists():
            bootstrap = json.loads(bootstrap_path.read_text())
            if panel_url(bootstrap['panel_url']) != origin or expiry(bootstrap['expires_at']) <= time.time():
                raise ValueError('Managed bootstrap origin differs or the code expired; resume setup from the panel')
            if type(bootstrap['node_id']) is not int or bootstrap['node_id'] < 1:
                raise ValueError('Managed bootstrap node is invalid')
            import uuid
            uuid.UUID(bootstrap['installation_id'])
            credentials = pair(origin, bootstrap['pairing_code'], str(credentials_path),
                               expected_scope=(bootstrap['node_id'], bootstrap['installation_id']))
            print('Automatic node pairing saved.', flush=True)
            EventLog(data/'state.json').log('pairing', outcome='success')
        else:
            print('Generate a one-use pairing code in Admin > PortOpenerService > Configure > Service connection and maintenance.', flush=True)
            print('Enter pairing code (console input may be visible to other administrators):', flush=True)
            code = sys.stdin.readline().strip()
            if not code:
                raise ValueError('A pairing code is required')
            credentials = pair(origin, code, str(credentials_path))
            print('Pairing saved. Clear the consumed code from console history if appropriate.', flush=True)
            EventLog(data/'state.json').log('pairing', outcome='success')
    # Delete a consumed/obsolete bootstrap only after credentials were saved durably.
    bootstrap_path.unlink(missing_ok=True)
    (data / 'bootstrap.json.pending').unlink(missing_ok=True)
    # Recover if pairing succeeded but the first desired-state request failed.
    if not config_path.exists():
        bootstrap_config(credentials, str(credentials_path), str(config_path))
    config = json.loads(config_path.read_text())
    if config.get('transport') != 'outbound' or config.get('backend') != 'upnp':
        raise ValueError('Egg requires outbound UPnP configuration')
    state_path = data / 'state.json'
    if state_path.exists():
        state = json.loads(state_path.read_text())
        if state.get('ipv6_owned') or state.get('ipv6_policy', {}).get('enabled'):
            raise ValueError('IPv6 state requires host-service cleanup before using this IPv4 Egg')
    config.update(ipv4_only=True, credentials_file=str(credentials_path), state_file=str(state_path))
    atomic_save(config_path, config)
    return config_path


if __name__ == '__main__':
    try:
        path = prepare(Path(__file__).resolve().parent)
    except Exception as error:
        # Never print exception bodies which may include credentials or remote responses.
        EventLog(Path(__file__).resolve().parent/'data/state.json').log('setup_failed', level='error', phase='bootstrap', **failure(error))
        print('IPv4 setup failed. Check HTTPS panel URL, pairing, saved files and node settings; restart to retry.', flush=True)
        raise SystemExit(1)
    # Environment overrides must not switch this Egg to the legacy listener.
    for name in ('PORT_OPENER_TRANSPORT', 'PORT_OPENER_CREDENTIALS_FILE', 'PORT_OPENER_CA_FILE'):
        os.environ.pop(name, None)
    sys.argv = ['port_opener.py', 'run', '--config', str(path)]
    print('PortOpenerService IPv4 starting (outbound HTTPS; no listening port).', flush=True)
    from port_opener import main
    main()
