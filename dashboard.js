(function () {
  const API = {
    dashboard: 'api/dashboard.php',
    session: 'api/session.php',
    clear: 'api/clear.php',
    exportCsv: 'api/export.php?format=csv',
    exportPdf: 'api/export.php?format=html',
  };

  const REFRESH_MS = 10000;
  const BT_ALARM_KEY = 'exam_bt_alarmed_macs';
  const WIFI_ALARM_KEY = 'exam_wifi_alarmed_macs';

  let refreshTimer = null;
  let lastCriticalCount = 0;
  let audioCtx = null;
  let audioUnlocked = false;
  let currentUser = null;
  let knownBtPhoneMacs = new Set();
  let knownWifiPhoneMacs = new Set();
  let lastActiveProximityPhones = [];
  let proximityBeepTimer = null;
  const fetchOpts = { credentials: 'include' };

  /** Higher dBm (e.g. -45) = closer / stronger signal */
  const PROXIMITY = {
    immediate: { minDbm: -52, label: 'VERY CLOSE' },
    near: { minDbm: -62, label: 'NEARBY' },
    medium: { minDbm: -72, label: 'IN RANGE' },
  };

  const $ = (sel) => document.querySelector(sel);

  function loadKnownBtMacs() {
    try {
      const raw = sessionStorage.getItem(BT_ALARM_KEY);
      if (raw) knownBtPhoneMacs = new Set(JSON.parse(raw));
    } catch (_) {
      knownBtPhoneMacs = new Set();
    }
  }

  function saveKnownBtMacs() {
    try {
      sessionStorage.setItem(BT_ALARM_KEY, JSON.stringify([...knownBtPhoneMacs]));
    } catch (_) {}
  }

  function loadKnownWifiMacs() {
    try {
      const raw = sessionStorage.getItem(WIFI_ALARM_KEY);
      if (raw) knownWifiPhoneMacs = new Set(JSON.parse(raw));
    } catch (_) {
      knownWifiPhoneMacs = new Set();
    }
  }

  function saveKnownWifiMacs() {
    try {
      sessionStorage.setItem(WIFI_ALARM_KEY, JSON.stringify([...knownWifiPhoneMacs]));
    } catch (_) {}
  }

  function ensureAudio() {
    try {
      if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      }
      if (audioCtx.state === 'suspended') {
        return audioCtx.resume();
      }
      audioUnlocked = true;
      return Promise.resolve();
    } catch (_) {
      return Promise.resolve();
    }
  }

  function beep(freq, durationMs, delayMs, volume) {
    if (!audioCtx) return;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    const vol = Math.min(1, Math.max(0.05, volume ?? 0.2));
    osc.type = 'square';
    osc.frequency.value = freq;
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    const t0 = audioCtx.currentTime + (delayMs || 0) / 1000;
    gain.gain.setValueAtTime(0.001, t0);
    gain.gain.exponentialRampToValueAtTime(vol, t0 + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.001, t0 + durationMs / 1000);
    osc.start(t0);
    osc.stop(t0 + durationMs / 1000 + 0.02);
  }

  function parseRssiDbm(signal) {
    if (signal == null || signal === '') return null;
    const m = String(signal).match(/(-?\d+)\s*dBm/i);
    return m ? parseInt(m[1], 10) : null;
  }

  /** Closest phone wins (highest RSSI value). */
  function strongestRssiDbm(devices) {
    let best = null;
    (devices || []).forEach((d) => {
      const dbm = parseRssiDbm(d.signal_strength);
      if (dbm != null && (best == null || dbm > best)) best = dbm;
    });
    return best;
  }

  function proximityLevel(dbm) {
    if (dbm == null) return 'far';
    if (dbm >= PROXIMITY.immediate.minDbm) return 'immediate';
    if (dbm >= PROXIMITY.near.minDbm) return 'near';
    if (dbm >= PROXIMITY.medium.minDbm) return 'medium';
    return 'far';
  }

  function proximityLabel(level) {
    if (level === 'immediate') return PROXIMITY.immediate.label;
    if (level === 'near') return PROXIMITY.near.label;
    if (level === 'medium') return PROXIMITY.medium.label;
    return '';
  }

  function clearProximityAlarmLoop() {
    if (proximityBeepTimer) {
      clearInterval(proximityBeepTimer);
      proximityBeepTimer = null;
    }
  }

  function playCriticalBeep() {
    ensureAudio().then(() => {
      beep(880, 180, 0);
      beep(1100, 180, 220);
    });
  }

  /**
   * Bluetooth phone alarm — faster and louder when RSSI shows device is close.
   * @param {'immediate'|'near'|'medium'|'far'} level
   */
  function playBluetoothPhoneAlarm(level) {
    const profiles = {
      immediate: {
        freqs: [1400, 1600, 1800, 2000, 1800, 2000, 2200, 2000],
        dur: 75,
        gap: 55,
        vol: 0.82,
        finaleFreq: 2400,
        finaleDur: 220,
        finaleVol: 0.95,
      },
      near: {
        freqs: [1200, 1400, 1600, 1400, 1600, 1800],
        dur: 95,
        gap: 75,
        vol: 0.62,
        finaleFreq: 2000,
        finaleDur: 200,
        finaleVol: 0.72,
      },
      medium: {
        freqs: [988, 1100, 1318, 1100, 1318],
        dur: 120,
        gap: 100,
        vol: 0.42,
        finaleFreq: 1479,
        finaleDur: 250,
        finaleVol: 0.5,
      },
      far: {
        freqs: [880, 988, 1100, 988, 1100],
        dur: 150,
        gap: 165,
        vol: 0.28,
        finaleFreq: 1100,
        finaleDur: 280,
        finaleVol: 0.32,
      },
    };
    const p = profiles[level] || profiles.far;
    ensureAudio().then(() => {
      p.freqs.forEach((f, i) => beep(f, p.dur, i * p.gap, p.vol));
      const tail = p.freqs.length * p.gap + 40;
      setTimeout(() => beep(p.finaleFreq, p.finaleDur, 0, p.finaleVol), tail);
    });
  }

  function syncProximityAlarmLoop() {
    clearProximityAlarmLoop();
    const phones = lastActiveProximityPhones;
    if (!phones.length) return;

    const dbm = strongestRssiDbm(phones);
    const level = proximityLevel(dbm);
    if (level === 'far') return;

    const intervalMs = level === 'immediate' ? 2200 : level === 'near' ? 3500 : 5500;
    const tick = () => {
      const liveDbm = strongestRssiDbm(lastActiveProximityPhones);
      const liveLevel = proximityLevel(liveDbm);
      if (liveLevel === 'far' || !lastActiveProximityPhones.length) {
        clearProximityAlarmLoop();
        return;
      }
      playBluetoothPhoneAlarm(liveLevel);
    };
    tick();
    proximityBeepTimer = setInterval(tick, intervalMs);
  }

  function isBtPhoneDevice(d) {
    if (!d || d.status === 'CLEARED') return false;
    return !!(d.bt_likely_phone && d.category && String(d.category).toUpperCase().includes('BLUETOOTH'));
  }

  function isWifiPhoneDevice(d) {
    if (!d || d.status === 'CLEARED') return false;
    if (d.wifi_likely_phone) return true;
    const cat = String(d.category || '').toUpperCase();
    return cat.includes('WI-FI') || (cat.includes('MOBILE PHONE') && !cat.includes('BLUETOOTH') && d.ip);
  }

  function isAnyPhoneDevice(d) {
    return isBtPhoneDevice(d) || isWifiPhoneDevice(d);
  }

  function signalProximityClass(d) {
    if (!isAnyPhoneDevice(d)) return '';
    const level = proximityLevel(parseRssiDbm(d.signal_strength));
    if (level === 'immediate') return 'signal-immediate';
    if (level === 'near' || level === 'medium') return 'signal-near';
    return '';
  }

  function mergeProximityPhones(btList, wifiList) {
    const byMac = new Map();
    [...btList, ...wifiList].forEach((p) => {
      const mac = (p.mac || '').toUpperCase();
      if (mac) byMac.set(mac, p);
    });
    return [...byMac.values()];
  }

  function handleWifiAlarms(data) {
    const phones = (data.wifi && data.wifi.phones) || (data.devices || []).filter(isWifiPhoneDevice);
    const banner = $('#wifi-alarm-banner');
    const newPhones = [];

    phones.forEach((p) => {
      const mac = (p.mac || '').toUpperCase();
      if (!mac) return;
      if (!knownWifiPhoneMacs.has(mac)) {
        newPhones.push(p);
        knownWifiPhoneMacs.add(mac);
      }
    });

    if (newPhones.length > 0) {
      const level = proximityLevel(strongestRssiDbm(newPhones)) || proximityLevel(strongestRssiDbm(phones));
      playBluetoothPhoneAlarm(level === 'far' ? 'near' : level);
      try {
        if (Notification.permission === 'granted') {
          new Notification('Wi-Fi phone detected (MAC)', {
            body: newPhones.map((p) => `${p.hostname || p.mac} [${p.mac}]`).join(', '),
          });
        }
      } catch (_) {}
      saveKnownWifiMacs();
    }

    if (phones.length > 0 && banner) {
      banner.style.display = 'block';
      const names = phones.map((p) => {
        const reason = p.mac_phone_reason ? ` (${p.mac_phone_reason})` : '';
        return `${p.hostname || p.mac} ${p.mac}${reason}`;
      }).join(', ');
      banner.textContent = `WI-FI MOBILE PHONE BY MAC (${phones.length}): ${names}`;
    } else if (banner) {
      banner.style.display = 'none';
    }

    return phones;
  }

  function handleBluetoothAlarms(data) {
    const phones = (data.bluetooth && data.bluetooth.phones) || (data.devices || []).filter(isBtPhoneDevice);
    const banner = $('#bt-alarm-banner');
    const newPhones = [];
    const strongestDbm = strongestRssiDbm(phones);
    const proxLevel = proximityLevel(strongestDbm);

    phones.forEach((p) => {
      const mac = (p.mac || '').toUpperCase();
      if (!mac) return;
      if (!knownBtPhoneMacs.has(mac)) {
        newPhones.push(p);
        knownBtPhoneMacs.add(mac);
      }
    });

    if (newPhones.length > 0) {
      const newLevel = proximityLevel(strongestRssiDbm(newPhones)) || proxLevel;
      playBluetoothPhoneAlarm(newLevel);
      try {
        if (Notification.permission === 'granted') {
          const prox = proximityLabel(newLevel);
          new Notification('Bluetooth phone detected', {
            body: `${prox ? prox + ' — ' : ''}${newPhones.map((p) => p.hostname || p.mac).join(', ')}`,
          });
        }
      } catch (_) {}
      saveKnownBtMacs();
    }

    if (phones.length > 0 && banner) {
      banner.style.display = 'block';
      banner.className = 'escalation-banner disciplinary bt-alarm-' + (proxLevel === 'far' ? 'watch' : proxLevel);
      const names = phones.map((p) => {
        const dbm = parseRssiDbm(p.signal_strength);
        const tag = dbm != null ? ` ${dbm} dBm` : '';
        return `${p.hostname || p.mac}${tag}`;
      }).join(', ');
      const proxText = proximityLabel(proxLevel);
      if (proxLevel === 'immediate' || proxLevel === 'near') {
        banner.textContent = `⚠ BLUETOOTH PHONE ${proxText} (${phones.length}): ${names} — fast alarm active!`;
      } else if (newPhones.length > 0) {
        banner.textContent = `BLUETOOTH MOBILE PHONE DETECTED (${newPhones.length}): ${names} — inspect immediately!`;
      } else {
        banner.textContent = `Bluetooth phones monitored (${phones.length}): ${names}`;
      }
    } else if (banner) {
      banner.style.display = 'none';
      banner.className = 'escalation-banner disciplinary';
    }

    const wifiPhones = handleWifiAlarms(data);
    lastActiveProximityPhones = mergeProximityPhones(phones, wifiPhones);
    syncProximityAlarmLoop();

    return newPhones;
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      ...fetchOpts,
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      ...options,
    });
    const data = await res.json();
    if (res.status === 401) {
      window.location.href = 'login.php';
      throw new Error('Unauthorized');
    }
    if (!data.ok && data.error) {
      throw new Error(data.error);
    }
    return data;
  }

  function applyRoleUI() {
    const isChief = currentUser && currentUser.role === 'chief';
    const startBtn = $('#btn-start-session');
    const closeBtn = $('#btn-close-session');
    const hint = $('#chief-only-hint');
    if (startBtn) startBtn.style.display = isChief ? '' : 'none';
    if (closeBtn) closeBtn.style.display = isChief ? '' : 'none';
    if (hint) hint.style.display = isChief ? 'none' : 'block';
    if ($('#user-display') && currentUser) {
      $('#user-display').textContent = currentUser.display_name;
      $('#user-role').textContent = currentUser.role;
    }
  }

  function riskClass(score) {
    if (score >= 8) return 'risk-crit';
    if (score >= 4) return 'risk-high';
    if (score >= 2) return 'risk-med';
    return 'risk-low';
  }

  function renderDashboard(data) {
    if (data.user) {
      currentUser = data.user;
      applyRoleUI();
    }
    const isChief = currentUser && currentUser.role === 'chief';
    const s = data.session;
    if (!s) {
      clearProximityAlarmLoop();
      lastActiveProximityPhones = [];
      $('#main-dashboard').style.display = 'none';
      $('#session-setup').style.display = 'block';
      applyRoleUI();
      return;
    }

    $('#session-setup').style.display = 'none';
    $('#main-dashboard').style.display = 'block';
    const meta = $('#exam-meta');
    if (meta) meta.style.display = 'flex';

    $('#exam-name').textContent = s.exam_name;
    $('#exam-date').textContent = s.exam_date;
    $('#exam-start').textContent = s.start_time;
    $('#exam-elapsed').textContent = s.elapsed;
    $('#chief-name').textContent = s.chief_invigilator || '—';

    const sum = data.summary || {};
    $('#stat-total').textContent = sum.total ?? 0;
    $('#stat-critical').textContent = sum.CRITICAL ?? 0;
    $('#stat-high').textContent = sum.HIGH ?? 0;
    $('#stat-medium').textContent = sum.MEDIUM ?? 0;
    $('#stat-low').textContent = sum.LOW ?? 0;

    const crit = sum.CRITICAL ?? 0;
    if (crit > lastCriticalCount) {
      playCriticalBeep();
    }
    lastCriticalCount = crit;

    handleBluetoothAlarms(data);

    const esc = data.escalation || {};
    const banner = $('#escalation-banner');
    if (esc.escalation_message) {
      banner.textContent = esc.escalation_message;
      banner.className = 'escalation-banner visible ' + (esc.escalation_level || '');
    } else {
      banner.className = 'escalation-banner';
    }

    const tbody = $('#device-tbody');
    tbody.innerHTML = '';
    (data.devices || []).forEach((d) => {
      const tr = document.createElement('tr');
      if (isBtPhoneDevice(d)) tr.className = 'bt-phone-row';
      else if (isWifiPhoneDevice(d)) tr.className = 'wifi-phone-row';
      const clearedLabel = d.cleared_by ? `Cleared by ${d.cleared_by}` : '';
      tr.innerHTML = `
        <td>${escapeHtml(d.id)}</td>
        <td>${escapeHtml(d.ip)}</td>
        <td class="mac">${escapeHtml(d.mac)}${d.mac_randomized ? ' <span class="rand-mac">RAND</span>' : ''}</td>
        <td>${escapeHtml(d.hostname)}</td>
        <td>${escapeHtml(d.vendor)}</td>
        <td class="${isAnyPhoneDevice(d) ? 'alert-CRITICAL' : ''}">${escapeHtml(d.category)}</td>
        <td class="alert-${d.alert_level}">${escapeHtml(d.alert_level)}</td>
        <td class="status-${d.status}">${escapeHtml(d.status)}${clearedLabel ? '<br><small>' + escapeHtml(clearedLabel) + '</small>' : ''}</td>
        <td>${escapeHtml(d.band)}</td>
        <td class="${signalProximityClass(d)}">${escapeHtml(d.signal_strength)}</td>
        <td>${escapeHtml(d.discovery_source || '—')}${isBtPhoneDevice(d) ? ' <span class="rand-mac">BT PHONE</span>' : ''}${isWifiPhoneDevice(d) ? ' <span class="rand-mac">WI-FI MAC</span>' : ''}</td>
        <td>
          ${isChief && !d.cleared_by ? `<button class="btn btn-sm btn-secondary" data-clear="${escapeHtml(d.id)}">Clear</button>` : (d.cleared_by ? '—' : 'Chief only')}
        </td>
      `;
      tbody.appendChild(tr);
    });

    tbody.querySelectorAll('[data-clear]').forEach((btn) => {
      btn.addEventListener('click', () => openClearModal(btn.dataset.clear));
    });

    const zonesEl = $('#zone-heatmap');
    zonesEl.innerHTML = '';
    const zones = data.zones || {};
    Object.entries(zones).forEach(([name, z]) => {
      const pct = Math.min(100, (z.risk || 0) * 12);
      const row = document.createElement('div');
      row.className = 'zone-row';
      row.innerHTML = `
        <span style="width:70px">${escapeHtml(name)}</span>
        <div class="zone-bar"><div class="zone-fill ${riskClass(z.risk)}" style="width:${pct}%"></div></div>
        <span style="width:80px;font-size:0.75rem;color:var(--muted)">${z.devices} dev</span>
      `;
      zonesEl.appendChild(row);
    });

    const tl = $('#timeline');
    tl.innerHTML = '';
    (data.timeline || []).forEach((e) => {
      const li = document.createElement('li');
      const highlight = e.event_type === 'bluetooth_phone'
        || e.event_type === 'wifi_phone'
        || e.event_type === 'magnetometer_electronics'
        || e.event_type === 'vision_phone';
      li.innerHTML = `<strong>${escapeHtml(e.event_time)}</strong> — <span class="${highlight ? 'alert-CRITICAL' : ''}">${escapeHtml(e.event_type)}</span>: ${escapeHtml(e.details || '')}`;
      tl.appendChild(li);
    });

    const al = $('#actions-log');
    al.innerHTML = '';
    (data.actions || []).forEach((a) => {
      const li = document.createElement('li');
      li.innerHTML = `<strong>${escapeHtml(a.action_time)}</strong> ${escapeHtml(a.invigilator_name)} — ${escapeHtml(a.action_type)} ${escapeHtml(a.device_id || '')}`;
      al.appendChild(li);
    });

    const alertsEl = $('#alerts-list');
    alertsEl.innerHTML = '';
    (data.alerts || []).slice(0, 30).forEach((a) => {
      const li = document.createElement('li');
      li.innerHTML = `<span class="alert-${a.alert_level}">${escapeHtml(a.alert_level)}</span> ${escapeHtml(a.alert_time)} — ${escapeHtml(a.mac_address)} — ${escapeHtml(a.estimated_zone || '')}`;
      alertsEl.appendChild(li);
    });

    $('#last-refresh').textContent = new Date().toLocaleTimeString();
  }

  function escapeHtml(s) {
    if (s == null) return '';
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  let pendingClearDevice = null;

  function openClearModal(deviceId) {
    pendingClearDevice = deviceId;
    $('#clear-modal').classList.add('open');
    $('#clear-invigilator').value = $('#chief-name').textContent || '';
    $('#clear-invigilator').focus();
  }

  async function refresh() {
    try {
      const data = await fetchJson(API.dashboard);
      renderDashboard(data);
    } catch (e) {
      console.error(e);
    }
  }

  async function startSession() {
    knownBtPhoneMacs = new Set();
    knownWifiPhoneMacs = new Set();
    saveKnownBtMacs();
    saveKnownWifiMacs();
    const body = {
      action: 'start',
      exam_name: $('#input-exam-name').value.trim() || 'Exam Session',
      exam_date: $('#input-exam-date').value || new Date().toISOString().slice(0, 10),
      start_time: new Date().toTimeString().slice(0, 8),
      chief_invigilator: $('#input-chief').value.trim() || 'Chief Invigilator',
    };
    await fetchJson(API.session, { method: 'POST', body: JSON.stringify(body) });
    await refresh();
  }

  async function closeSession() {
    if (!confirm('Close exam session? Logs retained per policy.')) return;
    await fetchJson(API.session, { method: 'POST', body: JSON.stringify({ action: 'close' }) });
    await refresh();
  }

  async function submitClear() {
    const name = $('#clear-invigilator').value.trim();
    if (!name || !pendingClearDevice) return;
    await fetchJson(API.clear, {
      method: 'POST',
      body: JSON.stringify({ device_id: pendingClearDevice, invigilator_name: name }),
    });
    $('#clear-modal').classList.remove('open');
    pendingClearDevice = null;
    await refresh();
  }

  function setupAudioUnlock() {
    const unlock = () => {
      ensureAudio().then(() => {
        audioUnlocked = true;
        const hint = $('#audio-hint');
        if (hint) hint.style.display = 'none';
      });
    };
    document.body.addEventListener('click', unlock, { once: true });
    document.body.addEventListener('keydown', unlock, { once: true });
  }

  $('#btn-start-session')?.addEventListener('click', startSession);
  $('#btn-close-session')?.addEventListener('click', closeSession);
  $('#btn-refresh')?.addEventListener('click', refresh);
  $('#btn-export-csv')?.addEventListener('click', () => window.open(API.exportCsv, '_blank'));
  $('#btn-export-pdf')?.addEventListener('click', () => window.open(API.exportPdf, '_blank'));
  $('#btn-clear-submit')?.addEventListener('click', submitClear);
  $('#btn-clear-cancel')?.addEventListener('click', () => $('#clear-modal').classList.remove('open'));
  $('#btn-enable-audio')?.addEventListener('click', () => {
    ensureAudio().then(() => {
      audioUnlocked = true;
      playBluetoothPhoneAlarm('near');
    });
  });

  $('#btn-logout')?.addEventListener('click', async () => {
    await fetchJson('api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'logout' }) });
    window.location.href = 'login.php';
  });

  $('#input-exam-date').value = new Date().toISOString().slice(0, 10);

  loadKnownBtMacs();
  loadKnownWifiMacs();
  setupAudioUnlock();

  (async function init() {
    try {
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
      }
    } catch (_) {}
    try {
      const auth = await fetchJson('api/auth.php');
      currentUser = auth.user;
      applyRoleUI();
    } catch (_) {}
    await refresh();
    refreshTimer = setInterval(refresh, REFRESH_MS);
  })();
})();
