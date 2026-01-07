@echo off
echo ====================================
echo VATUSA Event AAR/ADR Entry Server
echo ====================================
echo.

REM Check for Python
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python not found. Please install Python 3.8+
    pause
    exit /b 1
)

REM Check for Flask
python -c "import flask" >nul 2>&1
if errorlevel 1 (
    echo Installing Flask...
    pip install flask
)

REM Check for pyodbc
python -c "import pyodbc" >nul 2>&1
if errorlevel 1 (
    echo Installing pyodbc...
    pip install pyodbc
)

echo.
echo Starting server...
echo Visit: http://localhost:5000
echo Press Ctrl+C to stop
echo.

python "%~dp0app.py"
