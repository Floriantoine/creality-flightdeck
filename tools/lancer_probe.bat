@echo off
cd /d "%~dp0"
echo K1 Probe (lecture seule) - 20 secondes...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0k1_probe.ps1"
echo.
echo Fini. Tu peux fermer cette fenetre.
pause
