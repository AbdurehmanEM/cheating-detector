#!/usr/bin/env python3
"""One-shot Bluetooth scan and post to API."""
import json
import sys
import urllib.request
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from bluetooth_scanner import collect_bluetooth_devices, bluetooth_ready
from scan_worker import load_config, post_scan

cfg = load_config()
bt_cfg = dict(cfg.get("bluetooth") or {"enabled": True})
bt_cfg["scan_seconds"] = 10

ok, msg = bluetooth_ready()
print("Bluetooth check:", "OK" if ok else msg)
if not ok:
    sys.exit(1)

print("Scanning 10 seconds — phone Bluetooth ON, within 2m of this PC...")
devices = collect_bluetooth_devices(bt_cfg)
phones = [v for v in devices.values() if v.get("bt_likely_phone")]
print(f"Found {len(devices)} BT devices, {len(phones)} phone(s)\n")
for v in devices.values():
    mark = " *** PHONE ***" if v.get("bt_likely_phone") else ""
    print(f"  {v['mac']}  {v.get('hostname')}{mark}")

now = datetime.now()
tshort = now.strftime("%H:%M:%S")
payload = {
    "scan_id": f"SCN-BT-{now.strftime('%H%M%S')}",
    "scan_time": now.strftime("%Y-%m-%d %H:%M:%S"),
    "subnets_scanned": ["bluetooth-ble"],
    "devices": [],
}
for idx, (key, data) in enumerate(devices.items(), start=1):
    payload["devices"].append({
        "id": f"DEV-BT-{idx:03d}",
        "ip": "",
        "mac": data["mac"],
        "hostname": data.get("hostname", "unknown"),
        "signal_strength": data.get("signal_strength", "-70 dBm"),
        "band": "Bluetooth",
        "first_seen": tshort,
        "last_seen": tshort,
        "status": "CRITICAL" if data.get("bt_likely_phone") else "FLAGGED",
        "discovery_source": data.get("discovery_source") or data.get("source", "bluetooth-ble"),
        "bt_likely_phone": bool(data.get("bt_likely_phone")),
    })
payload["total_devices"] = len(payload["devices"])

print("\nPosting to API...")
result = post_scan(cfg.get("api_url", ""), cfg.get("api_key", ""), payload)
if result and result.get("ok"):
    print("SUCCESS — check dashboard (refresh + click page for alarm sound)")
    for p in result.get("new_bluetooth_phones") or []:
        print(f"  NEW: {p.get('hostname')} {p.get('mac')}")
else:
    print("FAILED — open dashboard, click Start Session, then retry")
