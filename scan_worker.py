#!/usr/bin/env python3
"""
Exam Phone Detection — network scan worker (ZERO EXEMPTION).
WiFi/LAN + SNMP + Bluetooth phone detection every 20 seconds.
"""

from __future__ import annotations

import json
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

SCAN_INTERVAL = 20
DEFAULT_SUBNETS = ["192.168.1.0/24", "192.168.0.0/24", "10.0.0.0/24"]
CONFIG_PATH = Path(__file__).resolve().parent / "config.json"

try:
    from snmp_scanner import collect_snmp_devices
except ImportError:
    collect_snmp_devices = None  # type: ignore

try:
    from bluetooth_scanner import collect_bluetooth_devices, bluetooth_ready
except ImportError:
    collect_bluetooth_devices = None  # type: ignore
    bluetooth_ready = None  # type: ignore

try:
    from wifi_phone_detector import classify_wifi_phone
except ImportError:
    classify_wifi_phone = None  # type: ignore


def load_config() -> dict:
    if CONFIG_PATH.exists():
        with open(CONFIG_PATH, encoding="utf-8") as f:
            return json.load(f)
    return {
        "api_url": "http://localhost/EXAM%20PHONE%20DETECTION%20SYSTEM/api/scan.php",
        "api_key": "change-me-in-production",
        "subnets": DEFAULT_SUBNETS,
        "bluetooth": {"enabled": True},
    }


def run_cmd(cmd: list[str], timeout: int = 30) -> str:
    try:
        r = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=timeout,
            creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0,
        )
        return (r.stdout or "") + (r.stderr or "")
    except (subprocess.TimeoutExpired, FileNotFoundError):
        return ""


def discover_local_subnets() -> list[str]:
    out = run_cmd(["ipconfig"] if sys.platform == "win32" else ["ip", "addr"])
    subnets: list[str] = []
    for line in out.splitlines():
        m = re.search(r"IPv4[^:]*:\s*(\d+\.\d+\.\d+)\.\d+", line, re.I)
        if m:
            subnets.append(f"{m.group(1)}.0/24")
    return list(dict.fromkeys(subnets)) or DEFAULT_SUBNETS[:2]


def parse_arp() -> dict[str, dict]:
    out = run_cmd(["arp", "-a"])
    devices: dict[str, dict] = {}
    for line in out.splitlines():
        m = re.search(
            r"(\d+\.\d+\.\d+\.\d+)\s+([0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2}[-:][0-9a-fA-F]{2})",
            line,
        )
        if not m:
            continue
        ip, mac = m.group(1), m.group(2).replace("-", ":").upper()
        if ip.startswith("224.") or ip.endswith(".255"):
            continue
        devices[ip] = {"mac": mac, "hostname": "unknown", "source": "arp"}
    return devices


def wlan_clients() -> dict[str, dict]:
    info: dict[str, dict] = {}
    if sys.platform != "win32":
        return info
    out = run_cmd(["netsh", "wlan", "show", "interfaces"])
    band = "5GHz"
    if "2.4" in out:
        band = "2.4GHz"
    rssi = "-70 dBm"
    m = re.search(r"Signal\s*:\s*(\d+)%", out, re.I)
    if m:
        dbm = int((int(m.group(1)) / 2) - 100)
        rssi = f"{dbm} dBm"
    bssid_m = re.search(r"BSSID\s*:\s*([0-9a-fA-F:]{17})", out, re.I)
    if bssid_m:
        info[bssid_m.group(1).upper()] = {"signal_strength": rssi, "band": band}
    return info


def ping_sweep_subnet(cidr: str, limit: int = 32) -> None:
    base = cidr.split("/")[0]
    parts = base.split(".")
    if len(parts) != 4:
        return
    prefix = ".".join(parts[:3])
    for host in range(1, min(limit + 1, 255)):
        ip = f"{prefix}.{host}"
        if sys.platform == "win32":
            run_cmd(["ping", "-n", "1", "-w", "150", ip], timeout=2)
        else:
            run_cmd(["ping", "-c", "1", "-W", "1", ip], timeout=2)


def resolve_hostname(ip: str) -> str:
    try:
        name, _, _ = socket.gethostbyaddr(ip)
        return name
    except (socket.herror, socket.gaierror, OSError):
        return "unknown"


def merge_sources(arp: dict[str, dict], snmp: dict[str, dict]) -> dict[str, dict]:
    merged = dict(arp)
    for ip, data in snmp.items():
        mac = data.get("mac", "")
        if not mac:
            continue
        if ip in merged:
            merged[ip].update({k: v for k, v in data.items() if v})
        else:
            merged[ip] = dict(data)
    return merged


def merge_bluetooth(network: dict[str, dict], bluetooth: dict[str, dict]) -> dict[str, dict]:
    mac_index = {(d.get("mac") or "").upper(): k for k, d in network.items() if d.get("mac")}

    for _key, bt in bluetooth.items():
        mac = (bt.get("mac") or "").upper()
        if not mac:
            continue
        src = bt.get("discovery_source") or bt.get("source") or "bluetooth-ble"
        entry = {
            "mac": mac,
            "hostname": bt.get("hostname", "unknown"),
            "signal_strength": bt.get("signal_strength", "-70 dBm"),
            "band": "Bluetooth",
            "source": src,
            "discovery_source": src,
            "bt_likely_phone": bool(bt.get("bt_likely_phone")),
        }
        if mac in mac_index:
            existing = network[mac_index[mac]]
            existing.update({k: v for k, v in entry.items() if v is not None})
            if entry.get("bt_likely_phone"):
                existing["bt_likely_phone"] = True
        else:
            network[f"bt-{mac}"] = entry
    return network


def scan_bluetooth(bt_cfg: dict) -> dict[str, dict]:
    if not bt_cfg.get("enabled", True):
        return {}
    if collect_bluetooth_devices is None:
        print("  Bluetooth: install bleak — pip install bleak", file=sys.stderr)
        return {}
    if bluetooth_ready:
        ok, msg = bluetooth_ready()
        if not ok:
            print(f"  Bluetooth: {msg}", file=sys.stderr)
            return {}
    try:
        return collect_bluetooth_devices(bt_cfg) or {}
    except Exception as exc:
        print(f"  Bluetooth error: {exc}", file=sys.stderr)
        return {}


def build_scan_payload(
    scan_num: int,
    subnets: list[str],
    snmp_routers: list[dict] | None = None,
    bluetooth_cfg: dict | None = None,
    wifi_phone_cfg: dict | None = None,
) -> dict:
    now = datetime.now()
    ts = now.strftime("%Y-%m-%d %H:%M:%S")
    tshort = now.strftime("%H:%M:%S")
    bt_cfg = bluetooth_cfg or {}
    wifi_cfg = wifi_phone_cfg or {"enabled": True, "lookup_vendor": True, "privacy_mac_on_wifi": True}
    bt_data = scan_bluetooth(bt_cfg) if bt_cfg.get("enabled", True) else {}

    ping_limit = int(wifi_cfg.get("ping_limit", 64))
    for cidr in subnets[:2]:
        ping_sweep_subnet(cidr, limit=ping_limit)

    arp = parse_arp()
    # Second ARP read after ping — more Wi-Fi clients appear in the table
    if arp:
        time.sleep(0.5)
    arp2 = parse_arp()
    for ip, data in arp2.items():
        if ip not in arp:
            arp[ip] = data
        else:
            arp[ip].update({k: v for k, v in data.items() if v})
    snmp_data: dict[str, dict] = {}
    if snmp_routers and collect_snmp_devices:
        snmp_data = collect_snmp_devices(snmp_routers) or {}
        if snmp_data:
            print(f"  SNMP: {len(snmp_data)} entries")

    merged = merge_sources(arp, snmp_data)
    if bt_data:
        phones = sum(1 for d in bt_data.values() if d.get("bt_likely_phone"))
        print(f"  Bluetooth merged: {len(bt_data)} device(s), {phones} phone(s)")
        merged = merge_bluetooth(merged, bt_data)

    wlan = wlan_clients()
    devices_list = []
    wifi_phone_count = 0

    for idx, (ip, data) in enumerate(sorted(merged.items()), start=1):
        mac = data["mac"]
        meta = wlan.get(mac, {})
        is_bt = ip.startswith("bt-") or data.get("band") == "Bluetooth"
        hostname = data.get("hostname") or "unknown"
        real_ip = ip if re.match(r"^\d+\.\d+\.\d+\.\d+$", ip) else ""
        if not is_bt and real_ip and hostname == "unknown":
            hostname = resolve_hostname(ip)

        band = data.get("band") or meta.get("band", "unknown")
        wifi_phone = False
        mac_reason = ""
        vendor_hint = data.get("vendor")

        if wifi_cfg.get("enabled", True) and classify_wifi_phone and not is_bt:
            wf = classify_wifi_phone(
                mac,
                hostname=hostname,
                vendor=vendor_hint,
                band=band,
                ip=real_ip or None,
                is_bluetooth=False,
                lookup_vendor=bool(wifi_cfg.get("lookup_vendor", True)),
                privacy_mac_on_wifi=bool(wifi_cfg.get("privacy_mac_on_wifi", True)),
            )
            wifi_phone = bool(wf.get("wifi_likely_phone"))
            mac_reason = wf.get("mac_phone_reason") or ""
            if wf.get("vendor") and not vendor_hint:
                vendor_hint = wf["vendor"]
        elif wifi_cfg.get("enabled", True) and classify_wifi_phone and is_bt and real_ip:
            wf = classify_wifi_phone(
                mac,
                hostname=hostname,
                vendor=vendor_hint,
                band=band,
                ip=real_ip,
                is_bluetooth=True,
                lookup_vendor=bool(wifi_cfg.get("lookup_vendor", True)),
                privacy_mac_on_wifi=bool(wifi_cfg.get("privacy_mac_on_wifi", True)),
            )
            wifi_phone = bool(wf.get("wifi_likely_phone"))
            mac_reason = wf.get("mac_phone_reason") or ""

        if wifi_phone:
            wifi_phone_count += 1

        is_phone = bool(data.get("bt_likely_phone")) or wifi_phone
        devices_list.append({
            "id": f"DEV-{idx:03d}",
            "ip": real_ip,
            "mac": mac,
            "hostname": hostname,
            "signal_strength": data.get("signal_strength") or meta.get("signal_strength", "-75 dBm"),
            "band": band,
            "first_seen": tshort,
            "last_seen": tshort,
            "duration": "00:00:00",
            "status": "CRITICAL" if is_phone else "FLAGGED",
            "discovery_source": data.get("discovery_source") or data.get("source", "arp"),
            "bt_likely_phone": bool(data.get("bt_likely_phone")),
            "wifi_likely_phone": wifi_phone,
            "mac_phone_reason": mac_reason,
            "vendor_hint": vendor_hint or "",
        })

    if wifi_phone_count:
        print(f"  Wi-Fi MAC phones: {wifi_phone_count}", file=sys.stderr)

    scan_subnets = list(subnets)
    if bt_cfg.get("enabled"):
        scan_subnets.append("bluetooth-ble")

    return {
        "scan_id": f"SCN-{scan_num:03d}",
        "scan_time": ts,
        "subnets_scanned": scan_subnets,
        "total_devices": len(devices_list),
        "devices": devices_list,
    }


def post_scan(api_url: str, api_key: str, payload: dict) -> dict | None:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        api_url,
        data=data,
        headers={"Content-Type": "application/json", "X-Api-Key": api_key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        print(f"[scan] HTTP {e.code}: {body[:800]}", file=sys.stderr)
    except urllib.error.URLError as e:
        print(f"[scan] Network error: {e.reason}", file=sys.stderr)
    return None


def main() -> None:
    cfg = load_config()
    api_url = cfg.get("api_url", "")
    api_key = cfg.get("api_key", "change-me-in-production")
    subnets = cfg.get("subnets") or discover_local_subnets()
    if cfg.get("auto_subnets", True):
        subnets = list(dict.fromkeys(subnets + discover_local_subnets()))
    bt_cfg = cfg.get("bluetooth") or {"enabled": True}

    print("=" * 50)
    print("ZERO EXEMPTION scan worker")
    wifi_cfg = cfg.get("wifi_phone") or {}
    if classify_wifi_phone and wifi_cfg.get("enabled", True):
        print("Wi-Fi phone detection: MAC OUI database + vendor API")
    elif not wifi_cfg.get("enabled", True):
        print("Wi-Fi phone detection: disabled in config")
    else:
        print("Wi-Fi phone detection: module missing")
    print(f"API: {api_url}")
    print(f"Interval: {SCAN_INTERVAL}s | READ ONLY")
    if bt_cfg.get("enabled", True):
        if bluetooth_ready:
            ok, msg = bluetooth_ready()
            print(f"Bluetooth: {'READY' if ok else msg}")
        else:
            print("Bluetooth: enabled (install bleak)")
    else:
        print("Bluetooth: disabled in config")
    print("=" * 50)

    scan_num = 0
    while True:
        scan_num += 1
        snmp_routers = cfg.get("snmp_routers") or []
        wifi_cfg = cfg.get("wifi_phone") or {"enabled": True}
        payload = build_scan_payload(scan_num, subnets, snmp_routers, bt_cfg, wifi_cfg)
        bt_phones = sum(1 for d in payload["devices"] if d.get("bt_likely_phone"))
        wifi_phones = sum(1 for d in payload["devices"] if d.get("wifi_likely_phone"))
        print(
            f"\n[{payload['scan_time']}] {payload['scan_id']}: {payload['total_devices']} devices "
            f"({bt_phones} BT, {wifi_phones} Wi-Fi MAC phones)"
        )
        result = post_scan(api_url, api_key, payload)
        if result:
            if not result.get("ok"):
                print(f"  API error: {result.get('error', 'unknown')}", file=sys.stderr)
            else:
                new_bt = result.get("new_bluetooth_phones") or []
                if new_bt:
                    for p in new_bt:
                        print(f"  >>> NEW BT PHONE: {p.get('hostname')} [{p.get('mac')}]")
                new_wifi = result.get("new_wifi_phones") or []
                if new_wifi:
                    for p in new_wifi:
                        print(f"  >>> NEW WI-FI PHONE (MAC): {p.get('hostname')} [{p.get('mac')}]")
                if result.get("escalation", {}).get("escalation_message"):
                    print(f"  {result['escalation']['escalation_message']}")
        else:
            print("  Scan post failed — start exam session on dashboard first", file=sys.stderr)
        time.sleep(SCAN_INTERVAL)


if __name__ == "__main__":
    main()
