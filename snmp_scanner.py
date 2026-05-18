"""
SNMP router/AP discovery for WiFi and ARP client tables (read-only).
Supports generic ARP (ipNetToMedia) plus vendor WiFi client OIDs.
"""

from __future__ import annotations

import re
import subprocess
import sys
from typing import Any

# ipNetToMediaPhysAddress / ipNetToMediaNetAddress
OID_ARP_MAC = "1.3.6.1.2.1.4.22.1.2"
OID_ARP_IP = "1.3.6.1.2.1.4.22.1.3"

VENDOR_OIDS: dict[str, dict[str, str]] = {
    "generic": {},
    "mikrotik": {
        "wifi_mac": "1.3.6.1.4.1.14988.1.1.4.1.1.4",
        "wifi_signal": "1.3.6.1.4.1.14988.1.1.4.1.1.6",
    },
    "ubiquiti": {
        "wifi_mac": "1.3.6.1.4.1.41112.1.6.1.1.4",
        "wifi_signal": "1.3.6.1.4.1.41112.1.6.1.1.6",
    },
    "tp-link": {
        "wifi_mac": "1.3.6.1.4.1.11863.5.4.2.1.1.2",
    },
    "cisco": {
        "wifi_mac": "1.3.6.1.4.1.9.9.383.1.3.2.1.3",
        "wifi_signal": "1.3.6.1.4.1.9.9.383.1.3.2.1.6",
    },
}


def normalize_mac(raw: str) -> str:
    raw = raw.strip().strip('"').lower()
    if " " in raw:
        parts = raw.split()
        if len(parts) == 6:
            return ":".join(f"{int(p, 16):02X}" for p in parts)
    raw = re.sub(r"[^0-9a-fA-F]", "", raw)
    if len(raw) == 12:
        return ":".join(raw[i : i + 2].upper() for i in range(0, 12, 2))
    return raw.upper()


def _snmpwalk_cli(host: str, community: str, oid: str, version: int = 2, port: int = 161) -> list[tuple[str, str]]:
    ver_flag = "2c" if version == 2 else "1"
    cmd = [
        "snmpwalk",
        f"-v{ver_flag}",
        "-c",
        community,
        f"{host}:{port}",
        oid,
    ]
    try:
        r = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=25,
            creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return []
    rows: list[tuple[str, str]] = []
    for line in (r.stdout or "").splitlines():
        if "=" not in line:
            continue
        left, _, right = line.partition("=")
        rows.append((left.strip(), right.strip()))
    return rows


def _snmpwalk_pysnmp(host: str, community: str, oid: str, version: int = 2, port: int = 161) -> list[tuple[str, str]]:
    try:
        from pysnmp.hlapi import (
            CommunityData,
            ContextData,
            ObjectIdentity,
            ObjectType,
            SnmpEngine,
            UdpTransportTarget,
            nextCmd,
            usmNoAuthProtocol,
        )
    except ImportError:
        return []

    rows: list[tuple[str, str]] = []
    mp_model = 1 if version == 2 else 0
    for (err, _, _, var_binds) in nextCmd(
        SnmpEngine(),
        CommunityData(community, mpModel=mp_model),
        UdpTransportTarget((host, port), timeout=3, retries=1),
        ContextData(),
        ObjectType(ObjectIdentity(oid)),
        lexicographicMode=False,
    ):
        if err:
            break
        for vb in var_binds:
            rows.append((str(vb[0]), str(vb[1])))
    return rows


def snmp_walk(host: str, community: str, oid: str, version: int = 2, port: int = 161) -> list[tuple[str, str]]:
    rows = _snmpwalk_pysnmp(host, community, oid, version, port)
    if not rows:
        rows = _snmpwalk_cli(host, community, oid, version, port)
    return rows


def parse_ip_value(val: str) -> str | None:
    val = val.strip().strip('"')
    parts = val.split(".")
    if len(parts) == 4 and all(p.isdigit() and 0 <= int(p) <= 255 for p in parts):
        return val
    hexraw = re.sub(r"[^0-9a-fA-F]", "", val)
    if len(hexraw) == 8:
        try:
            b = bytes.fromhex(hexraw)
            return ".".join(str(x) for x in b)
        except ValueError:
            pass
    return None


def collect_arp(host: str, community: str, version: int, port: int) -> dict[str, dict[str, Any]]:
    """IP -> device info from router ARP table."""
    devices: dict[str, dict[str, Any]] = {}
    mac_rows = snmp_walk(host, community, OID_ARP_MAC, version, port)
    ip_rows = snmp_walk(host, community, OID_ARP_IP, version, port)
    index_mac: dict[str, str] = {}
    for oid, val in mac_rows:
        idx = oid.rsplit(".", 1)[-1]
        mac = normalize_mac(val.split(":")[-1] if ":" in val else val)
        if len(mac) >= 11:
            index_mac[idx] = mac
    for oid, val in ip_rows:
        idx = oid.rsplit(".", 1)[-1]
        ip = parse_ip_value(val.split(":")[-1] if ":" in val else val)
        if not ip or ip.endswith(".0") or ip.startswith("224."):
            continue
        mac = index_mac.get(idx, "")
        if not mac:
            continue
        devices[ip] = {
            "mac": mac,
            "hostname": f"snmp-{host}",
            "signal_strength": "-70 dBm",
            "band": "unknown",
            "source": f"snmp-arp@{host}",
        }
    return devices


def collect_wifi_clients(
    host: str, community: str, version: int, port: int, vendor: str
) -> dict[str, dict[str, Any]]:
    """MAC -> wifi metadata from vendor OIDs."""
    info: dict[str, dict[str, Any]] = {}
    oids = VENDOR_OIDS.get(vendor, VENDOR_OIDS["generic"])
    mac_oid = oids.get("wifi_mac")
    if not mac_oid:
        return info
    mac_rows = snmp_walk(host, community, mac_oid, version, port)
    signal_rows = {}
    sig_oid = oids.get("wifi_signal")
    if sig_oid:
        for oid, val in snmp_walk(host, community, sig_oid, version, port):
            signal_rows[oid.rsplit(".", 1)[-1]] = val

    for oid, val in mac_rows:
        mac = normalize_mac(val)
        if len(mac) < 11:
            continue
        idx = oid.rsplit(".", 1)[-1]
        sig = signal_rows.get(idx, "")
        dbm = "-65 dBm"
        band = "5GHz"
        if sig.isdigit() or re.match(r"^-?\d+$", sig.strip()):
            n = int(re.sub(r"\D", "", sig) or "70")
            if n > 0 and n <= 100:
                dbm = f"{int((n / 2) - 100)} dBm"
            else:
                dbm = f"-{min(95, abs(n))} dBm"
        info[mac] = {
            "signal_strength": dbm,
            "band": band,
            "source": f"snmp-wifi@{host}",
        }
    return info


def collect_snmp_devices(routers: list[dict]) -> dict[str, dict[str, Any]]:
    """
    Merge all routers into IP -> device map.
    routers: [{host, community, version, port, vendor}]
    """
    merged: dict[str, dict[str, Any]] = {}
    for r in routers:
        if not r.get("enabled", True):
            continue
        host = r.get("host", "")
        community = r.get("community", "public")
        if not host:
            continue
        version = int(r.get("version", 2))
        port = int(r.get("port", 161))
        vendor = (r.get("vendor") or "generic").lower()
        try:
            arp = collect_arp(host, community, version, port)
            wifi_by_mac = collect_wifi_clients(host, community, version, port, vendor)
            for ip, dev in arp.items():
                mac = dev["mac"]
                if mac in wifi_by_mac:
                    dev.update(wifi_by_mac[mac])
                    dev["source"] = f"snmp@{host}"
                merged[ip] = dev
            for mac, meta in wifi_by_mac.items():
                if any(d.get("mac") == mac for d in merged.values()):
                    continue
                merged[f"wifi-{mac}"] = {
                    "mac": mac,
                    "hostname": f"snmp-wifi-{host}",
                    "signal_strength": meta.get("signal_strength", "-68 dBm"),
                    "band": meta.get("band", "unknown"),
                    "source": meta.get("source", f"snmp-wifi@{host}"),
                }
        except Exception as exc:
            print(f"[snmp] {host}: {exc}", file=sys.stderr)
    return merged
