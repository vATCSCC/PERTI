@echo off
REM VATUSA Event Statistics - Daily Update Script
REM Schedule this script with Windows Task Scheduler to run daily at 02:30 UTC
REM
REM Task Scheduler Setup:
REM   1. Open Task Scheduler
REM   2. Create Task -> Name: "VATUSA Event Stats Update"
REM   3. Triggers -> New -> Daily at 02:30 (UTC time)
REM   4. Actions -> New -> Start a Program
REM      Program: This batch file path
REM      Start in: The scripts\statsim folder
REM   5. Conditions -> Uncheck "Start only if on AC power" if needed
REM   6. Settings -> Check "Run task as soon as possible after scheduled start is missed"

cd /d "%~dp0"

echo ========================================
echo VATUSA Event Statistics Daily Update
echo %date% %time%
echo ========================================

python daily_event_update.py --days-back 3

if %errorlevel% neq 0 (
    echo ERROR: Update failed with code %errorlevel%
    exit /b %errorlevel%
)

echo ========================================
echo Update completed successfully
echo ========================================
