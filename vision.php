<?php require_once __DIR__ . '/auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Vision Detection — Exam Phone Detection</title>
    <link rel="stylesheet" href="assets/css/vision.css">
</head>
<body>
    <header class="vision-header">
        <div>
            <h1>AI Vision Detection</h1>
            <p class="sub">Uses this PC's webcam and on-device AI to detect mobile phones in the exam hall</p>
        </div>
        <a class="vision-link" href="index.php">Dashboard</a>
    </header>

    <div id="alert-banner" class="vision-banner">MOBILE PHONE DETECTED — INSPECT IMMEDIATELY</div>

    <div class="vision-layout">
        <div>
            <p id="session-label" class="vision-status-line" style="margin-bottom:0.5rem">Loading…</p>
            <div id="error-box" class="vision-error" style="display:none"></div>

            <div id="vision-stage" class="vision-stage">
                <div id="model-loading" class="vision-model-loading">Loading AI vision model…</div>
                <span id="overlay-status" class="vision-overlay-label">Initializing</span>
                <video id="vision-video" playsinline muted autoplay></video>
                <canvas id="vision-canvas"></canvas>
            </div>
        </div>

        <aside class="vision-side">
            <div class="vision-panel">
                <h2>Controls</h2>
                <div class="vision-controls">
                    <button type="button" class="vision-btn" id="btn-camera">Start camera</button>
                    <button type="button" class="vision-btn vision-btn-secondary" id="btn-stop-camera" disabled>Stop camera</button>
                    <button type="button" class="vision-btn" id="btn-start-ai" disabled>Start AI detection</button>
                    <button type="button" class="vision-btn vision-btn-secondary" id="btn-stop-ai" disabled>Stop AI</button>
                </div>
                <div class="vision-slider-row">
                    <label for="confidence">Min confidence</label>
                    <input type="range" id="confidence" min="40" max="95" value="55" step="5">
                    <span id="confidence-val">55%</span>
                </div>
                <div class="vision-slider-row">
                    <label for="scan-speed">Scan speed</label>
                    <input type="range" id="scan-speed" min="100" max="400" value="200" step="50">
                    <span id="scan-speed-val">Normal</span>
                </div>
                <p class="vision-status-line">Detections: <strong id="hit-count">0</strong></p>
            </div>

            <div class="vision-panel">
                <h2>How it works</h2>
                <ul>
                    <li>Point the PC webcam at desks, hands, or bags.</li>
                    <li>AI (COCO-SSD) recognizes <strong>cell phones</strong> in the live video.</li>
                    <li>Red boxes and an alarm appear when a phone is seen.</li>
                    <li>Events are saved to the exam timeline when a session is active.</li>
                </ul>
            </div>

            <div class="vision-panel">
                <h2>Recent detections</h2>
                <ul id="hits-list" class="vision-hits"></ul>
            </div>
        </aside>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.22.0/dist/tf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.2"></script>
    <script src="assets/js/vision.js"></script>
</body>
</html>
