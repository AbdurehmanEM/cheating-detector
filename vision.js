(function () {
  const API = 'api/vision.php';
  const PHONE_LABELS = new Set(['cell phone', 'mobile phone', 'phone']);
  const fetchOpts = { credentials: 'include', headers: { 'Content-Type': 'application/json' } };

  const $ = (id) => document.getElementById(id);

  let model = null;
  let stream = null;
  let detecting = false;
  let loopTimer = null;
  let audioCtx = null;
  let sessionActive = false;
  let hitCount = 0;
  let lastAlertAt = 0;
  let lastLogAt = 0;
  let lastPhoneSeenAt = 0;

  function getConfidenceMin() {
    return parseInt($('confidence')?.value || '55', 10) / 100;
  }

  function getScanMs() {
    return parseInt($('scan-speed')?.value || '200', 10);
  }

  function ensureAudio() {
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') return audioCtx.resume();
    } catch (_) {}
    return Promise.resolve();
  }

  function playPhoneAlarm() {
    ensureAudio().then(() => {
      if (!audioCtx) return;
      [880, 1100, 1400, 1100].forEach((f, i) => {
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.frequency.value = f;
        gain.gain.value = 0.28;
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        const t = audioCtx.currentTime + i * 0.12;
        osc.start(t);
        osc.stop(t + 0.14);
      });
    });
  }

  function isPhoneClass(className) {
    const c = String(className || '').toLowerCase();
    if (PHONE_LABELS.has(c)) return true;
    return c.includes('phone') && !c.includes('microphone');
  }

  function setStatus(msg, type) {
    const el = $('overlay-status');
    if (!el) return;
    el.textContent = msg;
    el.className = 'vision-overlay-label' + (type ? ' ' + type : '');
  }

  function showError(msg) {
    const box = $('error-box');
    if (!box) return;
    box.textContent = msg;
    box.style.display = 'block';
  }

  async function loadModel() {
    if (model) return model;
    $('model-loading').style.display = 'flex';
    setStatus('Loading AI model…', '');
    if (typeof cocoSsd === 'undefined') {
      throw new Error('COCO-SSD model failed to load. Check internet connection for first-time download.');
    }
    if (typeof tf !== 'undefined') {
      await tf.ready();
      try {
        await tf.setBackend('webgl');
      } catch (_) {}
    }
    model = await cocoSsd.load({ base: 'lite_mobilenet_v2' });
    $('model-loading').style.display = 'none';
    setStatus('AI model ready — start camera', '');
    return model;
  }

  async function startCamera() {
    $('error-box').style.display = 'none';
    if (!navigator.mediaDevices?.getUserMedia) {
      showError('Camera API not available. Use Chrome or Edge on this PC.');
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: 'user',
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      });
    } catch (e) {
      showError(
        'Camera blocked or unavailable: ' +
          (e.message || e.name) +
          '. Allow camera access in browser settings and reload.'
      );
      return;
    }

    const video = $('vision-video');
    video.srcObject = stream;
    await video.play();

    $('btn-camera').disabled = true;
    $('btn-stop-camera').disabled = false;
    $('btn-start-ai').disabled = false;
    setStatus('Camera on — click Start AI detection', '');
  }

  function stopCamera() {
    stopDetection();
    if (stream) {
      stream.getTracks().forEach((t) => t.stop());
      stream = null;
    }
    const video = $('vision-video');
    video.srcObject = null;
    clearCanvas();
    $('btn-camera').disabled = false;
    $('btn-stop-camera').disabled = true;
    $('btn-start-ai').disabled = true;
    $('vision-stage')?.classList.remove('alert');
    $('alert-banner')?.classList.remove('visible');
    setStatus('Camera off', '');
  }

  function clearCanvas() {
    const canvas = $('vision-canvas');
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  function resizeCanvas() {
    const video = $('vision-video');
    const canvas = $('vision-canvas');
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
  }

  function drawPredictions(predictions) {
    const video = $('vision-video');
    const canvas = $('vision-canvas');
    const ctx = canvas.getContext('2d');
    resizeCanvas();
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const phones = predictions.filter(
      (p) => isPhoneClass(p.class) && p.score >= getConfidenceMin()
    );

    predictions.forEach((p) => {
      if (!isPhoneClass(p.class) || p.score < getConfidenceMin()) return;
      const [x, y, w, h] = p.bbox;
      ctx.strokeStyle = '#e74c3c';
      ctx.lineWidth = 3;
      ctx.fillStyle = 'rgba(231, 76, 60, 0.2)';
      ctx.fillRect(x, y, w, h);
      ctx.strokeRect(x, y, w, h);
      ctx.fillStyle = '#e74c3c';
      ctx.font = 'bold 16px Segoe UI, sans-serif';
      const label = `${p.class} ${(p.score * 100).toFixed(0)}%`;
      const tw = ctx.measureText(label).width + 8;
      ctx.fillRect(x, y - 22, tw, 22);
      ctx.fillStyle = '#fff';
      ctx.fillText(label, x + 4, y - 6);
    });

    return phones;
  }

  function addHitLog(score, count) {
    const ul = $('hits-list');
    if (!ul) return;
    const li = document.createElement('li');
    li.textContent = `${new Date().toLocaleTimeString()} — cell phone ${(score * 100).toFixed(0)}% (${count} in frame)`;
    ul.prepend(li);
    while (ul.children.length > 25) ul.removeChild(ul.lastChild);
  }

  async function logDetection(score, count) {
    if (!sessionActive || Date.now() - lastLogAt < 5000) return;
    lastLogAt = Date.now();
    try {
      await fetch(API, {
        ...fetchOpts,
        method: 'POST',
        body: JSON.stringify({
          action: 'detect',
          label: 'cell phone',
          score,
          count,
        }),
      });
    } catch (_) {}
  }

  function onPhonesDetected(phones) {
    const now = Date.now();
    lastPhoneSeenAt = now;
    $('vision-stage')?.classList.add('alert');
    $('alert-banner')?.classList.add('visible');
    setStatus(`PHONE DETECTED (${phones.length})`, 'phone');

    const best = phones.reduce((a, b) => (a.score > b.score ? a : b), phones[0]);

    if (now - lastAlertAt > 2500) {
      lastAlertAt = now;
      hitCount += 1;
      $('hit-count').textContent = String(hitCount);
      playPhoneAlarm();
      addHitLog(best.score, phones.length);
      logDetection(best.score, phones.length);
    }
  }

  async function detectOnce() {
    const video = $('vision-video');
    if (!model || !video.videoWidth || video.readyState < 2) return;

    try {
      const predictions = await model.detect(video);
      const phones = drawPredictions(predictions);

      if (phones.length > 0) {
        onPhonesDetected(phones);
      } else if (Date.now() - lastPhoneSeenAt > 1500) {
        $('vision-stage')?.classList.remove('alert');
        $('alert-banner')?.classList.remove('visible');
        if (detecting) setStatus('Scanning exam area…', 'scanning');
      }
    } catch (e) {
      console.error(e);
    }
  }

  function detectionLoop() {
    if (!detecting) return;
    detectOnce().finally(() => {
      loopTimer = setTimeout(detectionLoop, getScanMs());
    });
  }

  async function startDetection() {
    ensureAudio();
    try {
      await loadModel();
    } catch (e) {
      showError(e.message || String(e));
      return;
    }
    const video = $('vision-video');
    if (!stream || !video.srcObject) {
      showError('Start the camera first.');
      return;
    }
    detecting = true;
    $('btn-start-ai').disabled = true;
    $('btn-stop-ai').disabled = false;
    setStatus('AI scanning for mobile phones…', 'scanning');
    detectionLoop();
  }

  function stopDetection() {
    detecting = false;
    if (loopTimer) {
      clearTimeout(loopTimer);
      loopTimer = null;
    }
    $('btn-start-ai').disabled = !stream;
    $('btn-stop-ai').disabled = true;
    clearCanvas();
    if (stream) setStatus('Camera on — AI paused', '');
  }

  async function loadSession() {
    try {
      const res = await fetch(API, fetchOpts);
      const data = await res.json();
      sessionActive = !!(data.session && data.session.status === 'active');
      $('session-label').textContent = sessionActive
        ? `Exam: ${data.session.exam_name} — detections will be logged`
        : 'No active session — start exam on dashboard to log detections';
    } catch (_) {
      $('session-label').textContent = 'Could not load session status';
    }
  }

  $('btn-camera')?.addEventListener('click', startCamera);
  $('btn-stop-camera')?.addEventListener('click', stopCamera);
  $('btn-start-ai')?.addEventListener('click', startDetection);
  $('btn-stop-ai')?.addEventListener('click', stopDetection);

  $('confidence')?.addEventListener('input', function () {
    $('confidence-val').textContent = this.value + '%';
  });
  $('scan-speed')?.addEventListener('input', function () {
    const ms = this.value;
    $('scan-speed-val').textContent = ms < 150 ? 'Fast' : ms < 280 ? 'Normal' : 'Slow';
  });

  loadSession();
  loadModel().catch((e) => {
    $('model-loading').style.display = 'none';
    showError(e.message || 'Failed to load AI model');
  });
})();
