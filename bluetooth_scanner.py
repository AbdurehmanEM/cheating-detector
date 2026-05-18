"""
Bluetooth / BLE discovery for mobile phone detection (read-only).
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import re
import subprocess
import sys
from typing import Any

PHONE_NAME_PATTERNS = [
    r"\biphone\b", r"\bipad\b", r"\bgalaxy\b", r"\bpixel\b", r"\bredmi\b",
    r"\boppo\b", r"\bvivo\b", r"\boneplus\b", r"\bhuawei\b", r"\bhonor\b",
    r"\bnokia\b", r"\bmotorola\b", r"\bxperia\b", r"\btecno\b", r"\binfinix\b",
    r"\bitel\b", r"\brealme\b", r"\bnothing\b", r"\bphone\b", r"\bmobile\b",
    r"\bandroid\b", r"\bsmartphone\b", r"sm-[a-z]\d{3}",
]

# PC / laptop Bluetooth — never classify as exam phone
PC_BT_EXCLUDE = [
    "intel", "microsoft", "microphone", "enumerator", "avrcp", "smart sound",
    "realtek", "qualcomm", "bthenum", "generic bluetooth", "bluetooth device",
    "personal area network", "rfcomm", "transport", "wireless bluetooth",
    "bluetooth le", "mu02", "byd ble", "headset", "headphone", "speaker",
    "keyboard", "mouse", "trackpad", "pen", "surface", "controller",
]

PHONE_BLE_SERVICE_HINTS = (
    "0000180f", "0000180a", "0000fe2c", "0000fd6f", "0000fee7",
)
APPLE_COMPANY_ID = 0x004C
_BleakScanner = None


def _import_bleak():
    global _BleakScanner
    if _BleakScanner is None:
        from bleak import BleakScanner
        _BleakScanner = BleakScanner
    return _BleakScanner


def bluetooth_ready() -> tuple[bool, str]:
    """Check bleak installed and Windows Bluetooth radio present."""
    try:
        _import_bleak()
    except ImportError:
        return False, "bleak not installed — run: pip install bleak"

    if sys.platform == "win32":
        ps = (
            "Get-PnpDevice -Class Bluetooth -ErrorAction SilentlyContinue | "
            "Where-Object { $_.Status -eq 'OK' } | Select-Object -First 1 | "
            "ConvertTo-Json -Compress"
        )
        out = _run_ps(ps, timeout=15)
        if not out or out == "null":
            return False, "Windows Bluetooth radio not found or disabled"
    return True, "ok"


def normalize_mac(raw: str) -> str:
    raw = re.sub(r"[^0-9a-fA-F]", "", raw or "")
    if len(raw) != 12:
        return (raw or "").upper()
    return ":".join(raw[i : i + 2].upper() for i in range(0, 12, 2))


def mac_from_registry_key(key: str) -> str:
    raw = re.sub(r"[^0-9a-fA-F]", "", key)
    if len(raw) == 12:
        return normalize_mac(raw)
    return ""


def synthetic_mac(seed: str) -> str:
    return normalize_mac(hashlib.sha256(seed.encode()).hexdigest()[:12])


def normalize_device_name(raw: Any) -> str:
    if raw is None:
        return "unknown"
    if isinstance(raw, list):
        raw = raw[0] if raw else "unknown"
    return str(raw).strip() or "unknown"


def is_pc_peripheral(name: str | None) -> bool:
    if not name:
        return False
    n = name.lower()
    return any(ex in n for ex in PC_BT_EXCLUDE)


def is_phone_name(name: str | None) -> bool:
    if not name or name.lower() in ("unknown", "n/a", "", "ble device"):
        return False
    if is_pc_peripheral(name):
        return False
    n = name.lower()
    return any(re.search(pat, n, re.I) for pat in PHONE_NAME_PATTERNS)


def is_locally_administered_mac(mac: str) -> bool:
    parts = re.split(r"[-:]", mac.strip())
    if not parts:
        return False
    try:
        return (int(parts[0], 16) & 0x02) != 0
    except ValueError:
        return False


def rssi_to_dbm(rssi: int | None) -> str:
    if rssi is None:
        return "-70 dBm"
    return f"{int(rssi)} dBm"


def parse_dbm(signal: str | None) -> int | None:
    if not signal:
        return None
    m = re.search(r"(-?\d+)\s*dBm", str(signal), re.I)
    return int(m.group(1)) if m else None


def stronger_signal(a: str | None, b: str | None) -> str | None:
    """Keep higher dBm (closer device)."""
    da, db = parse_dbm(a), parse_dbm(b)
    if da is None:
        return b
    if db is None:
        return a
    return a if da >= db else b


def _manufacturer_is_apple(manufacturer: dict | None) -> bool:
    if not manufacturer:
        return False
    for key in manufacturer:
        try:
            if int(key) == APPLE_COMPANY_ID:
                return True
        except (TypeError, ValueError):
            continue
    return False


def _services_hint_phone(service_uuids: list[str] | None) -> bool:
    if not service_uuids:
        return False
    joined = " ".join(u.lower().replace("-", "") for u in service_uuids)
    return any(h in joined for h in PHONE_BLE_SERVICE_HINTS)


def classify_bt_device(
    name: str | None,
    mac: str,
    manufacturer: dict | None = None,
    service_uuids: list[str] | None = None,
    *,
    source: str = "bluetooth-ble",
    aggressive: bool = True,
) -> bool:
    if is_pc_peripheral(name):
        return False
    if is_phone_name(name):
        return True
    if source.startswith("bluetooth-ble"):
        if _manufacturer_is_apple(manufacturer):
            return True
        if _services_hint_phone(service_uuids):
            return True
        if aggressive and is_locally_administered_mac(mac):
            return True
    if source == "bluetooth-registry" and is_phone_name(name):
        return True
    if source == "bluetooth-pnp" and is_phone_name(name):
        return True
    return False


def _parse_ble_entry(device: Any, adv: Any) -> dict[str, Any] | None:
    address = getattr(device, "address", None) or str(device)
    mac = normalize_mac(address)
    if not mac or len(mac) < 11:
        return None

    name = getattr(device, "name", None) or "unknown"
    local_name = None
    rssi = None
    mfg = None
    services: list[str] = []

    if adv is not None:
        local_name = getattr(adv, "local_name", None)
        rssi = getattr(adv, "rssi", None)
        mfg = getattr(adv, "manufacturer_data", None)
        su = getattr(adv, "service_uuids", None)
        if su:
            services = list(su)

    display = local_name or name or "unknown"
    likely = classify_bt_device(
        display, mac, mfg, services, source="bluetooth-ble", aggressive=True
    )
    if likely and display == "unknown" and is_locally_administered_mac(mac):
        display = "Mobile phone (Bluetooth BLE)"

    return {
        "mac": mac,
        "hostname": display,
        "signal_strength": rssi_to_dbm(rssi),
        "band": "Bluetooth",
        "source": "bluetooth-ble",
        "bt_likely_phone": likely,
    }


async def _ble_scan_async(duration: float) -> list[dict[str, Any]]:
    BleakScanner = _import_bleak()
    results: list[dict[str, Any]] = []
    seen: set[str] = set()

    discovered = await BleakScanner.discover(timeout=duration, return_adv=True)

    if isinstance(discovered, dict):
        items = discovered.values()
    elif isinstance(discovered, list):
        items = [(d, None) for d in discovered]
    else:
        items = []

    for item in items:
        if isinstance(item, tuple) and len(item) >= 2:
            row = _parse_ble_entry(item[0], item[1])
        else:
            row = _parse_ble_entry(item, None)
        if row and row["mac"] not in seen:
            seen.add(row["mac"])
            results.append(row)

    return results


def scan_ble(duration: float = 6.0) -> list[dict[str, Any]]:
    ready, msg = bluetooth_ready()
    if not ready:
        print(f"[bluetooth] {msg}", file=sys.stderr)
        return []
    try:
        if sys.platform == "win32":
            asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
        return asyncio.run(_ble_scan_async(duration))
    except Exception as exc:
        print(f"[bluetooth] BLE scan failed: {exc}", file=sys.stderr)
        return []


def _run_ps(script: str, timeout: int = 30) -> str:
    try:
        r = subprocess.run(
            ["powershell", "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", script],
            capture_output=True,
            text=True,
            timeout=timeout,
            creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == "win32" else 0,
        )
        return (r.stdout or "").strip()
    except (FileNotFoundError, subprocess.TimeoutExpired):
        return ""


def scan_windows_pnp() -> list[dict[str, Any]]:
    if sys.platform != "win32":
        return []

    ps = r"""
$out = @()
$bt = Get-PnpDevice -PresentOnly -ErrorAction SilentlyContinue |
  Where-Object {
    $_.FriendlyName -match 'iPhone|iPad|Galaxy|Pixel|Android|Phone|Mobile|Redmi|OPPO|vivo|OnePlus|Huawei|Honor|Nokia|Motorola|Xiaomi|Realme|Tecno|Infinix'
  }
foreach ($d in $bt) {
  $out += [PSCustomObject]@{ Name = $d.FriendlyName; Id = $d.InstanceId }
}
$out | ConvertTo-Json -Compress
"""
    raw = _run_ps(ps)
    if not raw:
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return []
    if isinstance(data, dict):
        data = [data]

    results: list[dict[str, Any]] = []
    for item in data:
        name = normalize_device_name(item.get("Name"))
        if not is_phone_name(name):
            continue
        iid = item.get("Id") or ""
        mac_match = re.search(r"([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})", iid)
        mac = normalize_mac(mac_match.group(0)) if mac_match else synthetic_mac(name + iid)
        results.append({
            "mac": mac,
            "hostname": name,
            "signal_strength": "-62 dBm",
            "band": "Bluetooth",
            "source": "bluetooth-pnp",
            "bt_likely_phone": True,
        })
    return results


def scan_windows_registry() -> list[dict[str, Any]]:
    if sys.platform != "win32":
        return []

    ps = r"""
$results = @()
$path = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Bluetooth\Devices'
if (Test-Path $path) {
  Get-ChildItem $path -ErrorAction SilentlyContinue | ForEach-Object {
    $mac = $_.PSChildName
    $name = ''
    try { $name = (Get-ItemProperty $_.PSPath -ErrorAction SilentlyContinue).Name } catch {}
    if (-not $name) { $name = $mac }
    $results += [PSCustomObject]@{ Mac = $mac; Name = $name }
  }
}
$results | ConvertTo-Json -Compress
"""
    raw = _run_ps(ps)
    if not raw:
        return []
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return []
    if isinstance(data, dict):
        data = [data]

    results: list[dict[str, Any]] = []
    for item in data:
        name = normalize_device_name(item.get("Name"))
        if not is_phone_name(name):
            continue
        mac = mac_from_registry_key(str(item.get("Mac") or "")) or synthetic_mac(name)
        results.append({
            "mac": mac,
            "hostname": name,
            "signal_strength": "-60 dBm",
            "band": "Bluetooth",
            "source": "bluetooth-registry",
            "bt_likely_phone": True,
        })
    return results


def collect_bluetooth_devices(config: dict | None = None) -> dict[str, dict[str, Any]]:
    cfg = config or {}
    if not cfg.get("enabled", True):
        return {}

    duration = float(cfg.get("scan_seconds", 6))
    use_ble = cfg.get("use_ble", True)
    use_pnp = cfg.get("use_windows_pnp", True)
    use_registry = cfg.get("use_windows_registry", True)

    by_mac: dict[str, dict[str, Any]] = {}

    def merge_dev(dev: dict[str, Any]) -> None:
        mac = dev["mac"]
        if mac not in by_mac:
            by_mac[mac] = dev
            return
        cur = by_mac[mac]
        if dev.get("bt_likely_phone"):
            cur["bt_likely_phone"] = True
        if dev.get("hostname") and dev["hostname"] not in ("unknown", ""):
            cur["hostname"] = dev["hostname"]
        if dev.get("signal_strength"):
            cur["signal_strength"] = stronger_signal(
                cur.get("signal_strength"), dev.get("signal_strength")
            )
        src, ns = cur.get("source", ""), dev.get("source", "")
        if ns and ns not in src:
            cur["source"] = f"{src}+{ns}" if src else ns

    if use_ble:
        print(f"[bluetooth] BLE scan ({duration}s)...", file=sys.stderr)
        for dev in scan_ble(duration):
            merge_dev(dev)

    if use_pnp:
        for dev in scan_windows_pnp():
            merge_dev(dev)

    if use_registry:
        for dev in scan_windows_registry():
            merge_dev(dev)

    out: dict[str, dict[str, Any]] = {}
    phone_count = 0
    for mac, dev in by_mac.items():
        is_phone = bool(dev.get("bt_likely_phone"))
        if is_phone:
            phone_count += 1
        out[f"bt-{mac}"] = {
            "mac": mac,
            "hostname": dev.get("hostname", "unknown"),
            "signal_strength": dev.get("signal_strength", "-70 dBm"),
            "band": "Bluetooth",
            "source": dev.get("source", "bluetooth-ble"),
            "discovery_source": dev.get("source", "bluetooth-ble"),
            "bt_likely_phone": is_phone,
        }

    print(f"[bluetooth] Devices: {len(out)}, phones: {phone_count}", file=sys.stderr)
    if phone_count:
        print(f"[bluetooth] *** {phone_count} MOBILE PHONE(S) via Bluetooth ***", file=sys.stderr)
    return out
