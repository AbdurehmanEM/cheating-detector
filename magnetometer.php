<?php require_once __DIR__ . '/auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1a1030">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Magnetometer Scan — Exam Phone Detection</title>
    <link rel="stylesheet" href="assets/css/magnetometer.css">
</head>
<body>
    <header class="mag-header">
        <div>
            <h1>Magnetometer scan</h1>
            <p class="sub">Uses your phone's magnetometer to find electronics, including mobile phones</p>
        </div>
        <a class="mag-link" href="index.php">Dashboard</a>
    </header>

    <div id="alert-banner" class="mag-alert-banner">ELECTRONICS DETECTED — CHECK AREA</div>

    <main class="mag-main">
        <p id="session-label" style="text-align:center;margin-bottom:0.5rem;color:var(--muted);font-size:0.85rem">Loading session…</p>
        <div id="error-box" class="mag-error" style="display:none"></div>

        <div class="mag-gauge-wrap">
            <div id="gauge-ring" class="mag-gauge-ring">
                <div class="mag-gauge-inner">
                    <div id="field-value" class="mag-field-value">—</div>
                    <div class="mag-field-unit">µT (microtesla)</div>
                    <div id="delta-value" class="mag-delta">Calibrate first</div>
                    <div id="status-text" class="mag-status-text">Open on a phone or tablet with a magnetometer</div>
                </div>
            </div>
        </div>

        <div class="mag-bar"><div id="bar-fill" class="mag-bar-fill"></div></div>

        <div class="mag-controls">
            <button type="button" class="mag-btn" id="btn-start">Start scan</button>
            <button type="button" class="mag-btn mag-btn-secondary" id="btn-calibrate" disabled>Calibrate</button>
            <button type="button" class="mag-btn mag-btn-secondary" id="btn-stop" disabled>Stop</button>
        </div>

        <div class="mag-panel">
            <h2>Settings</h2>
            <div class="mag-slider-row">
                <label for="threshold">Sensitivity (µT)</label>
                <input type="range" id="threshold" min="8" max="45" value="18" step="1">
                <span id="threshold-val">18</span>
            </div>
            <div class="mag-slider-row">
                <label for="sensitivity">Confirm hits</label>
                <input type="range" id="sensitivity" min="2" max="8" value="3" step="1">
                <span id="sensitivity-val">3</span>
            </div>
            <p style="margin-top:0.5rem">Baseline: <strong id="baseline-value">—</strong> µT · Mode: <span id="sensor-mode">—</span></p>
            <p>Hits this session: <strong id="hit-count">0</strong></p>
        </div>

        <div class="mag-panel">
            <h2>How it works</h2>
            <ul>
                <li>Phones and electronics contain speakers, motors, and steel that disturb Earth's magnetic field.</li>
                <li>Calibrate in a clear area, then sweep slowly over desks, bags, and pockets.</li>
                <li>Spikes on the gauge suggest hidden <strong>mobile phones or electronics</strong>.</li>
                <li>Detections are logged to the exam timeline when a session is active.</li>
            </ul>
        </div>

        <div class="mag-panel">
            <h2>Recent hits</h2>
            <ul id="hits-list" class="mag-hits"></ul>
        </div>
    </main>

    <script>
    document.getElementById('threshold')?.addEventListener('input', function () {
      document.getElementById('threshold-val').textContent = this.value;
    });
    document.getElementById('sensitivity')?.addEventListener('input', function () {
      document.getElementById('sensitivity-val').textContent = this.value;
    });
    </script>
    <script src="assets/js/magnetometer.js"></script>
</body>
</html>
