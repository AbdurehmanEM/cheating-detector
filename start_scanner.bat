@echo off
cd /d "%~dp0scanner"
title Exam Phone Detection - Scanner
echo ============================================
echo  EXAM PHONE DETECTION - SCAN WORKER
echo  Bluetooth + Wi-Fi MAC phone scan every 20s
echo ============================================
echo.

python -m pip install -r requirements.txt
if errorlevel 1 (
    echo Failed to install Python packages.
    pause
    exit /b 1
)

echo Checking Bluetooth...
python -c "import sys; sys.path.insert(0,'.'); from bluetooth_scanner import bluetooth_ready; ok,m=bluetooth_ready(); print('Bluetooth:', 'OK' if ok else m); sys.exit(0 if ok else 1)"
if errorlevel 1 (
    echo.
    echo WARNING: Bluetooth may not work. Enable Bluetooth in Windows Settings.
    echo.
)

echo.
echo Starting scanner... Keep this window open.
echo Dashboard: start exam session first, then open index.php
echo.
python scan_worker.py
pause
