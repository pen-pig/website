@echo off
REM ============================================================
REM  Club website local launcher (double-click to run)
REM  1) locate php.exe (default D:\php\php.exe, fallback: PATH)
REM  2) start built-in server: http://127.0.0.1:8080
REM  3) check g++ for OJ auto-judging (manual judging if missing)
REM  NOTE: keep this file ASCII-only. Non-ASCII bytes (GBK/UTF-8)
REM        can contain ')', '|', '&' and break cmd parsing.
REM ============================================================
setlocal

set "PORT=8080"
set "ROOT=%~dp0web"
set "PHP_BIN=D:\php\php.exe"

if not exist "%PHP_BIN%" (
    where php >nul 2>nul
    if errorlevel 1 (
        echo [ERROR] php.exe not found.
        echo         Edit PHP_BIN in this script to point to your php.exe,
        echo         or add PHP's directory to system PATH.
        pause
        exit /b 1
    ) else (
        set "PHP_BIN=php"
    )
)

echo [INFO] PHP      : %PHP_BIN%
"%PHP_BIN%" -v | findstr /b "PHP"

where g++ >nul 2>nul
if errorlevel 1 (
    echo [WARN] g++ not found. OJ submissions will go to manual judging.
    echo        Install Dev-C++ / MinGW and add its bin dir to PATH to enable auto judging.
) else (
    echo [INFO] g++     : OK ^(OJ auto judging available^)
)

echo.
echo [INFO] Root     : %ROOT%
echo [INFO] URL      : http://127.0.0.1:%PORT%/
echo [INFO] Press Ctrl+C to stop the server
echo.

start "" http://127.0.0.1:%PORT%/
"%PHP_BIN%" -S 127.0.0.1:%PORT% -t "%ROOT%" "%ROOT%\router.php"

endlocal
