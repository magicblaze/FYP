@echo off
setlocal

cd /d "%~dp0"
set "HOST=127.0.0.1"
set "PORT=8010"

if not exist ".venv\Scripts\python.exe" (
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

    echo [setup] Installing dependencies...
    ".venv\Scripts\python.exe" -m pip install -r requirements.txt
    if errorlevel 1 (
        echo [error] Failed to install requirements.
        pause
        exit /b 1
    )
)

echo [run] Starting Local Chat Assistant on http://%HOST%:%PORT%
".venv\Scripts\python.exe" -m uvicorn app:app --host %HOST% --port %PORT% --reload

set "EXIT_CODE=%ERRORLEVEL%"
if not "%EXIT_CODE%"=="0" (
    echo [info] Server stopped with exit code %EXIT_CODE%.
    pause
)

exit /b %EXIT_CODE%
