@echo off
rem Double-click to publish the local WordPress site to Vercel (see bin\publish.ps1).
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File bin\publish.ps1 -Deploy
if errorlevel 1 (echo. & echo Deploy FAILED - nothing was published. Read the error above.)
echo.
pause
