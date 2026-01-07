@echo off
REM VATSIM ATIS Daemon Launcher
REM Run from PERTI root directory

cd /d "%~dp0scripts"
python -m vatsim_atis.atis_daemon %*
