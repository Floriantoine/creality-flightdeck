@echo off
cd /d "%~dp0"
echo K1 Explore (lecture seule) - environ 1 minute...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0k1_explore.ps1"
echo.
echo Fini. Tu peux fermer cette fenetre.
pause
