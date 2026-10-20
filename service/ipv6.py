"""Local IPv6 discovery and opt-in, finite-lease firewall pinholes. No DNS writes."""
import ipaddress
import re
import sys
import time
import socket
import http.client
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urljoin, urlparse
from port_opener import ServiceError, UPnPRouter, router_open


def public_address(value):
    address = ipaddress.IPv6Address(value)
    return address.is_global and not (address.is_multicast or address.is_reserved or address.ipv4_mapped)


def discover_address(interface=None, proc='/proc/net'):
    if interface and not re.fullmatch(r'[a-zA-Z0-9_.:-]{1,32}', interface):
        raise ServiceError('ipv6_interface_invalid', 'Choose a valid local network interface')
    candidates = []
    for line in (Path(proc)/'if_inet6').read_text().splitlines():
        fields = line.split()
        if len(fields) != 6:
            continue
        address, _, _, scope, flags, device = fields
        # TEMPORARY, DADFAILED, DEPRECATED and TENTATIVE are unsuitable for a server.
        if scope != '00' or int(flags, 16) & (0x01 | 0x08 | 0x20 | 0x40):
            continue
        value = str(ipaddress.IPv6Address(int(address, 16)))
        if public_address(value) and (not interface or device == interface):
            candidates.append((device, value))
    if not interface and len({device for device, _ in candidates}) > 1:
        routes = (Path(proc)/'ipv6_route').read_text().splitlines()
        devices = {parts[-1] for line in routes if len(parts := line.split()) >= 10 and parts[0] == '0'*32 and parts[1] == '00'}
        candidates = [candidate for candidate in candidates if candidate[0] in devices]
    candidates = sorted(set(candidates))
    if len(candidates) != 1:
        raise ServiceError('ipv6_address_ambiguous' if candidates else 'ipv6_address_unavailable',
            'Select a local interface with one usable, non-temporary global IPv6 address')
    return dict(interface=candidates[0][0], address=candidates[0][1])


def listener_present(address, port, protocol, proc='/proc/net'):
    path = Path(proc)/('tcp6' if protocol == 'TCP' else 'udp6')
    for line in path.read_text().splitlines()[1:]:
        fields = line.split()
        if len(fields) < 4:
            continue
        raw, number = fields[1].split(':')
        if int(number, 16) != port or fields[3] != ('0A' if protocol == 'TCP' else '07'):
            continue
        packed = b''.join(int(raw[index:index+8], 16).to_bytes(4, sys.byteorder) for index in range(0, 32, 8))
        bound = ipaddress.IPv6Address(packed)
        if bound.is_unspecified or str(bound) == address:
            return True
    return False


class IPv6Router(UPnPRouter):
    def bind_target(self, target, proc='/proc/net'):
        if not public_address(target['address']):
            raise ServiceError('ipv6_address_unavailable', 'Invalid local public IPv6 address')
        gateways=set()
        for line in (Path(proc)/'ipv6_route').read_text().splitlines():
            parts=line.split()
            if len(parts)>=10 and parts[0]=='0'*32 and parts[1]=='00' and parts[-1]==target['interface']:
                address=ipaddress.IPv6Address(int(parts[4],16))
                if address.is_link_local and int(parts[8],16)&1 and not int(parts[8],16)&0x200:
                    gateways.add(str(address))
        if len(gateways)!=1:
            raise ServiceError('ipv6_gateway_unavailable', 'Choose an interface with one link-local IPv6 default gateway')
        self.target=dict(target)
        self.gateway=gateways.pop()+'%'+target['interface']

    def local_request(self, method, path, body=None, headers=None):
        connection=http.client.HTTPConnection(self.gateway, urlparse(self.description_url).port or 80,
            timeout=self.timeout,source_address=(self.target['address'],0))
        try:
            connection.request(method,path,body,headers or {})
            response=connection.getresponse()
            payload=response.read(262145)
            if len(payload)>262144:raise ServiceError('ipv6_response_too_large','IPv6 router response is too large',503)
            return response.status,payload
        finally:
            connection.close()

    def discover(self):
        with router_open(self.description_url, timeout=self.timeout) as response:
            root = ET.fromstring(response.read(262145))
        if hasattr(self,'target'):
            status,payload=self.local_request('GET',urlparse(self.description_url).path)
            if status!=200:raise ServiceError('ipv6_gateway_unavailable','IPv6 router description unavailable',503)
            peer=ET.fromstring(payload)
            identity=lambda tree:next((item.text for item in tree.iter() if item.tag.rsplit('}',1)[-1]=='UDN'),None)
            if not identity(root) or identity(root)!=identity(peer):
                raise ServiceError('ipv6_router_mismatch','IPv6 default gateway differs from the configured router',503)
        for item in root.iter():
            if item.tag.rsplit('}', 1)[-1] != 'service':
                continue
            fields = {child.tag.rsplit('}', 1)[-1]: child.text for child in item}
            kind = fields.get('serviceType', '')
            if kind != 'urn:schemas-upnp-org:service:WANIPv6FirewallControl:1':
                continue
            url = urljoin(self.description_url, fields['controlURL'])
            if urlparse(url).scheme != 'http' or urlparse(url).netloc != urlparse(self.description_url).netloc:
                raise ServiceError('unsafe_router_url', 'IPv6 firewall control changed router host')
            self.control_url, self.service = url, kind
            return
        raise ServiceError('ipv6_unsupported', 'Router does not advertise IPv6 firewall control', 503)

    def call(self, action, fields):
        if not hasattr(self,'target'):
            return super().call(action,fields)
        if not self.control_url:self.discover()
        envelope=ET.Element('{http://schemas.xmlsoap.org/soap/envelope/}Envelope')
        body=ET.SubElement(envelope,'{http://schemas.xmlsoap.org/soap/envelope/}Body')
        method=ET.SubElement(body,'{'+self.service+'}'+action)
        for name,value in fields.items():ET.SubElement(method,name).text=str(value)
        status,payload=self.local_request('POST',urlparse(self.control_url).path,ET.tostring(envelope),
            {'Content-Type':'text/xml; charset="utf-8"','SOAPAction':'"'+self.service+'#'+action+'"'})
        try:
            result={item.tag.rsplit('}',1)[-1]:item.text for item in ET.fromstring(payload).iter()}
        except ET.ParseError:
            raise ServiceError('ipv6_router_unavailable','Invalid IPv6 router response',503)
        if 'errorCode' in result:raise ServiceError('upnp_'+result['errorCode'],'IPv6 router rejected action',503)
        if status!=200:raise ServiceError('upnp_'+str(status),'IPv6 router request failed',503)
        return result

    def capability(self):
        result = self.call('GetFirewallStatus', {})
        if result.get('FirewallEnabled') not in ('0', '1') or result.get('InboundPinholeAllowed') not in ('0', '1'):
            raise ServiceError('ipv6_invalid_router_response', 'Invalid IPv6 firewall status', 503)
        if result['FirewallEnabled'] != '1' or result['InboundPinholeAllowed'] != '1':
            raise ServiceError('ipv6_firewall_unavailable', 'IPv6 pinholes are not allowed by this router', 503)

    def open_tuple(self, address, port, protocol, lease):
        response = self.call('AddPinhole', dict(RemoteHost='', RemotePort=0, InternalClient=address,
            InternalPort=port, Protocol=6 if protocol == 'TCP' else 17, LeaseTime=lease))
        try:
            identity = int(response['UniqueID'])
            if not 0 <= identity <= 65535:
                raise ValueError()
        except (ValueError, TypeError, KeyError):
            raise ServiceError('ipv6_invalid_router_response', 'Router returned an invalid pinhole ID', 503)
        return identity

    def verify(self, identity):
        try:
            response = self.call('GetPinholePackets', dict(UniqueID=identity))
        except ServiceError as error:
            if error.code == 'upnp_403':
                return False  # Creation accepted, but this IGD denies read-back. Never claim verified.
            raise
        try:
            if int(response['PinholePackets']) < 0:
                raise ValueError()
        except (ValueError, TypeError, KeyError):
            raise ServiceError('ipv6_invalid_router_response', 'Router returned invalid pinhole verification', 503)
        return True


class IPv6Manager:
    def __init__(self, service, discover=discover_address, listener=listener_present, router_factory=IPv6Router):
        self.service, self.discover, self.listener, self.router_factory = service, discover, listener, router_factory
        self.report = dict(enabled=False, state='disabled', address=None, interface=None, error_code=None, mappings=[])

    def reconcile(self):
        service = self.service
        policy = service.state.get('ipv6_policy', {})
        enabled = policy.get('enabled', False) and service.config.get('transport') == 'outbound'
        owned = service.state.setdefault('ipv6_owned', {})
        now = time.time()
        # Expired identifiers may already belong to another application. Never mutate by ID.
        for key in list(owned):
            if owned[key]['expires_at'] <= now:
                del owned[key]
        rows = {row['mapping_id']: row for row in (service.state.get('desired') or {}).get('mappings', [])
            if enabled and row['mapping_id'] in policy.get('mapping_ids', [])}
        self.report = dict(enabled=enabled, state='disabled', address=None, interface=None, error_code=None, mappings=[])
        active_keys = set()
        try:
            if enabled:
                target = self.discover(policy.get('interface'))
                self.report.update(target, state='available')
                if service.config.get('backend', 'fake') == 'fake':
                    self.report['state'] = 'simulation'
                else:
                    url = service.state.get('router_url') or service.config['router_description_url']
                    router = self.router_factory(url)
                    if hasattr(router,'bind_target'):router.bind_target(target)
                    router.capability()
                for identity, row in rows.items():
                    key = identity+'@'+target['address']
                    result = dict(mapping_id=identity, port=row['port'], protocol=row['protocol'], address=target['address'], state='pending', error_code=None)
                    self.report['mappings'].append(result)
                    if getattr(service, 'renewal_paused', False):
                        result.update(state='blocked', error_code='authentication_paused')
                        continue
                    if not self.listener(target['address'], row['port'], row['protocol']):
                        result.update(state='blocked', error_code='ipv6_listener_missing')
                        continue
                    if service.dry_run or service.config.get('backend', 'fake') == 'fake':
                        continue
                    try:
                        if key not in owned and len(owned) >= 512:
                            raise ServiceError('ipv6_mapping_limit', 'IPv6 lease history limit reached', 503)
                        # Persist intent/lease horizon before the network write, including lost acknowledgements.
                        record = dict(result, expires_at=now+125, phase='creating')
                        previous = owned.get(key)
                        owned[key] = record
                        try:
                            service.save()
                        except Exception:
                            if previous is None: owned.pop(key, None)
                            else: owned[key] = previous
                            raise
                        pinhole_id = router.open_tuple(target['address'], row['port'], row['protocol'], 120)
                        record.update(pinhole_id=pinhole_id, phase='applied', expires_at=time.time()+125)
                        service.save()
                        verified = router.verify(pinhole_id)
                        record['phase'] = 'applied' if verified is False else 'confirmed'
                        service.save()
                        result['state'] = 'pending' if verified is False else 'mapped'
                        if verified is False: result['error_code'] = 'ipv6_verification_denied'
                        active_keys.add(key)
                    except Exception as error:
                        result.update(state='error', error_code=getattr(error, 'code', 'ipv6_router_unavailable'))
        except Exception as error:
            self.report.update(state='error', error_code=getattr(error, 'code', 'ipv6_discovery_failed'))
        for key, record in owned.items():
            if key not in active_keys:
                self.report['mappings'].append(dict(mapping_id=record['mapping_id'], port=record['port'], protocol=record['protocol'],
                    address=record['address'], state='removing', error_code=None, expires_at=record['expires_at']))
        # Duplicate IDs can occur during address rotation; bound retained expiry records separately.
        self.report['mappings'] = self.report['mappings'][:512]
        service.save()
