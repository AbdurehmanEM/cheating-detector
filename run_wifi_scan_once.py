#!/usr/bin/env python3
"""One-shot Wi-Fi LAN scan (ARP + MAC phone detection) and post to API."""
import re
import sys
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from scan_worker import (
    discover_local_subnets,
    load_config,
    merge_sources,
    parse_arp,
    ping_sweep_subnet,
    post_scan,
    resolve_hostname,
)
from wifi_phone_detector import classify_wifi_phone

cfg = load_config()
wifi_cfg = dict(cfg.get("wifi_phone") or {"enabled": True})
subnets = cfg.get("subnets") or discover_local_subnets()
ping_limit = int(wifi_cfg.get("ping_limit", 64))

print("Wi-Fi phone scan (MAC OUI + hostname + vendor API)")
print(f"Subnets: {', '.join(subnets[:3])} | ping sweep: {ping_limit} hosts\n")

for cidr in subnets[:2]:
    ping_sweep_subnet(cidr, limit=ping_limit)

arp = parse_arp()
merged = merge_sources(arp, {})
now = datetime.now()
tshort = now.strftime("%H:%M:%S")
devices_out = []
wifi_phones = []

for idx, (ip, data) in enumerate(sorted(merged.items()), start=1):
    mac = data["mac"]
    hostname = data.get("hostname") or "unknown"
    if hostname == "unknown":
        hostname = resolve_hostname(ip)
    wf = classify_wifi_phone(
        mac,
        hostname=hostname,
        ip=ip,
        lookup_vendor=bool(wifi_cfg.get("lookup_vendor", True)),
        privacy_mac_on_wifi=bool(wifi_cfg.get("privacy_mac_on_wifi", True)),
    )
    is_wifi = bool(wf.get("wifi_likely_phone"))
    if is_wifi:
        wifi_phones.append((ip, mac, hostname, wf.get("mac_phone_reason")))
    devices_out.append({
        "id": f"DEV-WF-{idx:03d}",
        "ip": ip,
        "mac": mac,
        "hostname": hostname,
        "signal_strength": "-68 dBm",
        "band": "2.4GHz",
        "first_seen": tshort,
        "last_seen": tshort,
        "status": "CRITICAL" if is_wifi else "FLAGGED",
        "discovery_source": "wifi-mac-scan",
        "bt_likely_phone": False,
        "wifi_likely_phone": is_wifi,
        "mac_phone_reason": wf.get("mac_phone_reason") or "",
        "vendor_hint": wf.get("vendor") or "",
    })

print(f"LAN devices: {len(devices_out)}, Wi-Fi phones: {len(wifi_phones)}\n")
for ip, mac, host, reason in wifi_phones:
    print(f"  *** PHONE *** {ip}  {mac}  {host}  ({reason})")
for d in devices_out:
    if not d["wifi_likely_phone"]:
        print(f"  {d['ip']}  {d['mac']}  {d['hostname']}")

payload = {
    "scan_id": f"SCN-WIFI-{now.strftime('%H%M%S')}",
    "scan_time": now.strftime("%Y-%m-%d %H:%M:%S"),
    "subnets_scanned": subnets[:2],
    "total_devices": len(devices_out),
    "devices": devices_out,
}

print("\nPosting to API...")
result = post_scan(cfg.get("api_url", ""), cfg.get("api_key", ""), payload)
if result and result.get("ok"):
    print("SUCCESS — refresh dashboard (Ctrl+F5), click page once for alarm")
    for p in result.get("new_wifi_phones") or []:
        print(f"  NEW WI-FI PHONE: {p.get('hostname')} [{p.get('mac')}]")
else:
    print("FAILED — start exam session on dashboard first, then retry")
