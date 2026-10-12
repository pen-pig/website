@echo off
REM ============================================================
REM  Club website: local server + cpolar tunnel (double-click)
REM  NOTE: keep this file ASCII-only. Non-ASCII bytes (GBK/UTF-8)
REM        can contain ')', '|', '&' and break cmd parsing.
REM ============================================================
setlocal enabledelayedexpansion
title ClubSite - Local Server + Tunnel
cd /d "%~dp0"

set "PORT=8080"
set "ROOT=%~dp0web"
set "PHP_BIN=D:\php\php.exe"
if not exist "%PHP_BIN%" set "PHP_BIN=php"
set "CPOLAR=%~dp0tools\cpolar.exe"

if not exist "%CPOLAR%" (
    echo [ERROR] tools\cpolar.exe not found.
    pause
    exit /b 1
)

where g++ >nul 2>nul
if errorlevel 1 (
    echo [WARN] g++ not found. OJ submissions will go to manual judging.
) else (
    echo [INFO] g++     : OK ^(OJ auto judging available^)
)

echo [INFO] Starting local server on port %PORT% ...
start "ClubSite-Local" /min cmd /c ""%PHP_BIN%" -S 127.0.0.1:%PORT% -t "%ROOT%" "%ROOT%\router.php""

timeout /t 2 /nobreak >nul
curl -s -o /dev/null -m 5 "http://127.0.0.1:%PORT%/" && echo [OK] Local server is up || echo [WARN] Local server not responding, check php.exe path

echo.
echo [INFO] Creating public tunnel (CN node)...
echo [INFO] The https:// URL below is your public entry. Keep this window open.
echo.

"%CPOLAR%" http %PORT% --log=stdout

echo.
echo [INFO] Tunnel closed, site is no longer publicly visible.
pause
endlocal
