"""Create fresh commissioning settings only; refuses to overwrite an installation."""
import argparse, json, pathlib, secrets, uuid
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--node-id',type=int,required=True)
parser.add_argument('--host-ip',required=True)
parser.add_argument('--router-url',required=True)
parser.add_argument('--min-port',type=int,default=1024)
parser.add_argument('--max-port',type=int,default=65535)
args=parser.parse_args()
from port_opener import validate_router_url
import ipaddress
ipaddress.IPv4Address(args.host_ip)
validate_router_url(args.router_url)
if args.node_id<1 or not 1<=args.min_port<=args.max_port<=65535:
    parser.error('Invalid node ID or port range')
root=pathlib.Path.cwd()
paths=[root/name for name in ('service.json','token.env','panel-settings.env')]
if any(path.exists() for path in paths):
    parser.error('Existing configuration found. Preserve its installation ID, token and state.')
token=secrets.token_hex(32); installation=str(uuid.uuid4())
config=dict(installation_id=installation,node_id=args.node_id,internal_address=args.host_ip,
    min_port=args.min_port,max_port=args.max_port,backend='upnp',dry_run=True,
    bind='127.0.0.1',listen_port=8787,router_description_url=args.router_url,
    state_file='/state/state.json',lease_seconds=3600,reconcile_seconds=60)
paths[0].write_text(json.dumps(config,indent=2)+'\n'); paths[0].chmod(0o644)
paths[1].write_text('PORT_OPENER_TOKEN='+token+'\n'); paths[1].chmod(0o600)
paths[2].write_text('PORT_OPENER_ENDPOINT=http://127.0.0.1:8787\nPORT_OPENER_TOKEN='+token+
    '\nPORT_OPENER_INSTALLATION_ID='+installation+'\nPORT_OPENER_NODE_ID='+str(args.node_id)+
    '\nPORT_OPENER_ENABLED=true\n'); paths[2].chmod(0o600)
print('Created service.json, token.env and private panel-settings.env. Dry-run is enabled; credentials are not printed.')
