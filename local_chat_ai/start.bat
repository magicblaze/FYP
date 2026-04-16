@echo off
setlocal EnableExtensions EnableDelayedExpansion

cd /d "%~dp0"
set "HOST=127.0.0.1"
set "PORT=8010"
set "PYEXE=.venv\Scripts\python.exe"

if not exist "%PYEXE%" (
    echo [setup] Virtual environment not found. Creating .venv...
    py -3 -m venv .venv
    if errorlevel 1 (
        echo [setup] 'py -3' failed. Trying 'python -m venv .venv'...
        python -m venv .venv
        if errorlevel 1 (
            echo [error] Could not create virtual environment.
            echo [hint] Install Python 3 and try again.
            pause
            exit /b 1
        )
    )
)

if not exist "requirements.txt" (
    echo [error] requirements.txt not found.
    pause
    exit /b 1
)

set "INSTALLED_ANY=0"
for /f "usebackq tokens=* delims=" %%R in ("requirements.txt") do (
    set "REQ=%%R"
    if not "!REQ!"=="" if /i not "!REQ:~0,1!"=="#" (
        set "PKG=!REQ!"
        for /f "tokens=1 delims=<>=!~[" %%P in ("!REQ!") do set "PKG=%%P"

        "%PYEXE%" -m pip show "!PKG!" >nul 2>&1
        if errorlevel 1 (
            echo [setup] Installing !REQ!...
            "%PYEXE%" -m pip install "!REQ!"
            if errorlevel 1 (
                echo [error] Failed to install !REQ!.
                pause
                exit /b 1
            )
            set "INSTALLED_ANY=1"
        )
    )
)

if "%INSTALLED_ANY%"=="0" (
    echo [setup] Required modules are already installed.
)

echo [run] Starting Local Chat Assistant on http://%HOST%:%PORT%
"%PYEXE%" -m uvicorn app:app --host %HOST% --port %PORT% --reload

set "EXIT_CODE=%ERRORLEVEL%"
if not "%EXIT_CODE%"=="0" (
    echo [info] Server stopped with exit code %EXIT_CODE%.
    pause
)

exit /b %EXIT_CODE%
