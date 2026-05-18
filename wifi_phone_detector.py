"""
Wi-Fi mobile phone detection using MAC address (OUI), vendor, and hostname.
"""

from __future__ import annotations

import json
import re
import urllib.error
import urllib.request
from functools import lru_cache
from pathlib import Path
from typing import Any

DATA_PATH = Path(__file__).resolve().parent.parent / "data" / "phone_mac_oui.json"
_vendor_cache: dict[str, str | None] = {}


def normalize_mac(mac: str) -> str:
    raw = re.sub(r"[^0-9a-fA-F]", "", mac or "")
    if len(raw) != 12:
        return (mac or "").upper()
    return ":".join(raw[i : i + 2].upper() for i in range(0, 12, 2))


def extract_oui(mac: str) -> str:
    parts = normalize_mac(mac).split(":")
    return ":".join(parts[:3]) if len(parts) >= 3 else ""


def is_mac_randomized(mac: str) -> bool:
    parts = normalize_mac(mac).split(":")
    if not parts or not parts[0]:
        return False
    try:
        return (int(parts[0], 16) & 0x02) != 0
    except ValueError:
        return False


@lru_cache(maxsize=1)
def load_phone_mac_data() -> dict[str, Any]:
    if not DATA_PATH.exists():
        return {"oui_prefixes": [], "vendor_keywords": [], "hostname_keywords": []}
    with open(DATA_PATH, encoding="utf-8") as f:
        data = json.load(f)
    prefixes = {p.upper().replace("-", ":") for p in data.get("oui_prefixes", [])}
    data["_oui_set"] = prefixes
    return data


def is_known_phone_oui(mac: str) -> bool:
    oui = extract_oui(mac)
    if not oui:
        return False
    return oui in load_phone_mac_data().get("_oui_set", set())


def vendor_matches_phone(vendor: str | None) -> bool:
    if not vendor:
        return False
    v = vendor.lower()
    if any(
        x in v
        for x in ("router", "switch", "access point", "laptop", "notebook", "desktop", "server", "printer")
    ):
        return False
    for kw in load_phone_mac_data().get("vendor_keywords", []):
        if kw.lower() in v:
            return True
    return False


def is_likely_gateway(ip: str | None, hostname: str | None = None) -> bool:
    """Skip router/gateway false positives unless hostname clearly is a phone."""
    if not ip or not re.match(r"^\d+\.\d+\.\d+\.\d+$", ip):
        return False
    last = ip.rsplit(".", 1)[-1]
    if last in ("0", "1", "254") and not hostname_matches_phone(hostname):
        return True
    if hostname:
        h = hostname.lower()
        for kw in ("router", "gateway", "switch", "accesspoint", "access-point", "ap-", "mikrotik", "tplink", "archer", "decoh", "extender"):
            if kw in h:
                return True
    return False


def hostname_matches_phone(hostname: str | None) -> bool:
    if not hostname or hostname.strip().lower() in ("", "unknown"):
        return False
    h = hostname.lower()
    for kw in load_phone_mac_data().get("hostname_keywords", []):
        if kw.lower() in h:
            return True
    return bool(re.search(r"\bsm-[a-z]\d{3}", h, re.I)) or bool(re.search(r"\brmx\d{4}", h, re.I))


def lookup_vendor_api(oui: str, api_base: str = "https://api.macvendors.com/") -> str | None:
    key = oui.replace(":", "").upper()
    if key in _vendor_cache:
        return _vendor_cache[key]
    url = api_base + key
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "ExamPhoneScanner/1.0"})
        with urllib.request.urlopen(req, timeout=5) as resp:
            body = resp.read().decode(errors="replace").strip()
        if not body or "not found" in body.lower():
            _vendor_cache[key] = None
            return None
        _vendor_cache[key] = body
        return body
    except (urllib.error.URLError, TimeoutError, OSError):
        _vendor_cache[key] = None
        return None


def classify_wifi_phone(
    mac: str,
    *,
    hostname: str | None = None,
    vendor: str | None = None,
    band: str | None = None,
    ip: str | None = None,
    is_bluetooth: bool = False,
    lookup_vendor: bool = True,
    privacy_mac_on_wifi: bool = True,
    api_base: str = "https://api.macvendors.com/",
) -> dict[str, Any]:
    """
    Returns wifi_likely_phone, mac_phone_reason, vendor (resolved), oui.
    Skips pure Bluetooth-only entries unless they also have a Wi-Fi IP.
    """
    mac_n = normalize_mac(mac)
    oui = extract_oui(mac_n)
    randomized = is_mac_randomized(mac_n)
    has_wifi_ip = bool(ip and re.match(r"^\d+\.\d+\.\d+\.\d+$", str(ip)))

    if is_bluetooth and not has_wifi_ip:
        return {
            "wifi_likely_phone": False,
            "mac_phone_reason": "",
            "vendor": vendor,
            "oui": oui,
            "mac_randomized": randomized,
        }

    if is_likely_gateway(str(ip) if ip else None, hostname):
        return {
            "wifi_likely_phone": False,
            "mac_phone_reason": "",
            "vendor": vendor,
            "oui": oui,
            "mac_randomized": randomized,
        }

    resolved_vendor = vendor
    if lookup_vendor and not resolved_vendor and oui:
        resolved_vendor = lookup_vendor_api(oui, api_base)

    reason = ""
    if is_known_phone_oui(mac_n):
        reason = "known_phone_oui"
    elif hostname_matches_phone(hostname):
        reason = "phone_hostname"
    elif vendor_matches_phone(resolved_vendor):
        reason = "phone_vendor_oui"
    elif (
        privacy_mac_on_wifi
        and randomized
        and has_wifi_ip
        and band
        and "bluetooth" not in str(band).lower()
    ):
        reason = "wifi_privacy_mac"

    return {
        "wifi_likely_phone": bool(reason),
        "mac_phone_reason": reason,
        "vendor": resolved_vendor,
        "oui": oui,
        "mac_randomized": randomized,
    }
