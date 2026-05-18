(function () {
  const API = 'api/magnetometer.php';
  const fetchOpts = { credentials: 'include', headers: { 'Content-Type': 'application/json' } };

  const $ = (id) => document.getElementById(id);

  let sensor = null;
  let running = false;
  let baseline = null;
  let baselineSamples = [];
  let calibrating = false;
  let audioCtx = null;
  let hitCount = 0;
  let lastAlertAt = 0;
  let lastLogAt = 0;
  let consecutiveHits = 0;
  let sessionActive = false;

  const state = {
    x: 0,
    y: 0,
    z: 0,
    magnitude: 0,
    delta: 0,
  };

  function getThreshold() {
    return parseFloat($('threshold')?.value || '18');
  }

  function getSensitivity() {
    return parseInt($('sensitivity')?.value || '3', 10);
  }

  function magnitude(x, y, z) {
    return Math.sqrt(x * x + y * y + z * z);
  }

  function ensureAudio() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') return audioCtx.resume();
    } catch (_) {}
    return Promise.resolve();
  }

  function playAlertSound(strong) {
    ensureAudio().then(() => {
      if (!audioCtx) return;
      const freqs = strong ? [880, 1200, 1600, 2000] : [660, 880, 1100];
      freqs.forEach((f, i) => {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.type = 'square';
        osc.frequency.value = f;
        gain.gain.value = strong ? 0.35 : 0.22;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        const t = audioCtx.currentTime + i * 0.09;
        osc.start(t);
        osc.stop(t + 0.1);
      });
    });
    try {
      if (navigator.vibrate) navigator.vibrate(strong ? [80, 40, 80, 40, 120] : [60, 30, 60]);
    } catch (_) {}
  }

  function updateUI() {
    const mag = state.magnitude;
    const delta = baseline != null ? Math.abs(mag - baseline) : 0;
    state.delta = delta;

    $('field-value').textContent = mag.toFixed(1);
    $('delta-value').textContent = baseline != null ? `Δ ${delta.toFixed(1)} µT` : 'Calibrate first';
    $('baseline-value').textContent = baseline != null ? baseline.toFixed(1) : '—';

    const threshold = getThreshold();
    const pct = Math.min(100, (delta / threshold) * 100);
    $('bar-fill').style.width = `${pct}%`;

    const alert = baseline != null && delta >= threshold;
    $('gauge-ring').classList.toggle('alert', alert);
    $('alert-banner').classList.toggle('visible', alert);

    if (alert) {
      $('status-text').textContent = 'Strong magnetic anomaly — possible phone or electronics nearby';
    } else if (running) {
      $('status-text').textContent = 'Sweep slowly over desks and pockets';
    } else {
      $('status-text').textContent = 'Tap Start scan, then calibrate away from metal';
    }
  }

  function onReading(x, y, z) {
    if (calibrating) {
      baselineSamples.push(magnitude(x, y, z));
      return;
    }
    state.x = x;
    state.y = y;
    state.z = z;
    state.magnitude = magnitude(x, y, z);
    updateUI();

    if (baseline == null || !running) return;

    const delta = Math.abs(state.magnitude - baseline);
    const threshold = getThreshold();
    const needHits = getSensitivity();

    if (delta >= threshold) {
      consecutiveHits += 1;
    } else {
      consecutiveHits = 0;
    }

    if (consecutiveHits >= needHits) {
      const now = Date.now();
      if (now - lastAlertAt > 1800) {
        lastAlertAt = now;
        consecutiveHits = 0;
        hitCount += 1;
        $('hit-count').textContent = String(hitCount);
        const strong = delta >= threshold * 1.6;
        playAlertSound(strong);
        logHit(delta, strong);
        addHitLog(delta);
      }
    }
  }

  function addHitLog(delta) {
    const ul = $('hits-list');
    if (!ul) return;
    const li = document.createElement('li');
    li.textContent = `${new Date().toLocaleTimeString()} — Δ ${delta.toFixed(1)} µT — possible electronics`;
    ul.prepend(li);
    while (ul.children.length > 20) ul.removeChild(ul.lastChild);
  }

  async function logHit(delta, strong) {
    if (!sessionActive || Date.now() - lastLogAt < 4000) return;
    lastLogAt = Date.now();
    try {
      await fetch(API, {
        ...fetchOpts,
        method: 'POST',
        body: JSON.stringify({
          action: 'detect',
          magnitude: state.magnitude,
          delta,
          peak: delta,
          label: strong ? 'mobile phone / electronics (strong)' : 'electronics / mobile phone',
        }),
      });
    } catch (_) {}
  }

  async function requestIOSPermission() {
    if (typeof DeviceOrientationEvent === 'undefined') return true;
    if (typeof DeviceOrientationEvent.requestPermission !== 'function') return true;
    try {
      const r = await DeviceOrientationEvent.requestPermission();
      return r === 'granted';
    } catch (_) {
      return false;
    }
  }

  function startMagnetometerAPI() {
    return new Promise((resolve, reject) => {
      if (!('Magnetometer' in window)) {
        reject(new Error('Magnetometer API not supported on this browser/device. Use Chrome on Android.'));
        return;
      }
      try {
        sensor = new Magnetometer({ frequency: 60 });
        sensor.addEventListener('reading', () => {
          onReading(sensor.x, sensor.y, sensor.z);
        });
        sensor.addEventListener('error', (e) => reject(e.error || new Error('Sensor error')));
        sensor.start();
        resolve();
      } catch (e) {
        reject(e);
      }
    });
  }

  /** Fallback: some builds expose magnetometer via Generic Sensor permission only on HTTPS */
  function startOrientationFallback() {
    return new Promise((resolve, reject) => {
      let lastAlpha = null;
      let tick = 0;
      const handler = (e) => {
        tick += 1;
        const a = e.webkitCompassHeading ?? e.alpha;
        if (a == null) return;
        if (lastAlpha != null) {
          let diff = Math.abs(a - lastAlpha);
          if (diff > 180) diff = 360 - diff;
          const pseudo = 45 + diff * 2.5;
          onReading(pseudo, pseudo * 0.3, pseudo * 0.7);
        }
        lastAlpha = a;
      };
      window.addEventListener('deviceorientationabsolute', handler, true);
      window.addEventListener('deviceorientation', handler, true);
      sensor = {
        stop: () => {
          window.removeEventListener('deviceorientationabsolute', handler, true);
          window.removeEventListener('deviceorientation', handler, true);
        },
      };
      setTimeout(() => {
        if (tick > 2) resolve();
        else reject(new Error('Compass fallback unavailable — use Android Chrome for magnetometer'));
      }, 1500);
    });
  }

  async function startScan() {
    $('error-box').style.display = 'none';
    const iosOk = await requestIOSPermission();
    if (!iosOk) {
      showError('Motion sensor permission denied. Enable in Settings → Safari → Motion.');
      return;
    }
    try {
      await startMagnetometerAPI();
      $('sensor-mode').textContent = 'Magnetometer active';
    } catch (e1) {
      try {
        await startOrientationFallback();
        $('sensor-mode').textContent = 'Compass fallback (less accurate)';
      } catch (e2) {
        showError(
          (e1.message || 'Magnetometer unavailable') +
            '. Best results: Android phone + Chrome, HTTPS or localhost, hold phone flat and sweep over bags/desks.'
        );
        return;
      }
    }
    running = true;
    $('btn-start').disabled = true;
    $('btn-stop').disabled = false;
    $('btn-calibrate').disabled = false;
    $('status-text').textContent = 'Calibrate away from phones, then sweep exam area';
  }

  function stopScan() {
    running = false;
    if (sensor && typeof sensor.stop === 'function') sensor.stop();
    sensor = null;
    $('btn-start').disabled = false;
    $('btn-stop').disabled = true;
    $('gauge-ring').classList.remove('alert');
    $('alert-banner').classList.remove('visible');
  }

  function showError(msg) {
    const box = $('error-box');
    box.textContent = msg;
    box.style.display = 'block';
  }

  function calibrate() {
    if (!running) return;
    calibrating = true;
    baselineSamples = [];
    $('status-text').textContent = 'Calibrating… hold still, away from electronics (2 sec)';
    $('btn-calibrate').disabled = true;
    setTimeout(() => {
      if (baselineSamples.length < 5) {
        $('status-text').textContent = 'Not enough samples — try again';
        calibrating = false;
        $('btn-calibrate').disabled = false;
        return;
      }
      baseline = baselineSamples.reduce((a, b) => a + b, 0) / baselineSamples.length;
      calibrating = false;
      $('btn-calibrate').disabled = false;
      $('status-text').textContent = 'Baseline set — sweep slowly near desks and pockets';
      updateUI();
    }, 2000);
  }

  async function loadSession() {
    try {
      const res = await fetch(API, fetchOpts);
      const data = await res.json();
      sessionActive = !!(data.session && data.session.status === 'active');
      $('session-label').textContent = sessionActive
        ? `Exam: ${data.session.exam_name}`
        : 'No active session — start exam on dashboard first';
    } catch (_) {
      $('session-label').textContent = 'Could not load session';
    }
  }

  $('btn-start')?.addEventListener('click', () => {
    ensureAudio();
    startScan();
  });
  $('btn-stop')?.addEventListener('click', stopScan);
  $('btn-calibrate')?.addEventListener('click', calibrate);
  $('threshold')?.addEventListener('input', updateUI);

  loadSession();
})();
