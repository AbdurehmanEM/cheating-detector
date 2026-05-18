@echo off
cd /d "%~dp0scanner"
python -m pip install bleak -q
python run_bt_scan_once.py
pause
