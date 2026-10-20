"""Manual, explicitly authorized IPv4 bridge probe; never scheduled or part of unit tests.

Check host listeners/allocations separately before choosing --port. The test creates
one owned UDP rule, verifies read-back and always requests cleanup. No listener opens.
"""
import argparse
import tempfile
import uuid

from port_opener import PortOpenerService, UPnPRouter


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--router-url', required=True)
    parser.add_argument('--host-ip', required=True)
    parser.add_argument('--port', required=True, type=int)
    args = parser.parse_args()
    router = UPnPRouter(args.router_url)
    if router.get(args.port, 'UDP') or router.get(args.port, 'TCP'):
        raise RuntimeError('Probe port already has a mapping; nothing changed.')
    print(f'BRIDGE_ROUTER_SERVICE {router.service}', flush=True)
    installation, server = str(uuid.uuid4()), str(uuid.uuid4())
    with tempfile.TemporaryDirectory(prefix='portopener-bridge-probe-') as temporary:
        config = dict(installation_id=installation, node_id=1, internal_address=args.host_ip,
                      min_port=args.port, max_port=args.port, state_file=temporary+'/state.json',
                      backend='upnp', ipv4_only=True, dry_run=False, lease_seconds=120)
        service = PortOpenerService(config, router)
        snapshot = dict(schema_version=1, installation_id=installation, generation=1, node_id=1,
                        mappings=[dict(mapping_id=f'{server}:1:udp', server_uuid=server,
                                       allocation_id=1, internal_address=args.host_ip, port=args.port, protocol='UDP')])
        try:
            result = service.accept(snapshot)
            if len(result['mappings']) != 1 or result['mappings'][0]['state'] != 'mapped':
                raise RuntimeError('Bridge mapping was not verified.')
            rule = router.get(args.port, 'UDP')
            if not rule or rule['address'] != args.host_ip or rule['port'] != args.port:
                raise RuntimeError('Router target read-back did not match.')
            print(f'BRIDGE_MAPPING_VERIFIED UDP {args.port} -> Wings LAN host', flush=True)
        finally:
            snapshot.update(generation=2, mappings=[])
            result = service.accept(snapshot)
            if any(row['state'] != 'removed' for row in result['mappings']) or router.get(args.port, 'UDP'):
                raise RuntimeError('Probe cleanup not confirmed; inspect the temporary UDP rule.')
            print(f'BRIDGE_CLEANUP_VERIFIED UDP {args.port}', flush=True)


if __name__ == '__main__':
    main()
