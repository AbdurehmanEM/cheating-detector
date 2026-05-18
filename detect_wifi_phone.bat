@echo off
cd /d "%~dp0scanner"
title Exam Phone Detection - Wi-Fi MAC Scan
echo ============================================
echo  WI-FI PHONE SCAN (MAC address detection)
echo ============================================
echo.
python run_wifi_scan_once.py
pause
