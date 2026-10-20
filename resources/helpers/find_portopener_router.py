#!/usr/bin/env python3
"""Read-only SSDP discovery on the Wings router's LAN. Python 3; no dependencies."""
import argparse
import ipaddress
import select
import socket
import time
from urllib.parse import urlsplit

TARGETS = tuple('urn:schemas-upnp-org:'+kind for kind in (
    'device:InternetGatewayDevice:2', 'service:WANIPConnection:2',
    'device:InternetGatewayDevice:1', 'service:WANIPConnection:1', 'service:WANPPPConnection:1'))
NETWORKS = tuple(ipaddress.IPv4Network(net) for net in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'))


def private_ipv4(address):
    try:
        return any(ipaddress.IPv4Address(address) in network for network in NETWORKS)
    except ipaddress.AddressValueError:
        return False


def router_url(packet, sender):
    """Accept router replies and URLs supported by the plugin, never other UPnP devices."""
    lines = packet.decode('ascii', errors='replace').split('\r\n')
    if lines[0].split()[:2] != ['HTTP/1.1', '200']:
        return None
    headers = {}
    for line in lines[1:]:
        if ':' not in line:
            continue
        name, value = line.split(':', 1)
        name = name.strip().lower()
        if name in headers and name in ('location', 'st'):
            return None
        headers[name] = value.strip()
    if headers.get('st', '').lower() not in {target.lower() for target in TARGETS}:
        return None
    location = headers.get('location', '')
    if any(ord(char) <= 32 or ord(char) == 127 or char in '\\?#' for char in location):
        return None
    try:
        url = urlsplit(location)
        if (url.scheme != 'http' or not private_ipv4(url.hostname) or url.hostname != sender
                or url.username is not None or url.password is not None or url.query or url.fragment
                or not url.path.startswith('/') or (url.port is not None and not 1 <= url.port <= 65535)):
            return None
    except (ValueError, TypeError):
        return None
    return location


def discover(timeout=4, interface=None):
    if interface and not private_ipv4(interface):
        raise ValueError('Choose this computer\'s private LAN IPv4 for --interface.')
    addresses = [interface] if interface else []
    if not addresses:
        try:
            addresses = sorted({item[4][0] for item in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET, socket.SOCK_DGRAM)
                                if private_ipv4(item[4][0])})
        except OSError:
            pass
    sockets, found = [], {}
    try:
        for address in addresses or ['0.0.0.0']:
            connection = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            try:
                connection.bind((address, 0))
                connection.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
                if address != '0.0.0.0':
                    connection.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(address))
                for target in TARGETS:
                    packet = ('M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\n'
                              'MAN: "ssdp:discover"\r\nMX: 2\r\nST: '+target+'\r\n\r\n')
                    connection.sendto(packet.encode('ascii'), ('239.255.255.250', 1900))
                sockets.append(connection)
            except OSError:
                connection.close()
        deadline = time.monotonic()+timeout
        packets = 0
        while sockets and time.monotonic() < deadline and packets < 1024:
            readable, _, _ = select.select(sockets, [], [], max(0, deadline-time.monotonic()))
            for connection in readable:
                packet, sender = connection.recvfrom(8192)
                packets += 1
                location = router_url(packet, sender[0])
                if location:
                    version = max((int(line.rsplit(':', 1)[-1]) for line in packet.decode('ascii', errors='replace').split('\r\n')
                                   if ':' in line and line.split(':', 1)[0].strip().lower() == 'st'), default=1)
                    found[location] = max(version, found.get(location, 1))
    finally:
        for connection in sockets:
            connection.close()
    return sorted(found, key=lambda location: (-found[location], location))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--interface', help='Local private IPv4, useful when a VPN or multiple adapters are present')
    parser.add_argument('--timeout', type=int, choices=range(2, 16), default=4, metavar='2..15')
    args = parser.parse_args()
    print('Searching this LAN for UPnP routers (read-only)...', flush=True)
    try:
        urls = discover(args.timeout, args.interface)
    except (ValueError, OSError) as error:
        parser.exit(1, str(error)+'\n')
    if not urls:
        print('No supported router URL found. Use the Wings node\'s LAN; check UPnP, firewall, guest Wi-Fi and VPN settings.')
        return 1
    print('\nRouter URL candidates (IGD2 preferred; IGD1 fallback):')
    print('\n'.join(urls))
    print('\nPaste the matching URL into PortOpener > node configuration > Router UPnP URL, save and run Check connection.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
