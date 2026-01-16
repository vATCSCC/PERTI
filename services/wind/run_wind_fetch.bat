@echo off
REM ============================================================================
REM run_wind_fetch.bat - Wind Data Fetcher for Task Scheduler
REM
REM Fetches NOAA GFS wind data and loads into wind_grid table.
REM Schedule this script to run every 6 hours via Windows Task Scheduler.
REM
REM Setup:
REM   1. Edit this file to set WIND_DB_PASSWORD below
REM   2. Create Task Scheduler task:
REM      - Program: C:\path\to\services\wind\run_wind_fetch.bat
REM      - Triggers: Daily, repeat every 6 hours
REM      - Run whether user is logged on or not
REM
REM Requirements:
REM   - Python 3.8+ with conda environment
REM   - Packages: xarray cfgrib eccodes requests pyodbc
REM ============================================================================

REM Set working directory to script location
cd /d "%~dp0"

REM ============================================================================
REM CONFIGURATION - Edit these values
REM ============================================================================

REM Database password (REQUIRED - set this before running)
set WIND_DB_PASSWORD=CAMRN@11000

REM Optional: Override defaults if needed
REM set WIND_DB_SERVER=tcp:vatsim.database.windows.net,1433
REM set WIND_DB_NAME=VATSIM_ADL
REM set WIND_DB_USER=adl_api_user

REM Conda environment name (if using conda)
set CONDA_ENV=wind

REM Log file location
set LOG_DIR=%~dp0logs
set LOG_FILE=%LOG_DIR%\wind_fetch_%date:~-4,4%%date:~-10,2%%date:~-7,2%.log

REM ============================================================================
REM EXECUTION
REM ============================================================================

REM Create log directory if it doesn't exist
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

REM Log start time
echo ============================================== >> "%LOG_FILE%"
echo Wind Fetch Started: %date% %time% >> "%LOG_FILE%"
echo ============================================== >> "%LOG_FILE%"

REM Activate conda environment (adjust path as needed)
REM Typical locations:
REM   - C:\ProgramData\Anaconda3\Scripts\activate.bat
REM   - C:\Users\%USERNAME%\Anaconda3\Scripts\activate.bat
REM   - C:\Users\%USERNAME%\miniconda3\Scripts\activate.bat

if exist "C:\ProgramData\Anaconda3\Scripts\activate.bat" (
    call "C:\ProgramData\Anaconda3\Scripts\activate.bat" %CONDA_ENV%
) else if exist "%USERPROFILE%\Anaconda3\Scripts\activate.bat" (
    call "%USERPROFILE%\Anaconda3\Scripts\activate.bat" %CONDA_ENV%
) else if exist "%USERPROFILE%\miniconda3\Scripts\activate.bat" (
    call "%USERPROFILE%\miniconda3\Scripts\activate.bat" %CONDA_ENV%
) else (
    echo WARNING: Conda not found, using system Python >> "%LOG_FILE%"
)

REM Run the wind fetcher
REM --all-tiers fetches all configured resolution tiers
REM Use --tier=0,1,2 for just domestic (faster)
python "%~dp0fetch_noaa_gfs.py" --all-tiers >> "%LOG_FILE%" 2>&1

REM Log completion
echo. >> "%LOG_FILE%"
echo Wind Fetch Completed: %date% %time% >> "%LOG_FILE%"
echo Exit Code: %ERRORLEVEL% >> "%LOG_FILE%"
echo. >> "%LOG_FILE%"

REM Keep only last 7 days of logs
forfiles /p "%LOG_DIR%" /s /m *.log /d -7 /c "cmd /c del @path" 2>nul

exit /b %ERRORLEVEL%
