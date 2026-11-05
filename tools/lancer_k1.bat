@echo off
cd /d "%~dp0"
echo Installation de websockets...
python -m pip install --quiet websockets
if errorlevel 1 (
  echo.
  echo Python introuvable ou pip a echoue. Installe Python depuis python.org en cochant "Add to PATH".
  pause
  exit /b
)
python k1_monitor.py %*
pause
