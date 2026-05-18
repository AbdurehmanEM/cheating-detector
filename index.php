<?php require_once __DIR__ . '/auth_check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Phone Detection — Zero Exemption</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <header class="header">
        <div>
            <h1>Exam Phone Detection System <span class="badge">ZERO EXEMPTION</span></h1>
            <p style="font-size:0.8rem;color:var(--muted);margin-top:0.25rem">Every device flagged · No whitelist · Read-only monitoring</p>
        </div>
        <div class="exam-meta" id="exam-meta">
            <span>Exam: <strong id="exam-name">—</strong></span>
            <span>Date: <strong id="exam-date">—</strong></span>
            <span>Started: <strong id="exam-start">—</strong></span>
            <span>Elapsed: <strong id="exam-elapsed">00:00:00</strong></span>
            <span>Chief: <strong id="chief-name">—</strong></span>
            <span>Logged in: <strong id="user-display">—</strong> (<span id="user-role">—</span>)</span>
        </div>
        <button class="btn btn-secondary btn-sm" id="btn-logout" type="button">Logout</button>
    </header>

    <div id="escalation-banner" class="escalation-banner"></div>
    <div id="bt-alarm-banner" class="escalation-banner disciplinary" style="display:none"></div>
    <div id="wifi-alarm-banner" class="escalation-banner disciplinary" style="display:none"></div>

    <div id="session-setup" class="session-setup">
        <h2>Start Exam Session</h2>
        <p style="color:var(--muted);margin-bottom:1rem;max-width:480px;margin-left:auto;margin-right:auto">
            All devices will be flagged on discovery. Only the chief invigilator can clear devices manually.
        </p>
        <div>
            <input type="text" id="input-exam-name" placeholder="Exam name" value="Final Examination">
            <input type="date" id="input-exam-date">
            <input type="text" id="input-chief" placeholder="Chief invigilator name" value="Chief Invigilator">
        </div>
        <button class="btn" id="btn-start-session" style="margin-top:1rem">Start Session &amp; Begin Monitoring</button>
        <p id="chief-only-hint" style="display:none;color:var(--critical);font-size:0.85rem;margin-top:0.75rem">Chief login required to start sessions.</p>
        <div class="scanner-launch-hint">
            <strong>Start the scanner (on this PC):</strong>
            <p>Double-click <strong>RUN SCANNER.bat</strong> or <strong>start_scanner.bat</strong> in File Explorer:</p>
            <code class="scanner-path"><?= htmlspecialchars(__DIR__ . DIRECTORY_SEPARATOR . 'start_scanner.bat', ENT_QUOTES, 'UTF-8') ?></code>
            <p style="margin:0.35rem 0">or</p>
            <code class="scanner-path"><?= htmlspecialchars(__DIR__ . DIRECTORY_SEPARATOR . 'scanner' . DIRECTORY_SEPARATOR . 'start_scanner.bat', ENT_QUOTES, 'UTF-8') ?></code>
            <p class="scanner-launch-note">Start the exam session above first, then run the scanner. Keep the black window open.</p>
            <p class="scanner-launch-note" style="margin-top:0.5rem">Wi-Fi MAC test only: <strong>detect_wifi_phone.bat</strong> in this folder</p>
            <p class="scanner-launch-note" style="margin-top:0.5rem">AI Vision (PC camera): <a href="vision.php" style="color:var(--accent)">vision.php</a></p>
            <p class="scanner-launch-note" style="margin-top:0.5rem">Magnetometer (invigilator phone): <a href="magnetometer.php" style="color:var(--accent)">magnetometer.php</a></p>
        </div>
    </div>

    <div id="main-dashboard" class="container" style="display:none">
        <div class="toolbar">
            <a class="btn btn-secondary" href="vision.php" title="PC webcam AI phone detection">AI Vision (camera)</a>
            <a class="btn btn-secondary" href="magnetometer.php" title="Open on invigilator phone">Magnetometer scan</a>
            <button class="btn" id="btn-refresh">Refresh Now</button>
            <button class="btn btn-secondary" id="btn-export-csv">Export CSV</button>
            <button class="btn btn-secondary" id="btn-export-pdf">Export Report (PDF)</button>
            <button class="btn btn-danger" id="btn-close-session">Close Session</button>
            <span class="refresh-indicator">Auto-refresh 10s · Last: <span id="last-refresh">—</span></span>
            <button type="button" class="btn btn-secondary btn-sm" id="btn-enable-audio">Test alarm sound</button>
            <span id="audio-hint" style="font-size:0.75rem;color:var(--muted)">Click page once — louder, faster alarm when phone is very close (strong RSSI)</span>
            <span class="scanner-toolbar-hint" title="Run on the monitoring PC">Scanner: <code><?= htmlspecialchars(basename(__DIR__) . '\start_scanner.bat', ENT_QUOTES, 'UTF-8') ?></code> (project folder)</span>
        </div>

        <div class="stats">
            <div class="stat-card"><div class="num" id="stat-total">0</div><div class="label">Total Devices</div></div>
            <div class="stat-card critical"><div class="num" id="stat-critical">0</div><div class="label">Critical</div></div>
            <div class="stat-card high"><div class="num" id="stat-high">0</div><div class="label">High</div></div>
            <div class="stat-card medium"><div class="num" id="stat-medium">0</div><div class="label">Medium</div></div>
            <div class="stat-card low"><div class="num" id="stat-low">0</div><div class="label">Cleared (Low)</div></div>
        </div>

        <div class="grid-2">
            <div>
                <div class="panel">
                    <div class="panel-header">Live Device List — All Devices Visible</div>
                    <div class="table-wrap">
                        <table class="device-table">
                            <thead>
                                <tr>
                                    <th>ID</th><th>IP</th><th>MAC</th><th>Host</th><th>Vendor</th>
                                    <th>Category</th><th>Alert</th><th>Status</th><th>Band</th><th>RSSI</th><th>Source</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="device-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">Event Timeline</div>
                    <ul class="timeline" id="timeline"></ul>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-header">Zone Risk Heatmap</div>
                    <div class="zone-heatmap" id="zone-heatmap"></div>
                </div>

                <div class="panel">
                    <div class="panel-header">Recent Alerts</div>
                    <ul class="timeline" id="alerts-list"></ul>
                </div>

                <div class="panel">
                    <div class="panel-header">Invigilator Action Log</div>
                    <ul class="actions-list" id="actions-log"></ul>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="clear-modal">
        <div class="modal">
            <h3>Clear Device (Chief Invigilator)</h3>
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:0.5rem">Device remains on dashboard. Reconnect after disconnect will re-flag.</p>
            <label>Invigilator name</label>
            <input type="text" id="clear-invigilator" placeholder="Your name">
            <div style="display:flex;gap:0.5rem">
                <button class="btn" id="btn-clear-submit">Confirm Clear</button>
                <button class="btn btn-secondary" id="btn-clear-cancel">Cancel</button>
            </div>
        </div>
    </div>

    <script src="assets/js/dashboard.js"></script>
</body>
</html>
