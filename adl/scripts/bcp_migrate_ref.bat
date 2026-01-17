@echo off
REM ============================================================================
REM BCP Migration Script: VATSIM_ADL -> VATSIM_REF
REM Fastest bulk copy for Azure SQL
REM ============================================================================

set SERVER=vatsim.database.windows.net
set ADL_DB=VATSIM_ADL
set REF_DB=VATSIM_REF
set USER=jpeterson
set PASS=Jhp21012
set DATADIR=%~dp0data

REM Create data directory if it doesn't exist
if not exist "%DATADIR%" mkdir "%DATADIR%"

echo ============================================================================
echo VATSIM_REF BCP Migration
echo Started: %date% %time%
echo ============================================================================
echo.

REM ============================================================================
REM Tables WITHOUT geography columns (simple BCP)
REM ============================================================================

echo --- nav_procedures ---
echo Exporting from ADL...
bcp "SELECT procedure_id, procedure_type, airport_icao, procedure_name, computer_code, transition_name, full_route, runways, is_active, source, effective_date FROM VATSIM_ADL.dbo.nav_procedures" queryout "%DATADIR%\nav_procedures.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Importing to REF...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.nav_procedures ON; TRUNCATE TABLE dbo.nav_procedures;"
bcp VATSIM_REF.dbo.nav_procedures in "%DATADIR%\nav_procedures.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -E -b 5000
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.nav_procedures OFF;"
echo.

echo --- coded_departure_routes ---
echo Exporting from ADL...
bcp "SELECT cdr_id, cdr_code, full_route, origin_icao, dest_icao, direction, altitude_min_ft, altitude_max_ft, is_active, source, effective_date FROM VATSIM_ADL.dbo.coded_departure_routes" queryout "%DATADIR%\coded_departure_routes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Importing to REF...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.coded_departure_routes ON; TRUNCATE TABLE dbo.coded_departure_routes;"
bcp VATSIM_REF.dbo.coded_departure_routes in "%DATADIR%\coded_departure_routes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -E -b 5000
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.coded_departure_routes OFF;"
echo.

echo --- playbook_routes ---
echo Exporting from ADL...
bcp "SELECT playbook_id, play_name, full_route, origin_airports, origin_tracons, origin_artccs, dest_airports, dest_tracons, dest_artccs, altitude_min_ft, altitude_max_ft, is_active, source, effective_date FROM VATSIM_ADL.dbo.playbook_routes" queryout "%DATADIR%\playbook_routes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Importing to REF...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.playbook_routes ON; TRUNCATE TABLE dbo.playbook_routes;"
bcp VATSIM_REF.dbo.playbook_routes in "%DATADIR%\playbook_routes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -E -b 5000
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.playbook_routes OFF;"
echo.

REM ============================================================================
REM Tables WITH geography columns (export as WKT, use staging)
REM ============================================================================

echo --- nav_fixes (with GEOGRAPHY) ---
echo Exporting from ADL (converting geography to WKT)...
bcp "SELECT fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code, country_code, freq_mhz, mag_var, elevation_ft, source, effective_date, position_geo.STAsText() FROM VATSIM_ADL.dbo.nav_fixes" queryout "%DATADIR%\nav_fixes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Creating staging table and importing...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "IF OBJECT_ID('dbo.nav_fixes_staging', 'U') IS NOT NULL DROP TABLE dbo.nav_fixes_staging; CREATE TABLE dbo.nav_fixes_staging (fix_id INT, fix_name NVARCHAR(10), fix_type NVARCHAR(16), lat DECIMAL(10,6), lon DECIMAL(11,6), artcc_id NVARCHAR(8), state_code NVARCHAR(4), country_code NVARCHAR(4), freq_mhz DECIMAL(7,3), mag_var DECIMAL(6,2), elevation_ft INT, source NVARCHAR(32), effective_date DATE, position_wkt NVARCHAR(MAX));"
bcp VATSIM_REF.dbo.nav_fixes_staging in "%DATADIR%\nav_fixes.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -b 10000
echo Converting WKT to GEOGRAPHY and inserting...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.nav_fixes ON; TRUNCATE TABLE dbo.nav_fixes; INSERT INTO dbo.nav_fixes (fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code, country_code, freq_mhz, mag_var, elevation_ft, source, effective_date, position_geo) SELECT fix_id, fix_name, fix_type, lat, lon, artcc_id, state_code, country_code, freq_mhz, mag_var, elevation_ft, source, effective_date, CASE WHEN position_wkt IS NOT NULL THEN geography::STGeomFromText(position_wkt, 4326) ELSE NULL END FROM dbo.nav_fixes_staging; SET IDENTITY_INSERT dbo.nav_fixes OFF; DROP TABLE dbo.nav_fixes_staging;"
echo.

echo --- area_centers (with GEOGRAPHY) ---
echo Exporting from ADL (converting geography to WKT)...
bcp "SELECT center_id, center_code, center_type, center_name, lat, lon, parent_artcc, position_geo.STAsText() FROM VATSIM_ADL.dbo.area_centers" queryout "%DATADIR%\area_centers.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Creating staging table and importing...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "IF OBJECT_ID('dbo.area_centers_staging', 'U') IS NOT NULL DROP TABLE dbo.area_centers_staging; CREATE TABLE dbo.area_centers_staging (center_id INT, center_code NVARCHAR(8), center_type NVARCHAR(16), center_name NVARCHAR(64), lat DECIMAL(10,6), lon DECIMAL(11,6), parent_artcc NVARCHAR(8), position_wkt NVARCHAR(MAX));"
bcp VATSIM_REF.dbo.area_centers_staging in "%DATADIR%\area_centers.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -b 1000
echo Converting WKT to GEOGRAPHY and inserting...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.area_centers ON; TRUNCATE TABLE dbo.area_centers; INSERT INTO dbo.area_centers (center_id, center_code, center_type, center_name, lat, lon, parent_artcc, position_geo) SELECT center_id, center_code, center_type, center_name, lat, lon, parent_artcc, CASE WHEN position_wkt IS NOT NULL THEN geography::STGeomFromText(position_wkt, 4326) ELSE NULL END FROM dbo.area_centers_staging; SET IDENTITY_INSERT dbo.area_centers OFF; DROP TABLE dbo.area_centers_staging;"
echo.

echo --- airway_segments (with GEOGRAPHY) ---
echo Checking if source has data...
sqlcmd -S %SERVER% -d %ADL_DB% -U %USER% -P %PASS% -Q "SELECT COUNT(*) AS cnt FROM dbo.airway_segments" -h -1
echo Exporting from ADL (converting geography to WKT)...
bcp "SELECT segment_id, airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg, min_alt_ft, max_alt_ft, segment_geo.STAsText() FROM VATSIM_ADL.dbo.airway_segments" queryout "%DATADIR%\airway_segments.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001
echo Creating staging table and importing...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "IF OBJECT_ID('dbo.airway_segments_staging', 'U') IS NOT NULL DROP TABLE dbo.airway_segments_staging; CREATE TABLE dbo.airway_segments_staging (segment_id INT, airway_id INT, airway_name NVARCHAR(8), sequence_num INT, from_fix NVARCHAR(10), to_fix NVARCHAR(10), from_lat DECIMAL(10,6), from_lon DECIMAL(11,6), to_lat DECIMAL(10,6), to_lon DECIMAL(11,6), distance_nm DECIMAL(8,2), course_deg DECIMAL(5,1), min_alt_ft INT, max_alt_ft INT, segment_wkt NVARCHAR(MAX));"
bcp VATSIM_REF.dbo.airway_segments_staging in "%DATADIR%\airway_segments.dat" -S %SERVER% -U %USER% -P %PASS% -c -t "||" -r "\n" -C 65001 -b 5000
echo Converting WKT to GEOGRAPHY and inserting...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SET IDENTITY_INSERT dbo.airway_segments ON; TRUNCATE TABLE dbo.airway_segments; INSERT INTO dbo.airway_segments (segment_id, airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg, min_alt_ft, max_alt_ft, segment_geo) SELECT segment_id, airway_id, airway_name, sequence_num, from_fix, to_fix, from_lat, from_lon, to_lat, to_lon, distance_nm, course_deg, min_alt_ft, max_alt_ft, CASE WHEN segment_wkt IS NOT NULL THEN geography::STGeomFromText(segment_wkt, 4326) ELSE NULL END FROM dbo.airway_segments_staging; SET IDENTITY_INSERT dbo.airway_segments OFF; DROP TABLE dbo.airway_segments_staging;"
echo.

echo ============================================================================
echo Migration Complete!
echo Finished: %date% %time%
echo ============================================================================

REM Verify row counts
echo.
echo Verifying row counts...
sqlcmd -S %SERVER% -d %REF_DB% -U %USER% -P %PASS% -Q "SELECT 'nav_fixes' AS tbl, COUNT(*) AS rows FROM dbo.nav_fixes UNION ALL SELECT 'airways', COUNT(*) FROM dbo.airways UNION ALL SELECT 'airway_segments', COUNT(*) FROM dbo.airway_segments UNION ALL SELECT 'nav_procedures', COUNT(*) FROM dbo.nav_procedures UNION ALL SELECT 'coded_departure_routes', COUNT(*) FROM dbo.coded_departure_routes UNION ALL SELECT 'playbook_routes', COUNT(*) FROM dbo.playbook_routes UNION ALL SELECT 'area_centers', COUNT(*) FROM dbo.area_centers UNION ALL SELECT 'oceanic_fir_bounds', COUNT(*) FROM dbo.oceanic_fir_bounds;"

pause
